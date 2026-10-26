<?php
// Изолированная проверка подключения Wiro: подпись, тело запроса моделей, разбор задач. Без сети и WordPress.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function esc_url_raw( $url, $protocols = array() ) {
    return in_array( strtolower( (string) parse_url( (string) $url, PHP_URL_SCHEME ) ), $protocols, true ) ? $url : '';
}
$GLOBALS['options'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); return true; }
$GLOBALS['transients'] = array();
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); return true; }
function wp_get_attachment_image_url( $id, $size ) { return '1536x1536' === $size ? 'https://vk.example.ru/wp-content/uploads/face-' . $id . '-1536x1536.jpg' : false; }

class VKT_Store {
    public static array $logs = array();
    public static function log( $method, $context, $status, $code, $message, $ms = 0, $detail = null ) { self::$logs[] = compact( 'method', 'status', 'message', 'detail' ); }
}
// Шифрование подменено обратимой пометкой: проверяем, что в базу не уходит открытый ключ.
class VKT_Tokens {
    public static function seal( $plain ) { return 'sealed:' . strrev( $plain ); }
    public static function unseal( $sealed ) { return str_starts_with( $sealed, 'sealed:' ) ? strrev( substr( $sealed, 7 ) ) : ''; }
}
class VKT_Account {
    public static int $id = 5;
    public static bool $admin = true;
    public static function id() { return self::$id; }
    public static function is_admin() { return self::$admin; }
}
class VKT_Media {
    public static array $files = array();
    public static array $sideloaded = array();
    public static bool $owned = true;
    public static function reference_path( $id ) { return self::$files[ $id ] ?? new WP_Error( 'vkt_media', 'Локальный файл не найден.', array( 'status' => 404 ) ); }
    public static function owned( $id ) { return self::$owned; }
    public static function sideload( $url, $kind ) {
        self::$sideloaded[] = $url;
        return array( 'id' => 91, 'url' => $url, 'type' => 'image' );
    }
}

$GLOBALS['sent'] = array();
$GLOBALS['replies'] = array();
// Поля multipart-тела: имя → значение, файлы — списком под своим полем.
function form_fields( $body ) {
    $fields = array();
    preg_match_all( '/name="([^"]+)"(?:; filename="([^"]*)")?(?:\r\nContent-Type: [^\r]+)?\r\n\r\n(.*?)\r\n--vkt/s', $body, $parts, PREG_SET_ORDER );
    foreach ( $parts as $part ) {
        if ( '' !== $part[2] ) { $fields[ $part[1] ][] = array( 'file' => $part[2], 'bytes' => $part[3] ); } else { $fields[ $part[1] ] = $part[3]; }
    }
    return $fields;
}
function wp_remote_request( $url, $args ) {
    $multipart = str_starts_with( (string) ( $args['headers']['Content-Type'] ?? '' ), 'multipart/form-data' );
    $GLOBALS['sent'][] = array( 'url' => $url, 'args' => $args, 'multipart' => $multipart, 'payload' => $multipart ? form_fields( $args['body'] ) : json_decode( $args['body'] ?? 'null', true ) );
    return array_shift( $GLOBALS['replies'] ) ?? array( 'code' => 200, 'body' => '{}' );
}
function wp_remote_retrieve_response_code( $response ) { return is_wp_error( $response ) ? 0 : (int) $response['code']; }
function wp_remote_retrieve_body( $response ) { return is_wp_error( $response ) ? '' : (string) $response['body']; }
function reply( $body, $code = 200 ) { return array( 'code' => $code, 'body' => wp_json_encode( $body ) ); }
function queue( ...$replies ) { $GLOBALS['replies'] = $replies; }
function last_sent() { return end( $GLOBALS['sent'] ); }

require dirname( __DIR__ ) . '/includes/class-wiro.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

// ——— Подпись ———
$headers = VKT_Wiro::headers( 'KEY123', 'SECRET456', 1700000000 );
$assert( 'KEY123' === $headers['x-api-key'] && '1700000000' === $headers['x-nonce'], 'Ключ и nonce уходят заголовками' );
$assert( hash_hmac( 'sha256', 'SECRET4561700000000', 'KEY123' ) === $headers['x-signature'], 'Подпись — HMAC-SHA256 от «секрет + nonce» с ключом API' );
$plain = VKT_Wiro::headers( 'KEY123', '' );
$assert( ! isset( $plain['x-signature'], $plain['x-nonce'] ), 'Без секрета запрос идёт в режиме «только ключ»' );

// ——— Сохранение ключа ———
$assert( ! VKT_Wiro::configured(), 'До сохранения Wiro не подключён' );
$assert( is_wp_error( VKT_Wiro::save( array( 'key' => 'коротко' ) ) ) && array() === $GLOBALS['sent'], 'Неполный ключ отклоняется без запроса в сеть' );
queue( reply( array( 'result' => false, 'errors' => array( array( 'message' => 'Unauthorized' ) ) ), 401 ) );
$denied = VKT_Wiro::save( array( 'key' => 'wiro_key_0123456789', 'secret' => 'wiro_secret_0123456789' ) );
$assert( is_wp_error( $denied ) && str_contains( $denied->get_error_message(), 'не принял ключ' ) && array() === $GLOBALS['options'], 'Отвергнутый ключ не сохраняется' );
queue( reply( array( 'result' => false, 'errors' => array( 'Invalid signature' ) ), 403 ) );
$unsigned = VKT_Wiro::save( array( 'key' => 'wiro_key_0123456789' ) );
$assert( is_wp_error( $unsigned ) && str_contains( $unsigned->get_error_message(), 'добавьте секрет' ), '403 без секрета подсказывает добавить секрет' );
queue( reply( array( 'result' => false, 'errors' => array( 'Unauthorized request' ) ) ) );
$assert( is_wp_error( VKT_Wiro::save( array( 'key' => 'wiro_key_0123456789' ) ) ), 'Отказ в доступе с кодом 200 тоже считается отказом' );
queue( reply( array( 'result' => false, 'errors' => array( array( 'message' => 'Task not found' ) ), 'tasklist' => array() ) ) );
$saved = VKT_Wiro::save( array( 'key' => ' wiro_key_0123456789 ', 'secret' => 'wiro_secret_0123456789' ) );
$assert( ! is_wp_error( $saved ) && $saved['probe']['ok'], '«Задачи нет» значит, что ключ и подпись приняты' );
$assert( str_ends_with( last_sent()['url'], '/v1/Task/Detail' ) && isset( last_sent()['args']['headers']['x-signature'] ), 'Проверка — бесплатный Task/Detail с подписью' );
$stored = wp_json_encode( $GLOBALS['options'] );
$assert( ! str_contains( $stored, 'wiro_key_0123456789' ) && ! str_contains( $stored, 'wiro_secret_0123456789' ), 'В базе ключ и секрет только зашифрованными' );
$assert( VKT_Wiro::configured(), 'После сохранения Wiro подключён' );
$status = VKT_Wiro::public_status();
$assert( $status['signed'] && 'wiro_k…6789' === $status['preview'] && ! str_contains( wp_json_encode( $status ), 'wiro_secret' ), 'В интерфейс уходит только огрызок ключа' );
VKT_Account::$admin = false;
$assert( '' === VKT_Wiro::public_status()['preview'] && null === VKT_Wiro::public_status()['probe'], 'Участнику не видно даже огрызка' );
VKT_Account::$admin = true;

// ——— Тело запроса моделей ———
$assert( array( 'prompt' => 'кот', 'resolution' => '2k', 'ratio' => '3:4', 'promptExtend' => 'false' ) === VKT_Wiro::payload( 'z-image-pro', 'кот', 'portrait' ), 'Z Image Pro: поля как в Tool/Detail' );
$assert( '9:16' === VKT_Wiro::payload( 'z-image-pro', 'кот', 'story' )['ratio'] && '3:4' === VKT_Wiro::payload( 'z-image-pro', 'кот', 'нет' )['ratio'], 'Неизвестный формат — вертикаль 3:4' );
$seedream = VKT_Wiro::payload( 'seedream-4-5', 'кот', 'landscape' );
$assert( '16:9' === $seedream['aspectRatio'] && 1 === $seedream['maxImages'] && 'false' === $seedream['watermark'] && ! isset( $seedream['inputImage'] ), 'Seedream: одна картинка, без водяного знака, пропорции заданы явно' );
$assert( 0 === VKT_Wiro::refs( 'z-image-pro' ) && 4 === VKT_Wiro::refs( 'seedream-4-5' ) && 4 === VKT_Wiro::refs( 'seedream-5-lite' ) && 0 === VKT_Wiro::refs( 'flux' ), 'Образцы: Z Image — нет, Seedream 4.5 и 5 Lite — четыре ракурса лица' );
$assert( VKT_Wiro::payload( 'seedream-4-5', 'кот', 'story' ) === VKT_Wiro::payload( 'seedream-5-lite', 'кот', 'story' ), 'Seedream 5 Lite — те же поля, что у 4.5' );
$assert( array( 'prompt' => 'кот', 'resolution' => '2k', 'aspectRatio' => '9:16', 'outputFormat' => 'jpeg', 'watermark' => 'false' ) === VKT_Wiro::payload( 'seedream-5-pro', 'кот', 'story' ) && 8 === VKT_Wiro::refs( 'seedream-5-pro' ), 'Seedream 5 Pro: без maxImages, jpeg, до 8 образцов' );

// ——— Запуск ———
$GLOBALS['sent'] = array();
$assert( is_wp_error( VKT_Wiro::start( 'flux', 'кот на окне', 'portrait' ) ) && is_wp_error( VKT_Wiro::start( 'z-image-pro', 'ой', 'portrait' ) ) && array() === $GLOBALS['sent'], 'Чужая модель и пустой промпт в сеть не уходят' );
$face = __DIR__ . '/wiro-face.jpg';
file_put_contents( $face, 'FACEBYTES' );
$photo = static fn( $id, $type = 'image', $size = 9 ) => array( 'id' => $id, 'type' => $type, 'name' => 'face-' . $id . '.jpg', 'mime' => 'image/jpeg', 'path' => $face, 'size' => $size );
VKT_Media::$files = array( 1 => $photo( 1 ), 2 => $photo( 2 ), 3 => $photo( 3 ), 4 => $photo( 4 ), 5 => $photo( 5 ), 6 => $photo( 6, 'video' ), 7 => $photo( 7, 'image', 11 * 1048576 ) );
queue( reply( array( 'result' => true, 'errors' => array(), 'taskid' => '534574', 'socketaccesstoken' => 'abc' ) ) );
$started = VKT_Wiro::start( 'seedream-4-5', 'Девушка на пляже <b>в купальнике</b>', 'portrait', array( 1, 2, 3, 4, 5 ) );
$sent = last_sent();
$assert( ! is_wp_error( $started ) && '534574' === $started['id'] && 'pending' === $started['status'], 'Задача поставлена и вернула taskid' );
$assert( 'https://api.wiro.ai/v1/Run/bytedance/seedream-v4-5-uncensored' === $sent['url'], 'Запрос ушёл на модель Seedream' );
$assert( 'Девушка на пляже в купальнике' === $sent['payload']['prompt'], 'Промпт очищен от разметки' );
$assert( $sent['multipart'] && array( 'face-1.jpg', 'face-2.jpg', 'face-3.jpg', 'face-4.jpg' ) === array_column( $sent['payload']['inputImage'], 'file' ) && 'FACEBYTES' === $sent['payload']['inputImage'][0]['bytes'], 'Образцы уходят файлами, повторяющимся полем inputImage, не больше четырёх' );
$assert( '3:4' === $sent['payload']['aspectRatio'] && '1' === $sent['payload']['maxImages'] && isset( $sent['args']['headers']['x-signature'] ), 'Поля модели и подпись в multipart на месте' );
$assert( is_wp_error( VKT_Wiro::start( 'seedream-4-5', 'кот на окне', 'portrait', array( 7 ) ) ), 'Образец больше 10 МБ не отправляется' );
$logged = array_values( array_filter( VKT_Store::$logs, static fn( $row ) => 'Задача генерации поставлена' === $row['message'] ) );
$detail = json_encode( end( $logged )['detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$assert( str_contains( $detail, 'bytedance/seedream-v4-5-uncensored' ) && str_contains( $detail, 'Девушка на пляже в купальнике' ) && str_contains( $detail, 'face-1.jpg (9 байт)' ) && str_contains( $detail, '534574' ), 'В журнал уходят модель, промпт, имена образцов и ответ Wiro' );
$assert( ! str_contains( $detail, 'FACEBYTES' ) && ! str_contains( $detail, 'wiro_key' ) && ! str_contains( $detail, 'wiro_secret' ), 'Байты фото, ключ и секрет в журнал не попадают' );
$assert( ! str_contains( $sent['args']['body'], 'wiro_key' ), 'Ключ в тело запроса не попадает' );
$assert( is_wp_error( VKT_Wiro::start( 'seedream-4-5', 'кот на окне', 'portrait', array( 6 ) ) ), 'Видео образцом не принимается' );
queue( reply( array( 'result' => true, 'taskid' => '777' ) ) );
VKT_Wiro::start( 'z-image-pro', 'кот на окне', 'square', array( 4 ) );
$assert( ! last_sent()['multipart'] && ! isset( last_sent()['payload']['inputImage'] ) && 'https://api.wiro.ai/v1/Run/wiro-partners/z-image-pro-uncensored' === last_sent()['url'], 'Z Image образцы не получает и идёт обычным JSON' );
queue( reply( array( 'result' => false, 'errors' => array( array( 'message' => 'Insufficient balance' ) ) ) ) );
$broke = VKT_Wiro::start( 'z-image-pro', 'кот на окне', 'square' );
$assert( is_wp_error( $broke ) && str_contains( $broke->get_error_message(), 'Insufficient balance' ), 'Отказ Wiro показывается его словами' );

// Лимит одновременных задач — очередь, а не сбой: помечен busy, в журнал (и в оповещения о деньгах) не попадает.
VKT_Store::$logs = array();
queue( reply( array( 'result' => false, 'errors' => array( array( 'message' => 'You have reached your concurrent task limit. With your current balance of $19.88, you can run up to 1 tasks at the same time. Add funds to increase your limit.' ) ) ) ) );
$busy = VKT_Wiro::start( 'z-image-pro', 'кот на окне', 'square' );
$assert( is_wp_error( $busy ) && true === $busy->get_error_data()['busy'] && 1 === $busy->get_error_data()['slots'] && 409 === $busy->get_error_data()['status'], 'Занятый Wiro отвечает busy с числом мест' );
$assert( ! str_contains( $busy->get_error_message(), 'balance' ) && ! str_contains( $busy->get_error_message(), 'лимит' ) && array() === VKT_Store::$logs, 'Текст без «balance» и «лимит», журнал чист: пачка не остановится, оповещения о деньгах не будет' );

// ——— Опрос ———
$task = static fn( $status, $extra = array() ) => reply( array( 'result' => true, 'tasklist' => array( array_merge( array( 'id' => '534574', 'status' => $status ), $extra ) ) ) );
queue( $task( 'task_queue' ) );
$assert( 'Pending' === VKT_Wiro::status( '534574' )['stage'], 'В очереди — «в очереди»' );
queue( $task( 'task_start' ) );
$assert( 'Generating' === VKT_Wiro::status( '534574' )['stage'], 'Запущена — «рисует»' );
queue( $task( 'task_end', array( 'pexit' => '0' ) ) );
$assert( 'pending' === VKT_Wiro::status( '534574' )['status'], 'task_end ещё не конец: ждём постобработку' );
queue( $task( 'task_error', array( 'debugerror' => 'requeued' ) ) );
$assert( 'pending' === VKT_Wiro::status( '534574' )['status'], 'task_error в Task/Detail — не провал: Wiro может перезапустить' );
VKT_Account::$id = 9;
$assert( is_wp_error( VKT_Wiro::status( '534574' ) ), 'Чужую задачу не опросить' );
VKT_Account::$id = 5;
queue( $task( 'task_postprocess_end', array( 'pexit' => '0', 'outputs' => array( array( 'name' => '0.png', 'contenttype' => 'image/png', 'url' => 'https://cdn1.wiro.ai/xyz/0.png' ) ) ) ) );
$done = VKT_Wiro::status( '534574' );
$assert( 'done' === $done['status'] && 91 === $done['media']['id'] && array( 'https://cdn1.wiro.ai/xyz/0.png' ) === VKT_Media::$sideloaded, 'Готовая картинка сразу ложится в медиатеку' );
$assert( is_wp_error( VKT_Wiro::status( '534574' ) ), 'Забранная задача второй раз не отдаётся' );
queue( $task( 'task_postprocess_end', array( 'pexit' => '1', 'outputs' => array(), 'debugerror' => 'CUDA out of memory' ) ) );
$failed = VKT_Wiro::status( '777' );
$assert( is_wp_error( $failed ) && str_contains( $failed->get_error_message(), 'CUDA out of memory' ), 'Сбой модели показывается с причиной' );
queue( reply( array( 'result' => true, 'taskid' => '888' ) ), $task( 'task_cancel' ) );
VKT_Wiro::start( 'z-image-pro', 'кот на окне', 'square' );
$assert( is_wp_error( VKT_Wiro::status( '888' ) ), 'Отменённая задача — ошибка, а не вечное ожидание' );

// ——— Стенд «Генерация фото» ———
$GLOBALS['sent'] = array();
$text_only = VKT_Wiro::stand_start( array( 'model' => 'wiro:z-image-pro', 'prompt' => 'кот на окне', 'images' => array( 4 ) ) );
$assert( is_wp_error( $text_only ) && str_contains( $text_only->get_error_message(), 'только по тексту' ) && array() === $GLOBALS['sent'], 'Z Image с образцом на стенде — ошибка, а не молча выброшенный файл' );
$assert( is_wp_error( VKT_Wiro::stand_start( array( 'model' => 'wiro:seedream-4-5', 'prompt' => 'кот на окне', 'images' => array( 1, 2, 3, 4, 5 ) ) ) ), 'Seedream со стенда берёт не больше четырёх образцов' );
$assert( is_wp_error( VKT_Wiro::stand_start( array( 'model' => 'flux-2-pro', 'prompt' => 'кот на окне' ) ) ), 'Модель BFL в Wiro не уходит' );
queue( reply( array( 'result' => true, 'taskid' => '991' ) ) );
$stand = VKT_Wiro::stand_start( array( 'model' => 'wiro:z-image-pro', 'prompt' => 'кот на окне', 'ratio' => 'landscape', 'seed' => 42 ) );
$assert( 'wiro:991' === $stand['id'] && 'z-image-pro' === $stand['model'], 'ID задачи стенда помнит поставщика' );
$assert( '16:9' === last_sent()['payload']['ratio'] && 42 === last_sent()['payload']['seed'], 'Формат кадра и seed со стенда доходят до Z Image' );
queue( reply( array( 'result' => true, 'taskid' => '992' ) ) );
VKT_Wiro::stand_start( array( 'model' => 'wiro:seedream-4-5', 'prompt' => 'кот на окне', 'seed' => 42, 'images' => array( 4 ) ) );
$assert( ! isset( last_sent()['payload']['seed'] ) && isset( last_sent()['payload']['inputImage'] ), 'Seedream seed не получает: в его параметрах поля нет' );
$assert( 2147483647 === VKT_Wiro::payload( 'z-image-pro', 'кот', 'square', 9999999999 )['seed'], 'Seed не выходит за предел Wiro' );
queue( $task( 'task_postprocess_end', array( 'pexit' => '0', 'totalcost' => '0.02', 'outputs' => array( array( 'contenttype' => 'image/png', 'url' => 'https://cdn1.wiro.ai/s/0.png' ) ) ) ) );
$assert( 0.02 === VKT_Wiro::status( '991' )['cost_usd'], 'Списанная сумма в долларах отдаётся стенду' );

// ——— Удаление ———
VKT_Wiro::forget();
$assert( ! VKT_Wiro::configured() && ! isset( $GLOBALS['options'][ VKT_Wiro::OPTION ] ), 'После удаления ключа Wiro отключён' );

@unlink( $face );
echo "All {$checks} offline Wiro checks passed.\n";

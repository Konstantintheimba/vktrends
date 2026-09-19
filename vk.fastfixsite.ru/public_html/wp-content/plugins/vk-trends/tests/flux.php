<?php
// Изолированная проверка стенда FLUX: сборка запроса и разбор ответов BFL. Без сети и WordPress.
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
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url, $protocols = array() ) {
    return in_array( strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ), $protocols, true ) ? $url : '';
}
function add_query_arg( $key, $value, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value; }
$GLOBALS['transients'] = array();
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); return true; }

class VKT_Store {
    public static array $logs = array();
    public static function table( $name ) { return 'wp_vkt_' . $name; }
    public static function log( $method, $context, $status, $code, $message, $ms = 0 ) {
        self::$logs[] = compact( 'method', 'context', 'status', 'code', 'message' );
    }
}
class VKT_Tokens {
    public static string $key = 'bfl_TESTKEY_0123456789abcdef';
    public static array $notes = array();
    public static function has( $slot ) { return 'bfl' === $slot && '' !== self::$key; }
    public static function token( $slot ) { return 'bfl' === $slot ? self::$key : ''; }
    public static function note( $slot, $message ) { self::$notes[ $slot ] = $message; }
}
class VKT_Account {
    public static int $id = 5;
    public static function id() { return self::$id; }
}
class VKT_Media {
    public static array $files = array();
    public static array $sideloaded = array();
    public static bool $owned = true;
    public static function item( $id ) {
        $id = absint( $id );
        return self::$files[ $id ] ?? new WP_Error( 'vkt_media', 'Локальный файл не найден.', array( 'status' => 404 ) );
    }
    public static function owned( $id ) { return self::$owned; }
    public static function sideload( $url, $kind ) {
        self::$sideloaded[] = array( 'url' => $url, 'kind' => $kind );
        return array( 'id' => 77, 'url' => $url, 'name' => 'flux.jpg', 'type' => 'image', 'thumbnail' => $url, 'size' => 1024 );
    }
}

// Сеть: запоминаем запросы и отдаём заготовленные ответы по очереди.
$GLOBALS['sent'] = array();
$GLOBALS['replies'] = array();
function wp_remote_request( $url, $args ) {
    $GLOBALS['sent'][] = array( 'url' => $url, 'args' => $args, 'payload' => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null );
    $reply = array_shift( $GLOBALS['replies'] );
    return $reply ?? array( 'code' => 200, 'body' => wp_json_encode( array() ) );
}
function wp_remote_retrieve_response_code( $response ) { return is_wp_error( $response ) ? 0 : (int) $response['code']; }
function wp_remote_retrieve_body( $response ) { return is_wp_error( $response ) ? '' : (string) $response['body']; }
function reply( $body, $code = 200 ) { return array( 'code' => $code, 'body' => wp_json_encode( $body ) ); }
function queue( ...$replies ) { $GLOBALS['replies'] = $replies; }
function last_payload() { return end( $GLOBALS['sent'] )['payload']; }

require dirname( __DIR__ ) . '/includes/class-flux.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

$png = __DIR__ . '/flux-sample.png';
file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
VKT_Media::$files = array(
    1 => array( 'id' => 1, 'type' => 'image', 'name' => 'model.png', 'path' => $png, 'size' => filesize( $png ) ),
    2 => array( 'id' => 2, 'type' => 'image', 'name' => 'ref.png', 'path' => $png, 'size' => filesize( $png ) ),
    3 => array( 'id' => 3, 'type' => 'image', 'name' => 'ref2.png', 'path' => $png, 'size' => filesize( $png ) ),
    4 => array( 'id' => 4, 'type' => 'image', 'name' => 'ref3.png', 'path' => $png, 'size' => filesize( $png ) ),
    5 => array( 'id' => 5, 'type' => 'image', 'name' => 'ref4.png', 'path' => $png, 'size' => filesize( $png ) ),
    6 => array( 'id' => 6, 'type' => 'video', 'name' => 'clip.mp4', 'path' => $png, 'size' => 10 ),
    7 => array( 'id' => 7, 'type' => 'image', 'name' => 'huge.png', 'path' => $png, 'size' => 9 * 1024 * 1024 ),
);

// ——— Проверка входных данных ———
$assert( is_wp_error( VKT_Flux::start( array( 'model' => 'gpt-image', 'prompt' => 'тест' ) ) ), 'Неизвестная модель отклоняется' );
$assert( is_wp_error( VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => 'ок' ) ) ), 'Слишком короткий промпт отклоняется' );
$assert( is_wp_error( VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => str_repeat( 'а', 5001 ) ) ) ), 'Слишком длинный промпт отклоняется' );
$assert( is_wp_error( VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => 'Замени купальник', 'images' => array( 6 ) ) ) ), 'Видео образцом не принимается' );
$assert( is_wp_error( VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => 'Замени купальник', 'images' => array( 7 ) ) ) ), 'Файл больше 8 МБ отклоняется' );
VKT_Media::$owned = false;
$foreign = VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => 'Замени купальник', 'images' => array( 1 ) ) );
$assert( is_wp_error( $foreign ) && 404 === $foreign->get_error_data()['status'], 'Чужой файл в стенд не подставить' );
VKT_Media::$owned = true;
$assert( array() === $GLOBALS['sent'], 'Ни одна неверная заявка не ушла в сеть' );

// ——— Сборка запроса ———
queue( reply( array( 'id' => '2f1c9f3a-1111-4c5f-9a0b-0d1e2f3a4b5c', 'polling_url' => 'https://api.eu.bfl.ai/v1/get_result?some=1' ) ) );
$started = VKT_Flux::start( array(
    'model' => 'flux-2-max', 'prompt' => "  Замени купальник <b>по образцу</b>  ", 'images' => array( 1, 2, 3, 4, 5 ),
    'safety_tolerance' => 9, 'seed' => 42, 'width' => 1000, 'height' => 30, 'output_format' => 'gif', 'disable_pup' => true,
) );
$payload = last_payload();
$assert( ! is_wp_error( $started ) && '2f1c9f3a-1111-4c5f-9a0b-0d1e2f3a4b5c' === $started['id'] && 'pending' === $started['status'], 'Задача поставлена и вернула свой ID' );
$assert( str_ends_with( end( $GLOBALS['sent'] )['url'], '/v1/flux-2-max' ), 'Запрос ушёл на выбранную модель' );
$assert( 'bfl_TESTKEY_0123456789abcdef' === end( $GLOBALS['sent'] )['args']['headers']['x-key'], 'Ключ уходит заголовком x-key' );
$assert( 'Замени купальник по образцу' === $payload['prompt'], 'Промпт обрезан и очищен от разметки' );
$assert( 5 === $payload['safety_tolerance'], 'Строгость выше пяти приводится к пяти' );
$assert( 'jpeg' === $payload['output_format'], 'Неизвестный формат заменяется на jpeg' );
$assert( 992 === $payload['width'] && 64 === $payload['height'], 'Стороны кратны 32 и не меньше 64' );
$assert( 42 === $payload['seed'] && true === $payload['disable_pup'], 'Seed и отказ от расширения промпта переданы' );
$assert( isset( $payload['input_image'], $payload['input_image_2'], $payload['input_image_3'], $payload['input_image_4'] ), 'Первое изображение исходное, остальные — образцы' );
$assert( ! isset( $payload['input_image_5'] ), 'Пятое изображение в запрос не попадает: предел стенда — четыре' );
$assert( str_starts_with( $payload['input_image'], 'iVBORw0KGgo' ), 'Картинка уходит в base64, без адреса сайта' );
$assert( ! str_contains( wp_json_encode( $payload ), 'bfl_TESTKEY' ), 'Ключ в теле запроса не дублируется' );

queue( reply( array( 'id' => '2f1c9f3a-2222-4c5f-9a0b-0d1e2f3a4b5c', 'polling_url' => 'https://api.bfl.ai/v1/get_result?x=2' ) ) );
VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => 'Каталожное фото товара' ) );
$payload = last_payload();
$assert( ! isset( $payload['input_image'], $payload['width'], $payload['height'], $payload['seed'], $payload['disable_pup'] ), 'Без изображений и размеров лишних полей в запросе нет' );
$assert( 2 === $payload['safety_tolerance'], 'Строгость по умолчанию — 2' );

// ——— Опрос статуса ———
queue( reply( array( 'status' => 'Generating', 'progress' => 0.4 ) ) );
$pending = VKT_Flux::status( '2f1c9f3a-1111-4c5f-9a0b-0d1e2f3a4b5c' );
$assert( 'pending' === $pending['status'] && 'Generating' === $pending['stage'] && 40 === $pending['progress'], 'Промежуточный статус показывается как есть' );
queue( reply( array( 'status' => 'Pending', 'progress' => 35 ) ) );
$assert( 35 === VKT_Flux::status( '2f1c9f3a-1111-4c5f-9a0b-0d1e2f3a4b5c' )['progress'], 'Готовность в процентах не умножается ещё раз' );
queue( reply( array( 'status' => 'Pending' ) ) );
$assert( null === VKT_Flux::status( '2f1c9f3a-1111-4c5f-9a0b-0d1e2f3a4b5c' )['progress'], 'Без готовности поле остаётся пустым' );
$assert( str_contains( end( $GLOBALS['sent'] )['url'], 'https://api.eu.bfl.ai/v1/get_result?some=1&id=2f1c9f3a-1111-4c5f-9a0b-0d1e2f3a4b5c' ), 'Опрос идёт по адресу из ответа BFL, со своим регионом' );

$assert( is_wp_error( VKT_Flux::status( '00000000-dead-4000-8000-000000000000' ) ), 'Неизвестная задача не опрашивается' );
VKT_Account::$id = 6;
$alien = VKT_Flux::status( '2f1c9f3a-1111-4c5f-9a0b-0d1e2f3a4b5c' );
$assert( is_wp_error( $alien ) && 404 === $alien->get_error_data()['status'], 'Чужую задачу не подсмотреть по ID' );
VKT_Account::$id = 5;

queue( reply( array( 'status' => 'Request Moderated', 'details' => array( 'Moderation Reasons' => array( 'Sexual Content' ) ) ) ) );
$request_moderated = VKT_Flux::status( '2f1c9f3a-1111-4c5f-9a0b-0d1e2f3a4b5c' );
$assert( is_wp_error( $request_moderated ) && 'request' === $request_moderated->get_error_data()['moderation'], 'Отказ на входе распознан' );
$assert( str_contains( $request_moderated->get_error_message(), 'генерации не было' ) && str_contains( $request_moderated->get_error_message(), 'Sexual Content' ), 'Отказ на входе объяснён словами и с причиной сервиса' );

queue( reply( array( 'id' => '2f1c9f3a-3333-4c5f-9a0b-0d1e2f3a4b5c', 'polling_url' => 'https://api.bfl.ai/v1/get_result?x=3' ) ) );
VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => 'Замени купальник на раздельный' ) );
queue( reply( array( 'status' => 'Content Moderated', 'details' => array( 'Moderation Reasons' => array( 'Sexual Content' ) ) ) ) );
$content_moderated = VKT_Flux::status( '2f1c9f3a-3333-4c5f-9a0b-0d1e2f3a4b5c' );
$assert( is_wp_error( $content_moderated ) && 'content' === $content_moderated->get_error_data()['moderation'], 'Отказ на выходе распознан отдельно от входного' );
$assert( str_contains( $content_moderated->get_error_message(), 'не отдал его' ) && str_contains( $content_moderated->get_error_message(), 'Строгость здесь уже не помогает' ), 'Разница между отказом на входе и на выходе объяснена' );
$assert( false === get_transient( 'vkt_flux_' . hash( 'sha256', '2f1c9f3a-3333-4c5f-9a0b-0d1e2f3a4b5c' ) ), 'Отклонённая задача больше не опрашивается' );

queue( reply( array( 'id' => '2f1c9f3a-4444-4c5f-9a0b-0d1e2f3a4b5c', 'polling_url' => 'https://api.bfl.ai/v1/get_result?x=4' ) ) );
VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => 'Каталожное фото' ) );
queue( reply( array( 'status' => 'Ready', 'cost' => 0.0432, 'result' => array( 'sample' => 'https://delivery-eu1.bfl.ai/results/abc.jpg' ) ) ) );
$done = VKT_Flux::status( '2f1c9f3a-4444-4c5f-9a0b-0d1e2f3a4b5c' );
$assert( 'done' === $done['status'] && 77 === $done['media']['id'] && 0.043 === $done['cost'], 'Готовый файл кладётся в медиатеку, стоимость возвращается' );
$assert( 'https://delivery-eu1.bfl.ai/results/abc.jpg' === end( VKT_Media::$sideloaded )['url'], 'Файл скачивается сразу: ссылка BFL живёт десять минут' );
$assert( false === get_transient( 'vkt_flux_' . hash( 'sha256', '2f1c9f3a-4444-4c5f-9a0b-0d1e2f3a4b5c' ) ), 'Выполненная задача забывается' );

// ——— Ошибки сервиса ———
queue( reply( array( 'detail' => 'no credits' ), 402 ) );
$paid = VKT_Flux::credits();
$assert( is_wp_error( $paid ) && str_contains( $paid->get_error_message(), 'кредиты' ), 'Нехватка кредитов объяснена словами' );
$assert( '' !== VKT_Tokens::$notes['bfl'], 'Отказ закрепляется за ключом BFL' );
queue( reply( array( 'credits' => 986.53 ) ) );
$credits = VKT_Flux::credits();
$assert( 986.53 === $credits['credits'] && '' === VKT_Tokens::$notes['bfl'], 'Удачная проверка показывает баланс и снимает прошлую ошибку' );
queue( reply( array( 'detail' => 'too many' ), 429 ) );
$busy = VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => 'Каталожное фото' ) );
$assert( is_wp_error( $busy ) && str_contains( $busy->get_error_message(), 'одновременных задач' ), 'Предел одновременных задач объяснён' );

$log = wp_json_encode( VKT_Store::$logs );
$assert( ! str_contains( $log, 'bfl_TESTKEY' ), 'Ключ не попадает в журнал' );
$assert( str_contains( $log, 'Content Moderated' ) && str_contains( $log, 'Sexual Content' ), 'Причина отказа видна в журнале' );

VKT_Tokens::$key = '';
$without = VKT_Flux::start( array( 'model' => 'flux-2-pro', 'prompt' => 'Каталожное фото' ) );
$assert( is_wp_error( $without ) && str_contains( $without->get_error_message(), 'Ключ BFL не сохранён' ), 'Без ключа стенд ничего не отправляет' );

@unlink( $png );
echo "All $checks offline FLUX checks passed.\n";

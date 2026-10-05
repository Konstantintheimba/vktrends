<?php
// Изолированная проверка медиамодуля и клиента xAI — без WordPress и сети.
define( 'ABSPATH', __DIR__ . '/' );
define( 'VKT_XAI_API_KEY', 'xai-TEST-SECRET-KEY' );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $value ); }
function wp_strip_all_tags( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function esc_url_raw( $url, $protocols = array() ) {
    $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
    return in_array( $scheme, $protocols, true ) ? $url : '';
}
function wp_http_validate_url( $url ) {
    $host = (string) parse_url( $url, PHP_URL_HOST );
    return '' !== $host && ! in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1' ), true );
}
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl ) { return true; }
function delete_transient( $key ) { return true; }

// Ключи моделей в тесте «шифруются» приставкой — достаточно, чтобы проверить, что наружу уходит не он.
class VKT_Tokens {
    public static function has( $slot ) { return false; }
    public static function seal( $plain ) { return 'SEALED:' . $plain; }
    public static function unseal( $sealed ) { return str_starts_with( (string) $sealed, 'SEALED:' ) ? substr( $sealed, 7 ) : ''; }
}
$GLOBALS['options'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = true ) { $GLOBALS['options'][ $key ] = $value; return true; }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
class VKT_Account {
    public static function is_admin() { return true; }
    public static function id() { return 1; }
    public static function ai_quota() { return null; }
}
class VKT_Store {
    public static array $entries = array();
    public static function log( $method, $context, $status, $code, $message, $ms = 0 ) {
        self::$entries[] = compact( 'method', 'context', 'status', 'code', 'message' );
    }
}

// Медиатека: несколько файлов на диске, как их видит WordPress.
$library = array();
foreach ( array( 11 => 'image/jpeg', 12 => 'video/mp4', 13 => 'application/pdf' ) as $id => $mime ) {
    $path = tempnam( sys_get_temp_dir(), 'vkt' );
    file_put_contents( $path, str_repeat( 'x', 2048 ) );
    $library[ $id ] = array( 'mime' => $mime, 'path' => $path );
}
function get_post_type( $id ) { global $library; return isset( $library[ $id ] ) ? 'attachment' : ''; }
function get_post_meta( $id, $key = '', $single = false ) { global $library; return $library[ $id ]['meta'][ $key ] ?? ''; }
function get_post_mime_type( $id ) { global $library; return $library[ $id ]['mime'] ?? ''; }
function get_attached_file( $id ) { global $library; return $library[ $id ]['path'] ?? false; }
function wp_get_attachment_url( $id ) { global $library; return isset( $library[ $id ] ) ? 'https://example.test/files/' . $id . '.bin' : false; }
function wp_get_attachment_image_url( $id, $size ) { global $library; return 'image/jpeg' === ( $library[ $id ]['mime'] ?? '' ) ? 'https://example.test/files/' . $id . '-medium.jpg' : false; }
function get_the_title( $id ) { return 'Файл ' . $id; }

// Тексты идут через OpenAI-совместимый chat/completions любого поставщика.
$GLOBALS['vkt_http'] = array();
function wp_remote_post( $url, $args ) {
    $GLOBALS['vkt_http'][] = array( 'url' => $url, 'args' => $args );
    // Так отвечает шлюз, когда поставщик не обслуживает страну сервера: не JSON.
    if ( str_contains( $url, 'blocked.test' ) ) {
        return array( 'response' => array( 'code' => 403 ), 'body' => '<html><body><h1>403 Forbidden</h1>Access denied</body></html>' );
    }
    if ( str_ends_with( $url, '/chat/completions' ) ) {
        $body = array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => 'Готовый текст поста' ) ) ) );
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ) );
    }
    return array( 'response' => array( 'code' => 404 ), 'body' => '' );
}
function wp_remote_request( $url, $args ) {
    $GLOBALS['vkt_http'][] = array( 'url' => $url, 'args' => $args );
    if ( str_ends_with( $url, '/v1/responses' ) ) {
        $body = array( 'output' => array(
            array( 'type' => 'reasoning', 'summary' => array( array( 'type' => 'summary_text', 'text' => 'Служебные размышления' ) ) ),
            array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => 'Готовый текст поста' ) ) ),
        ) );
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ) );
    }
    if ( str_ends_with( $url, '/v1/videos/generations' ) ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'request_id' => '36a62d4d-9891-97cf-915c-aaa28f1e471c' ) ) );
    }
    return array( 'response' => array( 'code' => 401 ), 'body' => json_encode( array( 'error' => array( 'message' => 'bad key' ) ) ) );
}
function wp_remote_retrieve_response_code( $response ) { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return (string) $response['body']; }

require dirname( __DIR__ ) . '/includes/class-media.php';
// Предел серии живёт в публикаторе: в боевом плагине оба класса подключены
// безусловно, а изолированному тексту нужна только константа.
class VKT_Publisher { const MAX_SERIES_SLOTS = 60; }
require dirname( __DIR__ ) . '/includes/class-ai.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

// --- Медиатека ---
$image = VKT_Media::public_item( 11 );
$assert( ! is_wp_error( $image ) && 'image' === $image['type'], 'Изображение медиатеки читается' );
$assert( ! isset( $image['path'] ), 'Абсолютный путь наружу не уходит' );
$assert( str_ends_with( $image['thumbnail'], '-medium.jpg' ), 'Для изображения отдаётся превью' );
$video = VKT_Media::public_item( 12 );
$assert( ! is_wp_error( $video ) && 'video' === $video['type'] && '' === $video['thumbnail'], 'MP4 читается и остаётся без превью' );
$assert( is_wp_error( VKT_Media::public_item( 13 ) ), 'PDF и прочие форматы отклоняются' );
$assert( is_wp_error( VKT_Media::public_item( 99 ) ), 'Несуществующее вложение отклоняется' );
$assert( array( 11, 12 ) === VKT_Media::validate_ids( array( 11, 12, 11, 0 ) ), 'Дубли и нули отбрасываются' );
$assert( is_wp_error( VKT_Media::validate_ids( array( 11, 13 ) ) ), 'Недопустимый файл в списке отклоняет весь набор' );
$assert( is_wp_error( VKT_Media::validate_ids( range( 11, 30 ) ) ), 'Больше десяти файлов VK не принимает' );

// --- Клиент xAI ---
$status = VKT_AI::public_status();
$assert( true === $status['configured'], 'Наличие ключа видно интерфейсу' );
$assert( ! str_contains( json_encode( $status ), VKT_XAI_API_KEY ), 'Ключ xAI наружу не отдаётся' );
$assert( is_wp_error( VKT_AI::generate_text( 'ок' ) ), 'Слишком короткая задача отклоняется до запроса' );
$text = VKT_AI::generate_text( 'Анонс распродажи', 'Черновик' );
$assert( ! is_wp_error( $text ) && 'Готовый текст поста' === $text['text'], 'Текст берётся из ответа chat/completions' );
$assert( 'https://api.x.ai/v1/chat/completions' === $GLOBALS['vkt_http'][0]['url'], 'Без выбора пишет модель по умолчанию — xAI из wp-config.php' );
$assert( 'Bearer ' . VKT_XAI_API_KEY === $GLOBALS['vkt_http'][0]['args']['headers']['Authorization'], 'Ключ уходит только в заголовке сервера' );
$assert( str_contains( $GLOBALS['vkt_http'][0]['args']['body'], 'Черновик' ), 'Текущий черновик передаётся модели' );
$video_job = VKT_AI::start_video( 'Осенний парк', 'story' );
$assert( ! is_wp_error( $video_job ) && 'pending' === $video_job['status'], 'Задача генерации видео принимается' );
$assert( '9:16' === json_decode( $GLOBALS['vkt_http'][1]['args']['body'], true )['aspect_ratio'], 'Формат кадра переводится в поддерживаемое xAI соотношение' );
$assert( '3:4' === VKT_AI::IMAGE_RATIOS['portrait'] && ! in_array( '4:5', VKT_AI::IMAGE_RATIOS, true ), 'Используются только соотношения из списка xAI' );
$assert( is_wp_error( VKT_AI::video_status( 'коротко' ) ), 'Неверный ID задачи отклоняется' );
$failed = VKT_AI::video_status( 'e2871717-85bf-9bbb-83a9-e51f098efaef' );
$assert( is_wp_error( $failed ) && ! str_contains( $failed->get_error_message(), VKT_XAI_API_KEY ), 'Ошибка xAI не раскрывает ключ' );
$assert( ! str_contains( json_encode( VKT_Store::$entries ), VKT_XAI_API_KEY ), 'В журнал попадают только метод и статус' );

// ——— Несколько моделей для текстов ———
$saved = VKT_AI::save_model( array( 'preset' => 'deepseek', 'key' => 'sk-deepseek-SECRET-1234' ) );
$assert( ! is_wp_error( $saved ) && 'Готовый текст поста' === $saved['answer'], 'Модель сохраняется только после удачного пробного запроса' );
$assert( 'https://api.deepseek.com/chat/completions' === end( $GLOBALS['vkt_http'] )['url'] && 'deepseek-chat' === json_decode( end( $GLOBALS['vkt_http'] )['args']['body'], true )['model'], 'Адрес и модель подставляются из готовых настроек поставщика' );
$assert( 'SEALED:sk-deepseek-SECRET-1234' === $GLOBALS['options']['vkt_text_models']['models'][ $saved['id'] ]['key'], 'Ключ модели хранится зашифрованным' );
$listed = VKT_AI::text_models();
$assert( 2 === count( $listed ) && ! str_contains( json_encode( $listed ), 'sk-deepseek-SECRET-1234' ), 'Список моделей без ключей' );
$assert( $saved['id'] === VKT_AI::default_model(), 'Первая добавленная модель сразу пишет вместо встроенной — например, вместо недоступного xAI' );
VKT_AI::generate_text( 'Анонс распродажи', '', $saved['id'] );
$request = end( $GLOBALS['vkt_http'] );
$assert( 'https://api.deepseek.com/chat/completions' === $request['url'] && 'Bearer sk-deepseek-SECRET-1234' === $request['args']['headers']['Authorization'], 'Выбранная модель пишет своим ключом' );
$assert( ! is_wp_error( VKT_AI::set_default_model( 'xai' ) ) && 'xai' === VKT_AI::default_model(), 'Модель по умолчанию меняется' );
VKT_AI::set_default_model( $saved['id'] );
$blocked = VKT_AI::save_model( array( 'preset' => 'custom', 'base' => 'https://blocked.test/v1', 'model' => 'grok-4.6', 'key' => 'sk-blocked-SECRET-99' ) );
$assert( is_wp_error( $blocked ) && str_contains( $blocked->get_error_message(), 'HTTP 403' ) && str_contains( $blocked->get_error_message(), 'страну сервера' ), '403 без JSON объясняется словами, а не пустым «отклонил запрос»' );
$assert( ! isset( $GLOBALS['options']['vkt_text_models']['models']['custom-' . substr( md5( 'https://blocked.test/v1|grok-4.6' ), 0, 8 )] ), 'Недоступная модель не сохраняется' );
$assert( 'settings' === ( $blocked->get_error_data()['fix']['view'] ?? '' ), 'Отказ модели ведёт в «Настройки»' );
$assert( is_wp_error( VKT_AI::save_model( array( 'preset' => 'custom', 'base' => 'http://plain.test', 'model' => 'x', 'key' => 'sk-12345678' ) ) ), 'Адрес без https не принимается' );
$assert( ! is_wp_error( VKT_AI::delete_model( $saved['id'] ) ) && 'xai' === VKT_AI::default_model(), 'Убранная модель по умолчанию уступает место оставшейся' );
$assert( is_wp_error( VKT_AI::delete_model( 'xai' ) ), 'Модель из wp-config.php из интерфейса не убирается' );
$assert( ! str_contains( json_encode( VKT_Store::$entries ), 'SECRET' ), 'Ключи моделей не попадают в журнал' );

// ——— Серия текстов одним запросом ———
// Просьбу вернуть чистый JSON модель выполняет не всегда, поэтому разбор
// проверяется на всех трёх формах ответа, которые встречались живьём.
$parse = new ReflectionMethod( VKT_AI::class, 'parse_series' );
$parse->setAccessible( true );
$assert( array( 'Первый', 'Второй' ) === $parse->invoke( null, '{"posts":["Первый","Второй"]}' ), 'Чистый JSON с ключом posts' );
$assert( array( 'Первый', 'Второй' ) === $parse->invoke( null, "```json\n{\"posts\":[\"Первый\",\"Второй\"]}\n```" ), 'JSON в тройных кавычках' );
$assert( array( 'Первый', 'Второй' ) === $parse->invoke( null, '["Первый","Второй"]' ), 'Массив без ключа posts' );
$assert( array( 'Первый', 'Второй' ) === $parse->invoke( null, '[{"text":"Первый"},{"text":"Второй"}]' ), 'Массив объектов с полем text' );
$numbered = $parse->invoke( null, "1. Первый пост\n2) Второй пост\n3. Третий пост" );
$assert( 3 === count( $numbered ) && 'Первый пост' === $numbered[0] && 'Третий пост' === $numbered[2], 'Пронумерованный список тоже разбирается' );
$assert( array() === $parse->invoke( null, '   ' ), 'Пустой ответ даёт пустой список' );
$assert( array() === $parse->invoke( null, '{"posts":[]}' ), 'Пустой список остаётся пустым' );
$assert( is_wp_error( VKT_AI::generate_series( 'ок', 5 ) ), 'Слишком короткая тема серии отклоняется до запроса' );

foreach ( $library as $file ) { @unlink( $file['path'] ); }
echo "All $checks offline media and xAI checks passed.\n";

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

class VKT_Tokens { public static function has( $slot ) { return false; } }
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
function get_post_mime_type( $id ) { global $library; return $library[ $id ]['mime'] ?? ''; }
function get_attached_file( $id ) { global $library; return $library[ $id ]['path'] ?? false; }
function wp_get_attachment_url( $id ) { global $library; return isset( $library[ $id ] ) ? 'https://example.test/files/' . $id . '.bin' : false; }
function wp_get_attachment_image_url( $id, $size ) { global $library; return 'image/jpeg' === ( $library[ $id ]['mime'] ?? '' ) ? 'https://example.test/files/' . $id . '-medium.jpg' : false; }
function get_the_title( $id ) { return 'Файл ' . $id; }

// Ответы xAI повторяют формат живого API: текст лежит в output[].content[].
$GLOBALS['vkt_http'] = array();
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
$assert( ! is_wp_error( $text ) && 'Готовый текст поста' === $text['text'], 'Текст собирается из блоков ответа, минуя размышления' );
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

foreach ( $library as $file ) { @unlink( $file['path'] ); }
echo "All $checks offline media and xAI checks passed.\n";

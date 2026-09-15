<?php
// Изолированная проверка схемы и строгого формата вложений — без WordPress и сети.
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
}
function esc_url_raw( $url, $protocols = array() ) {
    $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
    return in_array( $scheme, $protocols, true ) ? $url : '';
}
function wp_http_validate_url( $url ) {
    $host = (string) parse_url( $url, PHP_URL_HOST );
    return '' !== $host && ! in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1' ), true );
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function absint( $value ) { return abs( (int) $value ); }

define( 'VKT_COMMUNITY_ID', 241464933 );
class VKT_Community {
    public static bool $on = true;
    public static function configured() { return self::$on; }
    public static function group_id() { return 241464933; }
}

require dirname( __DIR__ ) . '/includes/class-publisher.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    ++$checks;
};
$method = new ReflectionMethod( VKT_Publisher::class, 'sanitize_attachments' );
$method->setAccessible( true );
$run_due = new ReflectionMethod( VKT_Publisher::class, 'run_due' );

$schema = VKT_Publisher::schema();
$assert( 3 === count( $schema ), 'Publisher owns three tables' );
$assert( str_contains( $schema['outbound_posts'], 'origin varchar(20)' ), 'Drafts store manual or agent origin' );
$assert( str_contains( $schema['outbound_posts'], 'editor_status varchar(20)' ), 'Drafts store editor state' );
$assert( str_contains( $schema['outbound_deliveries'], "status varchar(20) NOT NULL DEFAULT 'pending'" ), 'Deliveries have an explicit queue state' );
$assert( 2 === $run_due->getNumberOfParameters(), 'Immediate publishing can target the newly created post instead of an older queue item' );
$assert( '' === $method->invoke( null, '' ), 'Empty attachments allowed when text exists' );
$assert( 'photo-123_456,video987_654' === $method->invoke( null, "photo-123_456\nvideo987_654" ), 'VK attachment IDs normalized' );
$assert( 'photo-123_456_ab-CD_9' === $method->invoke( null, 'photo-123_456_ab-CD_9' ), 'Attachment access key accepted' );
$assert( 'https://example.com/item?a=1' === $method->invoke( null, 'https://example.com/item?a=1' ), 'One public link accepted' );
$assert( is_wp_error( $method->invoke( null, 'javascript:alert(1)' ) ), 'Non-HTTP scheme rejected' );
$assert( is_wp_error( $method->invoke( null, 'https://example.com/photo.jpg' ) ), 'Прямая ссылка на изображение отклоняется до запроса к VK' );
$assert( is_wp_error( $method->invoke( null, 'https://example.com/clip.MP4' ) ), 'Регистр расширения не обходит проверку' );
$assert( 'https://example.com/catalog/jpg-lamp' === $method->invoke( null, 'https://example.com/catalog/jpg-lamp' ), 'Страница с похожим адресом остаётся разрешённой' );
$assert( 'https://example.com/item?file=a.jpg' === $method->invoke( null, 'https://example.com/item?file=a.jpg' ), 'Проверяется путь, а не строка запроса' );
$assert( is_wp_error( $method->invoke( null, "https://example.com/a\nhttps://example.com/b" ) ), 'Second external link rejected' );
$assert( is_wp_error( $method->invoke( null, 'audio-123_456' ) ), 'Audio without carousel support rejected locally' );
$assert( is_wp_error( $method->invoke( null, 'doc-123_1,doc-123_2' ) ), 'Second document rejected locally' );
$assert( is_wp_error( $method->invoke( null, 'poll-123_1,poll-123_2' ) ), 'Second poll rejected locally' );
$assert( is_wp_error( $method->invoke( null, 'poll-123_1' ) ), 'Poll cannot be the only attachment' );
$assert( 'photo-123_456,poll-123_1' === $method->invoke( null, 'photo-123_456,poll-123_1' ), 'Poll accepted with another attachment' );
$assert( is_wp_error( $method->invoke( null, implode( ',', array_fill( 0, 11, 'photo-123_456' ) ) ) ) === false, 'Duplicate attachments collapse before the limit' );
$many = array();
for ( $index = 1; $index <= 11; ++$index ) { $many[] = 'photo-123_' . $index; }
$assert( is_wp_error( $method->invoke( null, implode( ',', $many ) ) ), 'More than ten unique attachments rejected' );

// Локальные файлы: порядок вложений и единственная ссылка в конце.
$merge = new ReflectionMethod( VKT_Publisher::class, 'merge_attachments' );
$merge->setAccessible( true );
$assert( str_contains( $schema['outbound_posts'], 'media text NOT NULL' ), 'Записи хранят выбранные файлы медиатеки' );
$assert( str_contains( $schema['outbound_deliveries'], 'media_attachments text NOT NULL' ), 'Полученный ID вложения VK кэшируется в задании' );
$assert( '' === $merge->invoke( null, '', '' ), 'Пустой набор остаётся пустым' );
$assert( 'photo-1_2,photo-1_3' === $merge->invoke( null, 'photo-1_2', 'photo-1_3' ), 'Файлы идут перед ручными вложениями' );
$assert( 'photo-1_3,https://example.com/a' === $merge->invoke( null, '', 'photo-1_3,https://example.com/a' ), 'Ссылка остаётся последней' );
$assert( 'photo-1_3,https://example.com/a' === $merge->invoke( null, '', 'https://example.com/a,photo-1_3' ), 'Ссылка переносится в конец списка' );
$assert( 'https://example.test/f.jpg' === $merge->invoke( null, 'https://example.test/f.jpg', '' ), 'Ссылка в позиции файла остаётся единственным вложением' );
$assert( is_wp_error( $merge->invoke( null, 'https://example.test/f.jpg', 'https://example.com/a' ) ), 'Две ссылки в одной записи отклоняются' );
$assert( 'photo-1_2' === $merge->invoke( null, 'photo-1_2', 'photo-1_2' ), 'Повтор одного вложения схлопывается' );
$assert( is_wp_error( $merge->invoke( null, implode( ',', $many ), '' ) ), 'Файлы и вложения считаются против общего лимита в десять' );

// Выбор токена: вложение публикует тот, кто его загрузил.
$picker = new ReflectionMethod( VKT_Publisher::class, 'use_community_key' );
$picker->setAccessible( true );
$assert( true === $picker->invoke( null, 241464933, '' ), 'Текст в своё сообщество уходит ключом сообщества' );
$assert( false === $picker->invoke( null, 241464933, '11,12' ), 'Запись с файлами публикует пользовательский токен, загрузивший фото' );
$assert( false === $picker->invoke( null, 987, '' ), 'Чужая группа ключом сообщества не публикуется' );
VKT_Community::$on = false;
$assert( false === $picker->invoke( null, 241464933, '' ), 'Без настроенного ключа сообщества остаётся пользовательский токен' );
VKT_Community::$on = true;

echo "All $checks offline publisher checks passed.\n";

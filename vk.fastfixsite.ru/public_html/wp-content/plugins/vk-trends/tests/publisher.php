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

$schema = VKT_Publisher::schema();
$assert( 3 === count( $schema ), 'Publisher owns three tables' );
$assert( str_contains( $schema['outbound_posts'], 'origin varchar(20)' ), 'Drafts store manual or agent origin' );
$assert( str_contains( $schema['outbound_posts'], 'editor_status varchar(20)' ), 'Drafts store editor state' );
$assert( str_contains( $schema['outbound_deliveries'], "status varchar(20) NOT NULL DEFAULT 'pending'" ), 'Deliveries have an explicit queue state' );
$assert( '' === $method->invoke( null, '' ), 'Empty attachments allowed when text exists' );
$assert( 'photo-123_456,video987_654' === $method->invoke( null, "photo-123_456\nvideo987_654" ), 'VK attachment IDs normalized' );
$assert( 'photo-123_456_ab-CD_9' === $method->invoke( null, 'photo-123_456_ab-CD_9' ), 'Attachment access key accepted' );
$assert( 'https://example.com/item?a=1' === $method->invoke( null, 'https://example.com/item?a=1' ), 'One public link accepted' );
$assert( is_wp_error( $method->invoke( null, 'javascript:alert(1)' ) ), 'Non-HTTP scheme rejected' );
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

echo "All $checks offline publisher checks passed.\n";

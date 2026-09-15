<?php
// Изолированная проверка безопасного статуса и чтения тестового сообщества.
define( 'ABSPATH', __DIR__ . '/' );
define( 'VKT_COMMUNITY_ID', 123456 );
define( 'VKT_COMMUNITY_ACCESS_TOKEN', 'COMMUNITY_SECRET_TOKEN' );
define( 'VKT_CALLBACK_CONFIRMATION', '12345678' );
define( 'VKT_CALLBACK_SECRET', 'CALLBACK_SECRET' );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
}
class VKT_Plugin {
    public static function settings() { return array( 'api_version' => '5.199' ); }
}
class VKT_Store {
    public static function log() {}
}
class VKT_Tokens {
    public static function token( $slot ) { return 'community' === $slot ? VKT_COMMUNITY_ACCESS_TOKEN : ''; }
}
function absint( $value ) { return abs( (int) $value ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value, $protocols = array() ) { return $value; }
function wp_remote_retrieve_response_code( $response ) { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return (string) $response['body']; }
function wp_remote_post( $url, $args ) {
    if ( ! hash_equals( VKT_COMMUNITY_ACCESS_TOKEN, (string) ( $args['body']['access_token'] ?? '' ) ) ) {
        return new WP_Error( 'token', 'Token not supplied server-side' );
    }
    if ( str_ends_with( $url, '/wall.post' ) ) {
        $body = array( 'response' => array( 'post_id' => 42 ) );
    } elseif ( str_ends_with( $url, '/groups.getTokenPermissions' ) ) {
        $body = array( 'response' => array( 'permissions' => array( array( 'name' => 'wall' ), array( 'name' => 'manage' ) ) ) );
    } else {
        $body = array( 'response' => array( 'groups' => array( array( 'id' => VKT_COMMUNITY_ID, 'name' => 'Тестовая группа', 'screen_name' => 'test.group' ) ) ) );
    }
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ) );
}

require dirname( __DIR__ ) . '/includes/class-community.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};
$status = VKT_Community::public_status();
$assert( true === $status['configured'] && true === $status['callback_configured'], 'Community and callback configuration detected' );
$assert( VKT_COMMUNITY_ID === $status['group_id'], 'Only safe community ID is public' );
$assert( ! str_contains( json_encode( $status ), VKT_COMMUNITY_ACCESS_TOKEN ) && ! str_contains( json_encode( $status ), VKT_CALLBACK_SECRET ), 'Public status contains no secrets' );
$assert( str_contains( $status['callback_url'], 'admin-post.php?action=vkt_callback' ), 'Callback endpoint URL generated' );
$checked = VKT_Community::check();
$assert( ! is_wp_error( $checked ) && 'Тестовая группа' === $checked['name'], 'Community identity checked through VK methods' );
$assert( array( 'wall', 'manage' ) === $checked['permissions'], 'Only permission names returned' );
$assert( 'test.group' === $checked['screen_name'], 'Screen name keeps a valid dot' );
$published = VKT_Community::publish( array( 'owner_id' => -VKT_COMMUNITY_ID, 'from_group' => 1, 'message' => 'Тест', 'guid' => 'test-guid' ) );
$assert( ! is_wp_error( $published ) && 42 === $published['response']['post_id'], 'Community key publishes to its own wall' );
$assert( is_wp_error( VKT_Community::publish( array( 'owner_id' => -999, 'from_group' => 1, 'message' => 'Чужая группа' ) ) ), 'Community key cannot target another wall' );

echo "All $checks offline community checks passed.\n";

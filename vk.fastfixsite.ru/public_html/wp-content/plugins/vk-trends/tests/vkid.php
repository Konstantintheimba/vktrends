<?php
// Изолированная проверка PKCE-подключения VK ID — без WordPress и сети.
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_current_user_id() { return 7; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function set_url_scheme( $url ) { return preg_replace( '~^http://~', 'https://', (string) $url ); }
function esc_url_raw( $url, $protocols = array() ) {
    $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
    return in_array( $scheme, $protocols, true ) ? $url : '';
}
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
$GLOBALS['transients'] = array();
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); return true; }

class VKT_Plugin {
    public static array $extra = array();
    public static function settings() { return array_merge( array( 'api_version' => '5.199', 'vkid_client_id' => 54770323 ), self::$extra ); }
}
class VKT_Store { public static function log() {} }
class VKT_Tokens { public static function get( $slot ) { return array( 'access_token' => '' ); } }
class VKT_API {
    public static array $saved = array();
    public static function save_token( $token, $kind = 'service', $extra = array() ) { self::$saved = compact( 'token', 'kind', 'extra' ); return true; }
}

require dirname( __DIR__ ) . '/includes/class-vkid.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

$status = VKT_VKID::public_status();
$assert( true === $status['configured'] && 54770323 === $status['client_id'], 'ID приложения читается из настроек' );
$assert( 'https://example.test/' === $status['redirect_uri'], 'По умолчанию адрес возврата — корень сайта, без строки запроса' );
VKT_Plugin::$extra = array( 'vkid_redirect' => 'https://example.test/wp-admin/admin-post.php?action=vkt_vkid' );
$assert( str_contains( VKT_VKID::public_status()['redirect_uri'], 'action=vkt_vkid' ), 'Настроенный адрес возврата подставляется вместо умолчания' );
VKT_Plugin::$extra = array();
$assert( 'https://example.test/hook' === VKT_VKID::sanitize_redirect( 'https://example.test/hook' ), 'Свой адрес принимается' );
$assert( '' === VKT_VKID::sanitize_redirect( '' ), 'Пустое поле возвращает умолчание' );
$assert( is_wp_error( VKT_VKID::sanitize_redirect( 'https://evil.test/steal' ) ), 'Чужой домен как адрес возврата отклоняется' );
$assert( is_wp_error( VKT_VKID::sanitize_redirect( 'javascript:alert(1)' ) ) || '' === VKT_VKID::sanitize_redirect( 'javascript:alert(1)' ), 'Небезопасная схема не проходит' );
$assert( false === $status['blocked_by_constant'], 'Без константы сервисного ключа подключение разрешено' );
$assert( 'wall photos groups video' === $status['scope'], 'Запрашиваются ровно нужные права' );
$assert( false === $status['has_secret'], 'По умолчанию защищённый ключ не требуется — подключение идёт по PKCE' );
$assert( ! str_contains( json_encode( $status ), 'SECRET' ), 'Статус не выдаёт значений ключей' );

$started = VKT_VKID::start( 'https://example.test/wp-admin/admin.php?page=vk-trends' );
$assert( ! is_wp_error( $started ), 'Подключение стартует' );
parse_str( (string) parse_url( $started['url'], PHP_URL_QUERY ), $query );
$assert( str_starts_with( $started['url'], 'https://id.vk.ru/authorize?' ), 'Используется страница согласия VK ID, а не устаревший oauth.vk.com' );
$assert( 'code' === $query['response_type'] && 'S256' === $query['code_challenge_method'], 'Поток — authorization code с PKCE S256' );
$assert( 'wall photos groups video' === $query['scope'], 'Права передаются через пробел, как требует OAuth 2.1' );
$assert( ! str_contains( $started['url'], 'offline' ), 'Устаревшее право offline не запрашивается' );
$assert( 1 === count( $GLOBALS['transients'] ), 'Состояние сохранено ровно одной записью' );

// Проверка PKCE: challenge должен быть base64url(sha256(verifier)) сохранённого verifier.
$stored = reset( $GLOBALS['transients'] );
$expected = rtrim( strtr( base64_encode( hash( 'sha256', $stored['verifier'], true ) ), '+/', '-_' ), '=' );
$assert( $expected === $query['code_challenge'], 'code_challenge соответствует сохранённому verifier' );
$assert( 43 <= strlen( $stored['verifier'] ) && 128 >= strlen( $stored['verifier'] ), 'Длина verifier укладывается в требования RFC 7636' );
$assert( 7 === $stored['user'], 'Состояние привязано к администратору, который начал подключение' );
$assert( ! str_contains( $started['url'], $stored['verifier'] ), 'Verifier не уходит в адресную строку' );

// Адрес возврата: только свой сайт.
$outside = VKT_VKID::start( 'https://evil.test/steal' );
$outside_state = array_values( $GLOBALS['transients'] );
$assert( str_starts_with( end( $outside_state )['return'], 'https://example.test/' ), 'Возврат на чужой домен заменяется своей страницей' );

// Константа сервисного ключа перекрывает пользовательский токен — отказ должен быть до перехода в VK.
define( 'VKT_ACCESS_TOKEN', 'SERVICE_KEY' );
$blocked = VKT_VKID::start( '' );
$assert( is_wp_error( $blocked ) && str_contains( $blocked->get_error_message(), 'VKT_ACCESS_TOKEN' ), 'При заданной константе подключение отклоняется с объяснением' );
$assert( true === VKT_VKID::public_status()['blocked_by_constant'], 'Интерфейс узнаёт о блокирующей константе заранее' );

// Выданные VK права должны доехать до хранилища токена: без них не понять отказы.
$reflection = new ReflectionMethod( VKT_VKID::class, 'finish' );
$assert( $reflection->isStatic(), 'Возврат обрабатывается статически, без состояния между запросами' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-vkid.php' );
$assert( str_contains( $source, "'scope' => (string) ( \$body['scope'] ?? '' )" ), 'Права из ответа VK ID передаются в сохранение токена' );
$tokens = file_get_contents( dirname( __DIR__ ) . '/includes/class-tokens.php' );
$assert( str_contains( $tokens, "'scope' => preg_replace(" ), 'Права очищаются и сохраняются вместе с ключом' );
$assert( str_contains( $tokens, "'scope' => (string) \$entry['scope']" ), 'Права видны в статусе для интерфейса' );

echo "All $checks offline VK ID checks passed.\n";

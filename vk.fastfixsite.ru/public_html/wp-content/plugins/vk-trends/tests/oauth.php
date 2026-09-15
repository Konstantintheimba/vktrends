<?php
// Проверка классического обмена кода на токен — без WordPress и сети.
define( 'ABSPATH', __DIR__ . '/' );
define( 'YEAR_IN_SECONDS', 31536000 );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
class VKT_Plugin { public static function settings() { return array( 'api_version' => '5.199', 'app_id' => 54770323 ); } }
class VKT_Store { public static function log() {} }
class VKT_API { public static function probe_slot( $slot ) { return array( 'slot' => $slot, 'ok' => true, 'checks' => array() ); } }
class VKT_Tokens {
    public static array $saved = array();
    public static function has( $slot ) { return 'app_secret' === $slot; }
    public static function token( $slot ) { return 'app_secret' === $slot ? 'SECRET_VALUE_0123456789' : ''; }
    public static function save( $slot, $token, $extra = array() ) { self::$saved = compact( 'slot', 'token', 'extra' ); return true; }
}
$GLOBALS['requests'] = array();
function wp_remote_get( $url, $args = array() ) {
    $GLOBALS['requests'][] = $url;
    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
    if ( 'GOODCODE123' !== ( $query['code'] ?? '' ) ) {
        return array( 'response' => array( 'code' => 401 ), 'body' => json_encode( array( 'error' => 'invalid_grant', 'error_description' => 'Code is invalid or expired' ) ) );
    }
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'access_token' => str_repeat( 'z', 60 ), 'expires_in' => 0, 'user_id' => 38975563 ) ) );
}
function wp_remote_retrieve_response_code( $r ) { return (int) $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return (string) $r['body']; }

require dirname( __DIR__ ) . '/includes/class-oauth.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

$assert( VKT_OAuth::configured(), 'Приложение и защищённый ключ распознаны' );
$url = VKT_OAuth::authorize_url();
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
$assert( str_starts_with( $url, 'https://oauth.vk.com/authorize?' ), 'Используется классический OAuth ВКонтакте' );
$assert( 'code' === $query['response_type'], 'Браузер получает код, а не токен — иначе токен привязался бы к его IP' );
$assert( 'https://oauth.vk.com/blank.html' === $query['redirect_uri'], 'Адрес возврата тот, что приложение принимает' );
$assert( 'wall,photos,groups,video' === $query['scope'], 'Запрашиваются классические права' );
$assert( ! str_contains( $url, 'offline' ), 'Отменённое право offline не запрашивается' );
$assert( ! str_contains( $url, 'SECRET_VALUE' ), 'Защищённый ключ в браузер не уходит' );

// Разбор вставленного адреса.
$assert( 'abc12345' === VKT_OAuth::parse_code( 'https://oauth.vk.com/blank.html?code=abc12345' ), 'Код вырезается из адреса' );
$assert( 'abc12345' === VKT_OAuth::parse_code( 'https://oauth.vk.com/blank.html#code=abc12345&state=x' ), 'Код виден и во фрагменте' );
$assert( 'abc12345' === VKT_OAuth::parse_code( '  abc12345 ' ), 'Голый код принимается' );
$assert( '' === VKT_OAuth::parse_code( 'https://oauth.vk.com/blank.html' ), 'Адрес без кода отклоняется' );
$assert( '' === VKT_OAuth::parse_code( '<script>alert(1)</script>' ), 'HTML не проходит' );

// Обмен идёт с сервера и кладёт токен в слот пользователя.
$report = VKT_OAuth::exchange( 'https://oauth.vk.com/blank.html?code=GOODCODE123' );
$assert( ! is_wp_error( $report ), 'Верный код обменивается на токен' );
$assert( 'user' === VKT_Tokens::$saved['slot'], 'Токен попадает в слот пользователя' );
$assert( str_repeat( 'z', 60 ) === VKT_Tokens::$saved['token'], 'Сохранён именно выданный токен' );
$assert( 'wall,photos,groups,video' === VKT_Tokens::$saved['extra']['scope'], 'Права записаны вместе с токеном' );
$assert( str_contains( $GLOBALS['requests'][0], 'client_secret=SECRET_VALUE_0123456789' ), 'Обмен выполняет сервер своим защищённым ключом' );
$assert( str_starts_with( $GLOBALS['requests'][0], 'https://oauth.vk.com/access_token?' ), 'Используется документированный адрес обмена' );

$failed = VKT_OAuth::exchange( 'https://oauth.vk.com/blank.html?code=EXPIRED999' );
$assert( is_wp_error( $failed ), 'Просроченный код отклоняется' );
$assert( str_contains( $failed->get_error_message(), 'Code is invalid or expired' ), 'Причина отказа от VK показана дословно' );
$assert( str_contains( $failed->get_error_message(), 'одноразовый' ), 'Подсказка про одноразовость кода на месте' );
$assert( is_wp_error( VKT_OAuth::exchange( 'мусор' ) ), 'Строка без кода отклоняется до запроса' );

echo "All $checks offline OAuth checks passed.\n";

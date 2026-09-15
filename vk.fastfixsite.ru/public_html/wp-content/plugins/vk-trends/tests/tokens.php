<?php
// Проверка хранилища нескольких ключей: изоляция слотов и выбор ключа под метод.
define( 'ABSPATH', __DIR__ . '/' );
define( 'YEAR_IN_SECONDS', 31536000 );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-value-0123456789'; }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
$GLOBALS['options'] = array();
function get_option( $key, $default = '' ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = true ) { $GLOBALS['options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); return true; }

class VKT_Plugin { public static function settings() { return array( 'api_version' => '5.199', 'community_id' => 241464933 ); } }

require dirname( __DIR__ ) . '/includes/class-tokens.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

$service = str_repeat( 'a', 40 );
$user = 'vk1.a.' . str_repeat( 'b', 60 );
$community = 'vk1.a.' . str_repeat( 'c', 60 );

$assert( 4 === count( VKT_Tokens::definitions() ), 'Слотов четыре: сервисный, пользовательский, защищённый ключ и сообщества' );
$assert( isset( VKT_Tokens::definitions()['app_secret'] ), 'Защищённый ключ хранится тем же шифром, что и остальные' );
$assert( ! VKT_Tokens::has( 'user' ), 'Пустое хранилище не выдаёт ключей' );

// Ключи не вытесняют друг друга — это и было причиной поломки сбора данных.
$assert( true === VKT_Tokens::save( 'service', $service ), 'Сервисный ключ сохраняется' );
$assert( true === VKT_Tokens::save( 'user', $user, array( 'scope' => 'wall photos', 'expires_in' => 86400 ) ), 'Пользовательский токен сохраняется' );
$assert( true === VKT_Tokens::save( 'community', $community ), 'Ключ сообщества сохраняется' );
$assert( $service === VKT_Tokens::token( 'service' ), 'Сервисный ключ на месте после записи остальных' );
$assert( $user === VKT_Tokens::token( 'user' ), 'Пользовательский токен на месте' );
$assert( $community === VKT_Tokens::token( 'community' ), 'Ключ сообщества на месте' );

// Хранится зашифрованно.
$assert( ! str_contains( (string) get_option( 'vkt_tokens' ), $user ), 'В базе ключ лежит только в шифре' );

// Наружу отдаётся безопасная сводка.
$status = VKT_Tokens::status();
$json = json_encode( $status );
$assert( ! str_contains( $json, $user ) && ! str_contains( $json, $service ) && ! str_contains( $json, $community ), 'Статус не раскрывает ни один ключ' );
$user_status = array_values( array_filter( $status, static fn( $row ) => 'user' === $row['slot'] ) )[0];
$assert( 'wall photos' === $user_status['scope'], 'Выданные права видны в статусе' );
$assert( $user_status['expires_in'] > 86000, 'Срок жизни считается от момента сохранения' );
$assert( str_starts_with( $user_status['preview'], 'vk1.a.' ), 'Огрызок позволяет узнать ключ' );

// Ошибка закрепляется за слотом и держится до исправления.
VKT_Tokens::note( 'user', 'Нет права photos' );
$assert( 'Нет права photos' === VKT_Tokens::error( 'user' )['message'], 'Ошибка слота сохраняется' );
$assert( null === VKT_Tokens::error( 'service' ), 'Ошибка одного слота не липнет к другому' );
VKT_Tokens::save( 'user', $user );
$assert( null === VKT_Tokens::error( 'user' ), 'Новый ключ сбрасывает прошлую ошибку' );

// Удаление затрагивает только свой слот.
VKT_Tokens::forget( 'user' );
$assert( ! VKT_Tokens::has( 'user' ), 'Пользовательский токен удалён' );
$assert( VKT_Tokens::has( 'service' ) && VKT_Tokens::has( 'community' ), 'Остальные ключи не пострадали' );

// Мусор не принимается.
$assert( is_wp_error( VKT_Tokens::save( 'user', 'коротко' ) ), 'Слишком короткий ключ отклоняется' );
$assert( is_wp_error( VKT_Tokens::save( 'user', 'https://example.com/#access_token=x' ) ), 'Сырой адрес в хранилище не попадает' );
$assert( is_wp_error( VKT_Tokens::save( 'нет-такого', $user ) ), 'Неизвестный слот отклоняется' );
$assert( is_wp_error( VKT_Tokens::save( 'user', $user, array( 'refresh_token' => 'r' ) ) ), 'Неполный комплект автообновления отклоняется' );

// Перенос единственного ключа старого формата.
$GLOBALS['options'] = array();
$reflection = new ReflectionMethod( VKT_Tokens::class, 'encrypt' );
$reflection->setAccessible( true );
$legacy = $reflection->invoke( null, wp_json_encode( array( 'kind' => 'user', 'access_token' => $user ) ) );
update_option( 'vkt_token', $legacy, false );
$assert( $user === VKT_Tokens::token( 'user' ), 'Старый единственный ключ переезжает в свой слот' );
$assert( '' === (string) get_option( 'vkt_token', '' ), 'После переноса старая запись удаляется' );

echo "All $checks offline token-store checks passed.\n";

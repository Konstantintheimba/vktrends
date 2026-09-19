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
// Кабинеты: 1 — хозяин сайта, остальные — участники.
class VKT_Account {
    public static int $id = 1;
    public static function id() { return self::$id; }
    public static function is_owner() { return 1 === self::$id; }
}
$GLOBALS['usermeta'] = array();
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['usermeta'][ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { $GLOBALS['usermeta'][ $id ][ $key ] = $value; return true; }

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

// Личные ключи у каждого свои, общие — одни на сайт.
$decrypt = new ReflectionMethod( VKT_Tokens::class, 'decrypt' );
$decrypt->setAccessible( true );
VKT_Tokens::save( 'user', $user );
$site_map = json_decode( $decrypt->invoke( null, get_option( 'vkt_tokens' ) ), true );
$assert( isset( $site_map['service'] ) && ! isset( $site_map['user'] ) && ! isset( $site_map['community'] ), 'В опции сайта лежат только общие ключи' );
$assert( ! str_contains( (string) $GLOBALS['usermeta'][1]['vkt_tokens'], $user ), 'Личный ключ в usermeta тоже в шифре' );
VKT_Account::$id = 2;
$assert( ! VKT_Tokens::has( 'user' ) && ! VKT_Tokens::has( 'community' ), 'Второй кабинет не видит чужих личных ключей' );
$assert( $service === VKT_Tokens::token( 'service' ), 'Сервисный ключ общий для всех кабинетов' );
$other = 'vk1.a.' . str_repeat( 'd', 60 );
VKT_Tokens::save( 'user', $other );
VKT_Tokens::note( 'user', 'Чужая ошибка' );
VKT_Account::$id = 1;
$assert( $user === VKT_Tokens::token( 'user' ), 'Токен второго кабинета не затёр первый' );
$assert( null === VKT_Tokens::error( 'user' ), 'Ошибка чужого ключа не видна' );
$assert( 'site' === VKT_Tokens::scope( 'app_secret' ) && 'user' === VKT_Tokens::scope( 'community' ), 'У каждого слота указано, чей он' );
$statuses = array_column( VKT_Tokens::status(), 'area', 'slot' );
$assert( 'user' === $statuses['user'] && 'site' === $statuses['service'], 'Сводка сообщает, общий слот или личный' );
$assert( 'wall photos' !== $statuses['user'], 'Поле принадлежности не путается с правами токена' );
VKT_Account::$id = 0;
$assert( is_wp_error( VKT_Tokens::save( 'community', $community ) ), 'Без пользователя личный ключ не сохраняется' );
VKT_Account::$id = 1;

// Переезд 0.22.0: личные слоты из общей опции уходят хозяину сайта.
$GLOBALS['options'] = array();
$GLOBALS['usermeta'] = array();
$encrypt = new ReflectionMethod( VKT_Tokens::class, 'encrypt' );
$encrypt->setAccessible( true );
update_option( 'vkt_tokens', $encrypt->invoke( null, wp_json_encode( array( 'service' => array( 'access_token' => $service ), 'user' => array( 'access_token' => $user ), 'community' => array( 'access_token' => $community ) ) ) ) );
update_option( 'vkt_token_errors', array( 'user' => array( 'message' => 'Старая ошибка', 'at' => '2026-09-01 00:00:00' ), 'service' => array( 'message' => 'Общая', 'at' => '2026-09-01 00:00:00' ) ) );
VKT_Tokens::migrate_to_owner( 1 );
$site_map = json_decode( $decrypt->invoke( null, get_option( 'vkt_tokens' ) ), true );
$assert( array( 'service' ) === array_keys( $site_map ), 'После переезда в опции только общий ключ' );
$assert( $user === VKT_Tokens::token( 'user' ) && $community === VKT_Tokens::token( 'community' ), 'Хозяин сохранил свой токен и ключ сообщества' );
$assert( 'Старая ошибка' === VKT_Tokens::error( 'user' )['message'] && 'Общая' === VKT_Tokens::error( 'service' )['message'], 'Ошибки слотов переехали вместе с ключами' );
VKT_Account::$id = 3;
$assert( ! VKT_Tokens::has( 'user' ), 'Участнику ключи хозяина не достались' );
VKT_Account::$id = 1;
VKT_Tokens::migrate_to_owner( 1 );
$assert( $user === VKT_Tokens::token( 'user' ), 'Повторный переезд ничего не ломает' );

// Ключ сообщества из wp-config.php — только хозяину сайта.
define( 'VKT_COMMUNITY_ACCESS_TOKEN', 'vk1.a.' . str_repeat( 'e', 60 ) );
$assert( VKT_COMMUNITY_ACCESS_TOKEN === VKT_Tokens::token( 'community' ) && VKT_Tokens::locked( 'community' ), 'Хозяин публикует ключом из wp-config.php' );
VKT_Account::$id = 2;
$assert( '' === VKT_Tokens::token( 'community' ) && ! VKT_Tokens::locked( 'community' ), 'Чужой кабинет константу не получает и может сохранить свой ключ' );
VKT_Account::$id = 1;

// Перенос единственного ключа старого формата: сначала в опцию, при обновлении — хозяину.
$GLOBALS['options'] = array();
$GLOBALS['usermeta'] = array();
$reflection = new ReflectionMethod( VKT_Tokens::class, 'encrypt' );
$reflection->setAccessible( true );
$legacy = $reflection->invoke( null, wp_json_encode( array( 'kind' => 'user', 'access_token' => $user ) ) );
update_option( 'vkt_token', $legacy, false );
VKT_Tokens::migrate_to_owner( 1 );
$assert( $user === VKT_Tokens::token( 'user' ), 'Старый единственный ключ переезжает в свой слот' );
$assert( '' === (string) get_option( 'vkt_token', '' ), 'После переноса старая запись удаляется' );

echo "All $checks offline token-store checks passed.\n";

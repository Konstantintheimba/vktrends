<?php
// Изолированная проверка безопасного статуса и чтения тестового сообщества.
define( 'ABSPATH', __DIR__ . '/' );
define( 'VKT_COMMUNITY_ID', 123456 );
define( 'VKT_COMMUNITY_ACCESS_TOKEN', 'COMMUNITY_SECRET_TOKEN' );
define( 'VKT_CALLBACK_CONFIRMATION', '12345678' );
define( 'VKT_CALLBACK_SECRET', 'CALLBACK_SECRET' );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_data() { return $this->data; }
    public function get_error_message() { return $this->message; }
}
class VKT_Plugin {
    public static function settings() { return array( 'api_version' => '5.199' ); }
}
class VKT_Store {
    public static function log() {}
    public static function table( $name ) { return 'wp_vkt_' . $name; }
}
// Запоминает запросы к таблице групп: добавление ключа должно сразу завести группу в кабинете.
class VKT_Test_WPDB {
    public array $queries = array();
    public array $updates = array();
    public function prepare( $sql, ...$args ) { foreach ( $args as $arg ) { $sql = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 ); } return $sql; }
    public function query( $sql ) { $this->queries[] = $sql; return 1; }
    public function get_row( $sql, $mode = null ) { return str_contains( $sql, 'group_id=555' ) ? array( 'name' => 'Вторая группа', 'screen_name' => 'second', 'photo' => '', 'can_post' => 1 ) : null; }
    public function update( $table, $data, $where ) { $this->updates[] = array( $table, $data, $where ); return 1; }
}
const ARRAY_A = 'ARRAY_A';
$GLOBALS['wpdb'] = new VKT_Test_WPDB();
// Константы wp-config.php принадлежат хозяину сайта.
class VKT_Account {
    public static bool $owner = true;
    public static array $meta = array();
    public static function is_owner() { return self::$owner; }
    public static function get( $key ) { return self::$meta[ $key ] ?? ''; }
    public static function owner() { return 1; }
    public static function id() { return 1; }
    public static function act_as( $user_id, callable $callback ) { return $callback(); }
}
class VKT_Tokens {
    public static array $notes = array();
    public static function token( $slot ) { return 'community' === $slot ? VKT_COMMUNITY_ACCESS_TOKEN : ''; }
    public static function error( $slot ) { return self::$notes[ $slot ] ?? null; }
    public static array $groups = array();
    public static function group_keys() { return self::$groups; }
    public static function save_group_key( $group_id, $token ) { self::$groups[ (int) $group_id ] = array( 'access_token' => $token, 'saved_at' => time() ); return true; }
    public static function forget_group_key( $group_id ) { unset( self::$groups[ (int) $group_id ] ); return true; }
    public static function note( $slot, $message, $dead = false ) {
        if ( '' === $message ) { unset( self::$notes[ $slot ] ); return; }
        self::$notes[ $slot ] = array( 'message' => $message, 'dead' => $dead );
    }
}
class VKT_Health {
    const DEAD_CODES = array( 5, 1117 );
    const RIGHTS_CODES = array( 7, 27, 28 );
    public static function fix_for( $slot, $code ) { return in_array( (int) $code, array( 5, 1117, 7, 27, 28 ), true ) ? array( 'view' => 'posting', 'label' => 'fix' ) : null; }
}
function absint( $value ) { return abs( (int) $value ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value, $protocols = array() ) { return $value; }
function wp_remote_retrieve_response_code( $response ) { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return (string) $response['body']; }
function wp_remote_post( $url, $args ) {
    $token = (string) ( $args['body']['access_token'] ?? '' );
    $GLOBALS['vkt_last_token'] = $token;
    // Ключ второй группы: без group_id VK отвечает своей группой, прав на стену у «READONLY» нет.
    if ( str_starts_with( $token, 'SECOND_GROUP_KEY' ) || str_starts_with( $token, 'READONLY_GROUP_KEY' ) ) {
        if ( str_ends_with( $url, '/groups.getTokenPermissions' ) ) {
            $body = array( 'response' => array( 'permissions' => str_starts_with( $token, 'SECOND' ) ? array( array( 'name' => 'wall' ) ) : array( array( 'name' => 'photos' ) ) ) );
        } elseif ( str_ends_with( $url, '/groups.getById' ) ) {
            $GLOBALS['vkt_getbyid'] = $args['body'];
            $body = array( 'response' => array( 'groups' => array( array( 'id' => str_starts_with( $token, 'SECOND' ) ? 555 : 666, 'name' => 'Вторая группа', 'screen_name' => 'second' ) ) ) );
        } else {
            $body = array( 'response' => array( 'post_id' => 43, 'comment_id' => 78 ) );
        }
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ) );
    }
    if ( str_starts_with( $token, 'GARBAGE' ) ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'error' => array( 'error_code' => 5, 'error_msg' => 'invalid access_token' ) ) ) );
    }
    if ( ! hash_equals( VKT_COMMUNITY_ACCESS_TOKEN, $token ) ) {
        return new WP_Error( 'token', 'Token not supplied server-side' );
    }
    if ( str_ends_with( $url, '/wall.post' ) ) {
        $body = array( 'response' => array( 'post_id' => 42 ) );
    } elseif ( ! empty( $GLOBALS['vkt_dead'] ) ) {
        $body = array( 'error' => array( 'error_code' => 5, 'error_msg' => 'User authorization failed: invalid access_token' ) );
    } elseif ( str_ends_with( $url, '/wall.createComment' ) ) {
        $GLOBALS['vkt_comment_body'] = $args['body'];
        $body = array( 'response' => array( 'comment_id' => 77 ) );
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
$assert( str_contains( $status['callback_url'], 'vk-trends/v1/callback' ) && ! str_contains( $status['callback_url'], 'wp-admin' ), 'Callback endpoint URL generated' );
$checked = VKT_Community::check();
$assert( ! is_wp_error( $checked ) && 'Тестовая группа' === $checked['name'], 'Community identity checked through VK methods' );
$assert( array( 'wall', 'manage' ) === $checked['permissions'], 'Only permission names returned' );
$assert( 'test.group' === $checked['screen_name'], 'Screen name keeps a valid dot' );
$published = VKT_Community::publish( array( 'owner_id' => -VKT_COMMUNITY_ID, 'from_group' => 1, 'message' => 'Тест', 'guid' => 'test-guid' ) );
$assert( ! is_wp_error( $published ) && 42 === $published['response']['post_id'], 'Community key publishes to its own wall' );
$assert( is_wp_error( VKT_Community::publish( array( 'owner_id' => -999, 'from_group' => 1, 'message' => 'Чужая группа' ) ) ), 'Community key cannot target another wall' );
$commented = VKT_Community::comment( array( 'owner_id' => -VKT_COMMUNITY_ID, 'post_id' => 5, 'message' => 'Спасибо!', 'reply_to_comment' => 9, 'guid' => 'reply-guid' ) );
$assert( ! is_wp_error( $commented ) && 77 === $commented['response']['comment_id'], 'Ключ сообщества отвечает на комментарий на своей стене' );
$assert( VKT_COMMUNITY_ID === $GLOBALS['vkt_comment_body']['from_group'] && 9 === $GLOBALS['vkt_comment_body']['reply_to_comment'], 'Ответ идёт от имени группы и в ветку нужного комментария' );
$assert( is_wp_error( VKT_Community::comment( array( 'owner_id' => -999, 'post_id' => 5, 'message' => 'Чужая стена' ) ) ), 'На чужой стене ключ сообщества не отвечает' );
$assert( is_wp_error( VKT_Community::comment( array( 'owner_id' => -VKT_COMMUNITY_ID, 'post_id' => 5, 'message' => 'x', 'attachments' => 'photo1_2' ) ) ), 'Вложения к ответу ключом сообщества не пропускаются' );
$GLOBALS['vkt_dead'] = true;
$refused = VKT_Community::comment( array( 'owner_id' => -VKT_COMMUNITY_ID, 'post_id' => 5, 'message' => 'x', 'reply_to_comment' => 9, 'guid' => 'g' ) );
$assert( is_wp_error( $refused ) && true === $refused->data['auth'] && 'posting' === $refused->data['fix']['view'], 'Отказ ключа помечен и несёт ссылку, где его заменить' );
$assert( true === VKT_Tokens::error( 'community' )['dead'] && ! str_contains( VKT_Tokens::error( 'community' )['message'], VKT_COMMUNITY_ACCESS_TOKEN ), 'Отказ закреплён за ключом сообщества без самого ключа' );
$GLOBALS['vkt_dead'] = false;
VKT_Community::check();
$assert( null === VKT_Tokens::error( 'community' ), 'Удачный запрос снимает прошлую ошибку ключа' );

// Несколько групп: у каждой свой ключ, группа определяется по ключу.
$added = VKT_Community::add_key( 'SECOND_GROUP_KEY_' . str_repeat( 'x', 20 ) );
$assert( ! is_wp_error( $added ) && 555 === $added['group_id'] && true === $added['can_post'], 'Группа добавлена по одному ключу — VK сам сказал, чья она' );
$assert( ! isset( $GLOBALS['vkt_getbyid']['group_id'] ), 'Без подсказки ID группы не передаётся: ключ отвечает своей группой' );
$assert( (bool) array_filter( $GLOBALS['wpdb']->queries, static fn( $sql ) => str_contains( $sql, 'INSERT INTO wp_vkt_publishing_groups' ) && str_contains( $sql, '555' ) ), 'Группа сразу заведена в кабинете' );
$assert( VKT_Community::has_key( 555 ) && VKT_Community::has_key( VKT_COMMUNITY_ID ) && 2 === count( VKT_Community::keys() ), 'Прежний ключ и новый работают вместе' );
$second = VKT_Community::publish( array( 'owner_id' => -555, 'from_group' => 1, 'message' => 'Во вторую' ) );
$assert( ! is_wp_error( $second ) && str_starts_with( $GLOBALS['vkt_last_token'], 'SECOND_GROUP_KEY' ), 'Запись во вторую группу уходит её ключом' );
VKT_Community::publish( array( 'owner_id' => -VKT_COMMUNITY_ID, 'from_group' => 1, 'message' => 'В первую' ) );
$assert( VKT_COMMUNITY_ACCESS_TOKEN === $GLOBALS['vkt_last_token'], 'Запись в первую группу — её ключом' );
$assert( is_wp_error( VKT_Community::publish( array( 'owner_id' => -777, 'from_group' => 1, 'message' => 'Без ключа' ) ) ), 'В группу без ключа ключом не пишем' );
$hinted = VKT_Community::add_key( 'SECOND_GROUP_KEY_' . str_repeat( 'x', 20 ), 'https://vk.com/club999' );
$assert( is_wp_error( $hinted ), 'Ключ чужой группы не выдаётся за указанную' );
$readonly = VKT_Community::add_key( 'READONLY_GROUP_KEY_' . str_repeat( 'x', 20 ) );
$assert( ! is_wp_error( $readonly ) && false === $readonly['can_post'] && str_contains( $readonly['warning'], 'Стена' ), 'Ключ без права на стену добавлен с предупреждением' );
$garbage = VKT_Community::add_key( 'GARBAGE_' . str_repeat( 'x', 20 ) );
$assert( is_wp_error( $garbage ) && str_contains( $garbage->message, 'Работа с API' ) && ! isset( VKT_Tokens::$notes['community'] ), 'Негодный ключ отклонён с подсказкой и не портит ошибки сохранённых' );
$list = VKT_Community::key_list();
$assert( 3 === count( $list ) && ! str_contains( json_encode( $list ), 'SECOND_GROUP_KEY' ), 'Список ключей для интерфейса без самих ключей' );
$assert( is_wp_error( VKT_Community::forget_key( VKT_COMMUNITY_ID ) ), 'Ключ из wp-config.php убирается там же' );
$assert( ! is_wp_error( VKT_Community::forget_key( 555 ) ) && ! VKT_Community::has_key( 555 ), 'Ключ группы убран' );

VKT_Account::$owner = false;
$assert( 0 === VKT_Community::group_id(), 'Чужой кабинет не получает сообщество из wp-config.php' );
VKT_Account::$meta['community_id'] = '777';
$assert( 777 === VKT_Community::group_id(), 'У кабинета своё сообщество из личных настроек' );
$assert( ! VKT_Community::callback_configured(), 'Callback API — только у хозяина сайта' );
VKT_Account::$owner = true;
echo "All $checks offline community checks passed.\n";

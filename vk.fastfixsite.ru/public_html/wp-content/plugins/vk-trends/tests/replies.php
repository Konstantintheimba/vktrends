<?php
// Очередь ответов на комментарии: темп отправки, дубли, выбор ключа, разбор
// ответа Grok. Без WordPress и сети; SQL очереди выполняется по-настоящему —
// в SQLite в памяти, чтобы проверялись сами запросы, а не заглушка.
define( 'ABSPATH', __DIR__ . '/' );
const ARRAY_A = 'ARRAY_A';
const MINUTE_IN_SECONDS = 60;
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['vkt_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['vkt_transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['vkt_transients'][ $key ] ); return true; }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $format, $stamp = null ) { return gmdate( $format, $stamp ?? time() ); } }

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function esc_url_raw( $url, $protocols = array() ) { return str_starts_with( (string) $url, 'https://' ) ? $url : ''; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_rand( $min, $max ) { return $GLOBALS['vkt_rand'] ?? $max; }
function update_option( $name, $value, $autoload = true ) { $GLOBALS['vkt_options'][ $name ] = $value; return true; }
function get_option( $name, $default = false ) { return $GLOBALS['vkt_options'][ $name ] ?? $default; }

class VKT_Store {
    public static function table( $name ) { return 'wp_vkt_' . $name; }
    public static function lock( $name, $seconds ) { return true; }
    public static function unlock( $name ) {}
    public static function log() {}
}
// Кабинет автора: cron выполняет ответ от его имени.
class VKT_Account {
    public static int $id = 5;
    public static array $switched = array();
    public static bool $active = true;
    public static int $batch = 300;
    public static function id() { return self::$id; }
    public static function limit( $key ) { return self::$batch; }
    public static function can_use() { return self::$active; }
    public static function act_as( $user_id, callable $callback ) {
        self::$switched[] = $user_id;
        $previous = self::$id;
        self::$id = $user_id;
        try { return $callback(); } finally { self::$id = $previous; }
    }
    public static function ai_quota() { return null; }
}
class VKT_Tokens {
    public static bool $user = true;
    public static function has( $slot ) { return 'user' === $slot && self::$user; }
}
// Ключ сообщества есть только у группы 100.
class VKT_Community {
    public static array $sent = array();
    public static $result = null;
    public static function configured() { return true; }
    public static function group_id() { return 100; }
    public static function has_key( $group_id ) { return 100 === (int) $group_id; }
    public static function comment( $params ) {
        self::$sent[] = $params;
        return self::$result ?? array( 'response' => array( 'comment_id' => 900 + count( self::$sent ) ) );
    }
}
class VKT_API {
    public static array $calls = array();
    public static array $read = array();
    public static $result = null;
    public static function mode() { return 'service'; }
    public static function publishing_request( $method, $params ) {
        self::$calls[] = array( $method, $params );
        return self::$result ?? array( 'response' => array( 'comment_id' => 700 + count( self::$calls ) ) );
    }
    public static function request( $method, $params, $context ) {
        self::$read[] = array( $method, $params, $context );
        return $GLOBALS['vkt_read_response'];
    }
}
class VKT_AI {
    public static function public_status() { return array( 'configured' => true ); }
}

// wpdb поверх SQLite: prepare подставляет значения, остальное — как в WordPress.
class VKT_SQLite_WPDB {
    public PDO $pdo;
    public int $insert_id = 0;
    public function __construct() {
        $this->pdo = new PDO( 'sqlite::memory:' );
        $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $this->pdo->exec( 'CREATE TABLE wp_vkt_publishing_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, group_id INT, name TEXT, screen_name TEXT, photo TEXT, enabled INT, can_post INT, callback_code TEXT DEFAULT \'\', callback_status TEXT DEFAULT \'\', callback_at TEXT)' );
        $this->pdo->exec( "CREATE TABLE wp_vkt_comment_replies (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, group_id INT, post_id INT, comment_id INT, author_id INT DEFAULT 0, author_name TEXT DEFAULT '', comment_text TEXT, message TEXT, origin TEXT DEFAULT 'manual', status TEXT DEFAULT 'pending', attempts INT DEFAULT 0, available_at TEXT, vk_comment_id INT, guid TEXT, error TEXT DEFAULT '', sent_at TEXT, created_at TEXT, updated_at TEXT)" );
    }
    public function prepare( $sql, ...$args ) {
        foreach ( $args as $arg ) {
            $value = is_int( $arg ) ? (string) $arg : $this->pdo->quote( (string) $arg );
            $sql = preg_replace( '/%[sd]/', str_replace( '$', '\$', $value ), $sql, 1 );
        }
        return $sql;
    }
    public function query( $sql ) {
        if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) { return 0; }
        return $this->pdo->exec( $sql );
    }
    public function get_results( $sql, $mode = null ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
    public function get_row( $sql, $mode = null ) { $rows = $this->get_results( $sql ); return $rows[0] ?? null; }
    public function get_var( $sql ) { $value = $this->pdo->query( $sql )->fetchColumn(); return false === $value ? null : $value; }
    public function insert( $table, $data ) {
        $statement = $this->pdo->prepare( "INSERT INTO $table (" . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')' );
        $statement->execute( array_values( $data ) );
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return 1;
    }
    public function update( $table, $data, $where ) {
        $set = implode( ',', array_map( static fn( $key ) => "$key=?", array_keys( $data ) ) );
        $cond = implode( ' AND ', array_map( static fn( $key ) => "$key=?", array_keys( $where ) ) );
        $statement = $this->pdo->prepare( "UPDATE $table SET $set WHERE $cond" );
        $statement->execute( array_merge( array_values( $data ), array_values( $where ) ) );
        return $statement->rowCount();
    }
    public function delete( $table, $where ) {
        $statement = $this->pdo->prepare( "DELETE FROM $table WHERE " . implode( ' AND ', array_map( static fn( $key ) => "$key=?", array_keys( $where ) ) ) );
        $statement->execute( array_values( $where ) );
        return $statement->rowCount();
    }
}
$wpdb = new VKT_SQLite_WPDB();
$GLOBALS['wpdb'] = $wpdb;
// Кабинет 5 ведёт группы 100 (есть ключ сообщества) и 200 (только токен).
// Группа 300 выключена, группа 400 принадлежит кабинету 6.
$wpdb->insert( 'wp_vkt_publishing_groups', array( 'user_id' => 5, 'group_id' => 100, 'name' => 'Своя', 'screen_name' => 'own', 'photo' => '', 'enabled' => 1, 'can_post' => 1 ) );
$wpdb->insert( 'wp_vkt_publishing_groups', array( 'user_id' => 5, 'group_id' => 200, 'name' => 'Вторая', 'screen_name' => 'second', 'photo' => '', 'enabled' => 1, 'can_post' => 1 ) );
$wpdb->insert( 'wp_vkt_publishing_groups', array( 'user_id' => 5, 'group_id' => 300, 'name' => 'Выключена', 'screen_name' => 'off', 'photo' => '', 'enabled' => 0, 'can_post' => 1 ) );
$wpdb->insert( 'wp_vkt_publishing_groups', array( 'user_id' => 6, 'group_id' => 400, 'name' => 'Чужая', 'screen_name' => 'foreign', 'photo' => '', 'enabled' => 1, 'can_post' => 1 ) );

require dirname( __DIR__ ) . '/includes/class-replies.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};
$rows = static fn( $where = '1=1' ) => $wpdb->get_results( "SELECT * FROM wp_vkt_comment_replies WHERE $where ORDER BY id" );
$item = static fn( $comment, $message = 'Спасибо!', $post = 10 ) => array( 'post_id' => $post, 'comment_id' => $comment, 'author_id' => 42, 'author' => 'Анна', 'comment_text' => 'Вопрос ' . $comment, 'message' => $message );
// Сдвигает всю очередь в прошлое, чтобы её можно было разобрать сейчас.
$ripen = static function () use ( $wpdb ) {
    $wpdb->query( "UPDATE wp_vkt_comment_replies SET available_at='2000-01-01 00:00:00' WHERE status='pending'" );
};
$age_sent = static function () use ( $wpdb ) {
    $wpdb->query( "UPDATE wp_vkt_comment_replies SET sent_at='2000-01-01 00:00:00' WHERE status='sent'" );
};

$schema = VKT_Replies::schema();
$assert( isset( $schema['comment_replies'] ) && str_contains( $schema['comment_replies'], 'KEY ready (status,available_at)' ), 'Очередь ответов — своя таблица с индексом готовности' );
$assert( str_contains( $schema['comment_replies'], 'guid varchar(64)' ), 'У ответа есть guid для безопасного повтора' );

// Доступ: только свои включённые группы.
$assert( is_wp_error( VKT_Replies::enqueue( array( 'group_id' => 400, 'items' => array( $item( 1 ) ) ) ) ), 'В группу чужого кабинета ответ не ставится' );
$assert( is_wp_error( VKT_Replies::enqueue( array( 'group_id' => 300, 'items' => array( $item( 1 ) ) ) ) ), 'В выключенную группу ответ не ставится' );
$assert( is_wp_error( VKT_Replies::posts( 400 ) ), 'Чужую стену через вкладку не прочитать' );
$too_many = array_map( $item, range( 1, VKT_Replies::BATCH_CEILING + 1 ) );
$assert( is_wp_error( VKT_Replies::enqueue( array( 'group_id' => 100, 'items' => $too_many ) ) ), 'Один запрос ограничен ' . VKT_Replies::BATCH_CEILING . ' ответами' );

// Интервал: без разброса ответы идут ровно через заданные минуты.
$before = time();
$batch = VKT_Replies::enqueue( array( 'group_id' => 100, 'interval' => 3, 'jitter' => false, 'items' => array( $item( 1 ), array_merge( $item( 2 ), array( 'origin' => 'ai' ) ), $item( 3 ) ) ) );
$assert( ! is_wp_error( $batch ) && 3 === $batch['created'], 'Пачка из трёх ответов встала в очередь' );
$times = array_map( static fn( $row ) => strtotime( $row['available_at'] . ' UTC' ), $rows( 'group_id=100' ) );
$assert( $times[0] >= $before && $times[0] <= time(), 'Первый ответ пачки — сразу' );
$assert( 180 === $times[1] - $times[0] && 180 === $times[2] - $times[1], 'Между ответами в группу ровно интервал' );
$stored = $rows( 'group_id=100' );
$assert( 'manual' === $stored[0]['origin'] && 'ai' === $stored[1]['origin'], 'Источник текста хранится у каждого ответа' );
$assert( 32 === strlen( $stored[0]['guid'] ) && $stored[0]['guid'] !== $stored[1]['guid'], 'У каждого ответа свой guid' );

// Дубли: на комментарий из очереди второй ответ не ставится.
$again = VKT_Replies::enqueue( array( 'group_id' => 100, 'interval' => 3, 'items' => array( $item( 1 ) ) ) );
$assert( is_wp_error( $again ), 'Повторный ответ на тот же комментарий отклонён' );
$mixed = VKT_Replies::enqueue( array( 'group_id' => 100, 'interval' => 3, 'jitter' => true, 'items' => array( $item( 2 ), $item( 4 ), $item( 5, '   ' ) ) ) );
$assert( 1 === $mixed['created'] && 2 === count( $mixed['skipped'] ), 'Дубль и пустой ответ пропущены, остальное встало' );
// Новая пачка встаёт после уже ожидающих ответов группы, а не параллельно им.
$last = $rows( 'group_id=100 AND comment_id=4' )[0];
$assert( strtotime( $last['available_at'] . ' UTC' ) === $times[2] + 180, 'Следующая пачка продолжает расписание группы' );
$long = VKT_Replies::enqueue( array( 'group_id' => 100, 'items' => array( $item( 6, str_repeat( 'а', VKT_Replies::MAX_LENGTH + 1 ) ) ) ) );
$assert( is_wp_error( $long ), 'Слишком длинный ответ отклонён' );

// Разброс: случайная добавка не больше половины интервала.
$GLOBALS['vkt_rand'] = 90;
$jittered = VKT_Replies::enqueue( array( 'group_id' => 200, 'interval' => 3, 'jitter' => true, 'items' => array( $item( 11 ), $item( 12 ) ) ) );
$gap = strtotime( $jittered['replies'][1]['available_at'] . ' UTC' ) - strtotime( $jittered['replies'][0]['available_at'] . ' UTC' );
$assert( 180 + 90 === $gap, 'Разброс добавляется к интервалу' );
unset( $GLOBALS['vkt_rand'] );

// Разбор очереди: по одному ответу в группу за проход.
$ripen();
VKT_Account::$id = 1;
$run = VKT_Replies::run_due( 5 );
$assert( 2 === $run['processed'], 'За проход ушло по одному ответу в каждую из двух групп' );
$assert( array( 5, 5 ) === VKT_Account::$switched, 'Ответы отправлены от имени автора' );
VKT_Account::$id = 5;
$assert( 1 === count( VKT_Community::$sent ) && -100 === VKT_Community::$sent[0]['owner_id'] && 1 === VKT_Community::$sent[0]['reply_to_comment'], 'Группа с ключом сообщества отвечает им, в ветку комментария' );
$assert( 1 === count( VKT_API::$calls ) && 'wall.createComment' === VKT_API::$calls[0][0] && 200 === VKT_API::$calls[0][1]['from_group'], 'Без ключа сообщества — токен пользователя от имени группы' );
$sent = $rows( "status='sent'" );
$assert( 2 === count( $sent ) && 901 === (int) $sent[0]['vk_comment_id'] && null !== $sent[0]['sent_at'], 'Отправленный ответ помнит ID комментария VK' );
// Сразу после отправки группа выдерживает паузу, даже если ответ созрел.
$run = VKT_Replies::run_due( 5 );
$assert( 0 === $run['processed'] && 2 === $run['waiting'], 'Раньше минуты в ту же группу ответ не уходит' );
$age_sent();
$run = VKT_Replies::run_due( 5 );
$assert( 2 === $run['processed'], 'После паузы очередь продолжается' );

// Одиночный ответ по кнопке уходит сразу, но паузу группы тоже соблюдает.
$age_sent();
$now = VKT_Replies::reply_now( array( 'group_id' => 200, 'items' => array( $item( 21 ) ) ) );
$assert( 'sent' === $now['status'], 'Одиночный ответ отправлен сразу' );
$waiting = VKT_Replies::reply_now( array( 'group_id' => 200, 'items' => array( $item( 22 ) ) ) );
$assert( 'pending' === $waiting['status'] && str_contains( $waiting['message'], 'паузу' ), 'Второй ответ подряд ждёт паузу группы' );

// Ошибки: временная — повтор с отсрочкой, постоянная — стоп.
$age_sent();
$ripen();
VKT_Community::$result = new WP_Error( 'vk_9', 'Flood control', array( 'retryable' => true ) );
VKT_API::$result = new WP_Error( 'vk_15', 'Доступ запрещён', array( 'retryable' => false ) );
VKT_Replies::run_due( 5 );
$flood = $rows( "group_id=100 AND attempts=1 AND status='pending'" );
$denied = $rows( "group_id=200 AND status='failed'" );
$assert( 1 === count( $flood ) && strtotime( $flood[0]['available_at'] . ' UTC' ) > time() + 60, 'Флуд-контроль — повтор позже' );
$assert( 1 === count( $denied ) && 'Доступ запрещён' === $denied[0]['error'], 'Отказ VK — ошибка с причиной' );
VKT_Community::$result = null;
VKT_API::$result = null;
$assert( ! is_wp_error( VKT_Replies::retry( $denied[0]['id'] ) ), 'Неудачный ответ можно повторить' );
VKT_Account::$id = 6;
$assert( is_wp_error( VKT_Replies::retry( $denied[0]['id'] ) ), 'Чужой ответ повторить нельзя' );
$assert( 0 === VKT_Replies::cancel( array( $denied[0]['id'] ) )['cancelled'], 'Чужой ответ отменить нельзя' );
VKT_Account::$id = 5;
$cancelled = VKT_Replies::cancel( array(), true );
$assert( $cancelled['cancelled'] > 0 && 0 === count( $rows( "status='pending'" ) ), 'Все ожидающие ответы отменены' );
// Отменённый комментарий снова доступен для ответа.
$assert( ! is_wp_error( VKT_Replies::enqueue( array( 'group_id' => 100, 'items' => array( $item( 3 ) ) ) ) ), 'На комментарий с отменённым ответом можно ответить заново' );

// Закрытый кабинет не отвечает, даже если ответ уже в очереди.
$ripen();
$age_sent();
VKT_Account::$active = false;
VKT_Replies::run_due( 5 );
VKT_Account::$active = true;
$assert( 1 === count( $rows( "comment_id=3 AND status='failed'" ) ), 'Закрытый кабинет не отправляет ответы' );

// Нечем отвечать — не ставим.
VKT_Tokens::$user = false;
$assert( is_wp_error( VKT_Replies::enqueue( array( 'group_id' => 200, 'items' => array( $item( 30 ) ) ) ) ), 'Без ключа и токена ответ не ставится' );
$assert( ! is_wp_error( VKT_Replies::enqueue( array( 'group_id' => 100, 'items' => array( $item( 30 ) ) ) ) ), 'Ключа сообщества достаточно для своей группы' );
VKT_Tokens::$user = true;

// Ветка комментариев: ответ группы и очередь видны у комментария.
$GLOBALS['vkt_read_response'] = array( 'response' => array(
    'count' => 3,
    'current_level_count' => 2,
    'items' => array(
        array( 'id' => 30, 'from_id' => 42, 'date' => 1700000000, 'text' => 'Сколько стоит?', 'thread' => array( 'count' => 1, 'items' => array( array( 'id' => 31, 'from_id' => -100, 'date' => 1700000100, 'text' => 'Ответили в ЛС' ) ) ) ),
        array( 'id' => 32, 'from_id' => 43, 'date' => 1700000200, 'text' => '', 'attachments' => array( array( 'type' => 'sticker' ) ), 'thread' => array( 'count' => 0, 'items' => array() ) ),
    ),
    'profiles' => array( array( 'id' => 42, 'first_name' => 'Анна', 'last_name' => 'К.', 'photo_50' => 'https://img.test/a.jpg' ) ),
    'groups' => array( array( 'id' => 100, 'name' => 'Своя', 'photo_50' => 'javascript:alert(1)' ) ),
) );
$thread = VKT_Replies::thread( 100, 10 );
$read = end( VKT_API::$read );
$assert( 'wall.getComments' === $read[0] && 0 === $read[1]['preview_length'] && -100 === $read[1]['owner_id'], 'Комментарии читаются без обрезки текста' );
$assert( 'Анна К.' === $thread['comments'][0]['author'] && true === $thread['comments'][0]['answered'], 'Автор найден, ответ группы в ветке замечен' );
$assert( true === $thread['comments'][0]['thread'][0]['is_group'] && '' === $thread['comments'][0]['thread'][0]['photo'], 'Ответ группы помечен, опасная ссылка на аватар отброшена' );
$assert( 'pending' === $thread['comments'][0]['queued'], 'Комментарий с ответом в очереди помечен' );
$assert( true === $thread['comments'][1]['has_media'] && 2 === $thread['total'], 'Стикер без текста распознан, счёт по верхнему уровню' );

// Посты стены: превью, закрытые комментарии.
$GLOBALS['vkt_read_response'] = array( 'response' => array( 'count' => 1, 'items' => array(
    array( 'id' => 10, 'date' => 1700000000, 'text' => 'Пост', 'comments' => array( 'count' => 5, 'can_post' => 0 ), 'attachments' => array( array( 'type' => 'photo', 'photo' => array( 'sizes' => array( array( 'width' => 75, 'url' => 'https://img.test/s.jpg' ), array( 'width' => 130, 'url' => 'https://img.test/m.jpg' ) ) ) ) ) ),
) ) );
$posts = VKT_Replies::posts( 100 );
$assert( 5 === $posts['posts'][0]['comments'] && false === $posts['posts'][0]['can_comment'] && 'https://img.test/m.jpg' === $posts['posts'][0]['thumb'], 'Пост: число комментариев, запрет комментариев, превью' );

// Cron не должен получить лимит из пустой строки do_action.
$cron = new ReflectionMethod( VKT_Replies::class, 'cron' );
$assert( 0 === $cron->getNumberOfParameters(), 'Вход для cron без параметров' );

$state = VKT_Replies::state();
$senders = array_column( $state['groups'], 'sender', 'group_id' );
$assert( 2 === count( $senders ) && 'community' === $senders[100] && 'user' === $senders[200], 'Вкладка знает, чем уйдёт ответ в каждую группу' );
$assert( in_array( $state['queue'][0]['status'], array( 'pending', 'sending' ), true ), 'Ожидающие ответы в начале списка очереди' );

VKT_Replies::purge_user( 5 );
$assert( 0 === count( $rows( 'user_id=5' ) ), 'Удаление кабинета уносит его очередь ответов' );

// Вложения комментария: стикер и фото видны в ленте, чтобы на них можно было ответить.
$media = VKT_Replies::media_of( array( 'attachments' => array(
    array( 'type' => 'sticker', 'sticker' => array( 'images' => array( array( 'url' => 'https://vk.test/s64.png', 'width' => 64 ), array( 'url' => 'https://vk.test/s128.png', 'width' => 128 ), array( 'url' => 'https://vk.test/s512.png', 'width' => 512 ) ) ) ),
    array( 'type' => 'photo', 'photo' => array( 'sizes' => array( array( 'url' => 'https://vk.test/p75.jpg', 'width' => 75 ), array( 'url' => 'https://vk.test/p360.jpg', 'width' => 360 ), array( 'url' => 'http://vk.test/p1280.jpg', 'width' => 1280 ) ) ) ),
    array( 'type' => 'audio', 'audio' => array( 'artist' => 'Группа', 'title' => 'Песня <b>' ) ),
    array( 'type' => 'video', 'video' => array( 'title' => 'Ролик', 'image' => array( array( 'url' => 'javascript:alert(1)', 'width' => 320 ) ) ) ),
    array( 'type' => 'poll', 'poll' => array() ),
) ) );
$assert( 4 === count( $media ) && array( 'type' => 'sticker', 'url' => 'https://vk.test/s128.png', 'title' => '' ) === $media[0], 'Стикер отдаётся картинкой подходящего размера, вложений не больше четырёх' );
$assert( 'https://vk.test/p360.jpg' === $media[1]['url'] && 'Группа — Песня' === $media[2]['title'] && '' === $media[2]['url'], 'Фото — превью около 320 px, аудио — подписью без тегов' );
$assert( 'video' === $media[3]['type'] && '' === $media[3]['url'] && 'Ролик' === $media[3]['title'], 'Небезопасный адрес картинки отбрасывается, подпись остаётся' );
$assert( array() === VKT_Replies::media_of( array( 'text' => 'Без вложений' ) ) && array() === VKT_Replies::media_of( array( 'attachments' => array( 'мусор', array() ) ) ), 'Комментарий без вложений и мусор в них не ломают разбор' );

// Лимит кабинета — сколько ответов ждёт в очереди одновременно: что влезло, ставится, остальное возвращается пропущенным.
$waiting_now = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_vkt_comment_replies WHERE user_id=" . VKT_Account::id() . " AND status IN ('pending','sending')" );
VKT_Account::$batch = $waiting_now + 2;
$partial = VKT_Replies::enqueue( array( 'group_id' => 100, 'interval' => 1, 'items' => array( $item( 9001 ), $item( 9002 ), $item( 9003 ) ) ) );
$assert( ! is_wp_error( $partial ) && 2 === $partial['created'] && 1 === count( $partial['skipped'] ) && 2 === $partial['skipped'][0]['index'] && ! empty( $partial['skipped'][0]['full'] ), 'В заполняющуюся очередь встаёт сколько влезло, лишний ответ помечен как не поместившийся' );
$full = VKT_Replies::enqueue( array( 'group_id' => 100, 'items' => array( $item( 9004 ) ) ) );
$assert( is_wp_error( $full ) && 429 === $full->data['status'] && str_contains( $full->get_error_message(), 'заполнена' ), 'В заполненную очередь ответы не ставятся, причина названа' );
VKT_Account::$batch = 300;

echo "All $checks offline reply checks passed.\n";

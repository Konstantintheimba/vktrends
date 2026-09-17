<?php
// Изолированная проверка схемы и строгого формата вложений — без WordPress и сети.
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
    public function add_data( $data ) { $this->data = $data; }
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
    public static array $published = array();
    public static function configured() { return self::$on; }
    public static function group_id() { return 241464933; }
    public static string $fail_with = '';
    public static function publish( $params ) {
        if ( '' !== self::$fail_with ) { return new WP_Error( 'vk_100', self::$fail_with, array( 'status' => 422, 'vk_code' => 100 ) ); }
        self::$published[] = $params;
        return array( 'response' => array( 'post_id' => 1 ) );
    }
}
const ARRAY_A = 'ARRAY_A';
const OBJECT_K = 'OBJECT_K';
function update_option( $name, $value, $autoload = true ) { return true; }
class VKT_Store {
    public static function table( $name ) { return 'wp_vkt_' . $name; }
    public static function lock( $name, $seconds ) { return true; }
    public static function unlock( $name ) {}
    public static function log() {}
}
class VKT_Tokens {
    public static function has( $slot ) { return 'user' === $slot; }
    public static function token( $slot ) { return 'user' === $slot ? 'USER_TOKEN' : ''; }
}
class VKT_Media {
    public static function prepare_for_vk( $ids, $group_id ) { return ''; }
    public static function validate_ids( $ids ) { return array_values( array_filter( array_map( 'absint', (array) $ids ) ) ); }
}
class VKT_Plugin {
    public static function settings() { return array( 'publishing_review' => false ); }
}
const DAY_IN_SECONDS = 86400;
const YEAR_IN_SECONDS = 31536000;
function apply_filters( $name, $value ) { return $value; }
function do_action() {}
function wp_generate_uuid4() { return '11111111-2222-3333-4444-555555555555'; }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
// Ни один тест очереди не должен дойти до сети: вызов записывается и виден в проверке.
$GLOBALS['vkt_http'] = 0;
function wp_remote_post( $url, $args ) { ++$GLOBALS['vkt_http']; return new WP_Error( 'blocked', 'Сеть в тесте недоступна' ); }
class VKT_Test_WPDB {
    public array $queries = array();
    public array $updates = array();
    public array $rows = array();
    public function prepare( $sql, ...$args ) {
        foreach ( $args as $arg ) {
            $sql = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
        }
        return $sql;
    }
    public function query( $sql ) { $this->queries[] = $sql; return 1; }
    // Сообщества отдаются на каждый запрос: create() спрашивает их отдельно
    // для каждой записи серии. Строки доставок — одноразовые.
    public array $groups = array();
    public int $insert_id = 0;
    public array $inserts = array();
    public function get_results( $sql, $mode = null ) {
        $this->queries[] = $sql;
        // Именно запрос сообществ из create(). Выборка очереди тоже упоминает
        // эту таблицу в JOIN, поэтому сверяем начало запроса, а не вхождение.
        if ( str_starts_with( ltrim( $sql ), 'SELECT id,group_id FROM' ) ) { return $this->groups; }
        if ( str_contains( $sql, 'GROUP BY status' ) ) { return array(); }
        $rows = $this->rows;
        $this->rows = array();
        return $rows;
    }
    public function insert( $table, $data ) {
        $this->inserts[] = array( 'table' => $table, 'data' => $data );
        $this->insert_id = count( $this->inserts );
        return 1;
    }
    public function get_var( $sql ) { return ''; }
    public function get_col( $sql ) { return array(); }
    public function update( $table, $data, $where ) { $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where ); return 1; }
}
$wpdb = new VKT_Test_WPDB();
$GLOBALS['wpdb'] = $wpdb;

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
// wall.post пользовательским токеном VK запрещает приложениям не типа Standalone,
// поэтому публикует всегда ключ сообщества, а токен только загружает фото.
$assert( true === $picker->invoke( null, 241464933, '11,12' ), 'Запись с файлами тоже публикует ключ сообщества' );
$assert( false === $picker->invoke( null, 987, '' ), 'Чужая группа ключом сообщества не публикуется' );
VKT_Community::$on = false;
$assert( false === $picker->invoke( null, 241464933, '' ), 'Без настроенного ключа сообщества остаётся пользовательский токен' );
VKT_Community::$on = true;

// Повтор вручную: кэш вложений VK сбрасывается, иначе отклонённая строка
// отправляется снова и снова.
VKT_Publisher::retry( 7 );
$retry_sql = $wpdb->queries[0];
$assert( str_contains( $retry_sql, "media_attachments=''" ), 'Кнопка «Повторить» сбрасывает кэш вложений VK' );
$assert( str_contains( $retry_sql, "status='failed'" ), 'В очередь возвращаются только проваленные доставки' );
$assert( str_contains( $retry_sql, "attempts=0" ), 'Счётчик попыток обнуляется' );

// Запись, созданная прежней версией: прямая ссылка на файл в поле вложений.
// Текущие проверки обязаны поймать её до обращения к VK.
$wpdb->queries = array();
$wpdb->updates = array();
$wpdb->rows = array( array(
    'id' => 3,
    'outbound_post_id' => 7,
    'group_id' => 241464933,
    'attempts' => 0,
    'media' => '',
    'media_attachments' => '',
    'attachments' => 'https://example.com/photo.jpg',
    'message' => 'Осенний пост',
    'signed' => 0,
    'close_comments' => 0,
    'guid' => 'guid-3',
    'local_group_id' => 1,
    'name' => 'Своя группа',
    'enabled' => 1,
    'can_post' => 1,
) );
VKT_Publisher::run_due( 1 );
$failed = array_values( array_filter( $wpdb->updates, static fn( $update ) => 'failed' === ( $update['data']['status'] ?? '' ) ) );
$assert( 1 === count( $failed ), 'Запись прежней версии отклонена очередью' );
$assert( str_contains( $failed[0]['data']['error'], 'Прямая ссылка на файл' ), 'Причина отказа объяснена словами, а не кодом VK' );
$assert( 0 === $GLOBALS['vkt_http'], 'До VK дело не дошло: ни одного сетевого запроса' );
$assert( array() === VKT_Community::$published, 'Ключ сообщества такую запись не публикует' );

// Дословный отказ VK про ссылку читается как поломка плагина, поэтому причина
// в очереди объясняется словами.
$wpdb->updates = array();
VKT_Community::$fail_with = 'VK отклонил запрос сообщества, код 100: One of the parameters specified was missing or invalid: Violated: link_photo_sizing_rule. No photo given';
$wpdb->rows = array( array(
    'id' => 4,
    'outbound_post_id' => 8,
    'group_id' => 241464933,
    'attempts' => 0,
    'media' => '',
    'media_attachments' => '',
    'attachments' => 'https://example.com/tovar',
    'message' => 'Запись со ссылкой',
    'signed' => 0,
    'close_comments' => 0,
    'guid' => 'guid-4',
    'local_group_id' => 1,
    'name' => 'Своя группа',
    'enabled' => 1,
    'can_post' => 1,
) );
VKT_Publisher::run_due( 1 );
$link_failed = array_values( array_filter( $wpdb->updates, static fn( $update ) => isset( $update['data']['error'] ) && '' !== (string) $update['data']['error'] ) );
$assert( 1 === count( $link_failed ), 'Отказ по ссылке записан в доставку' );
$assert( str_contains( $link_failed[0]['data']['error'], 'VK не собрал карточку из ссылки' ), 'Причина объяснена словами' );
$assert( ! str_contains( $link_failed[0]['data']['error'], 'link_photo_sizing_rule' ), 'Внутренний код VK в сообщение не попадает' );
VKT_Community::$fail_with = '';

// ——— Серия постов ———
$wpdb->groups = array( array( 'id' => 3, 'group_id' => 241464933 ) );
$future = static fn( $hours ) => gmdate( 'c', time() + $hours * 3600 );

$assert( is_wp_error( VKT_Publisher::create_series( array() ) ), 'Серия без слотов отклоняется' );
$many = array_map( static fn( $i ) => array( 'scheduled_at' => gmdate( 'c', time() + 3600 + $i * 60 ), 'message' => 'Пост ' . $i ), range( 1, VKT_Publisher::MAX_SERIES_SLOTS + 1 ) );
$over = VKT_Publisher::create_series( array( 'slots' => $many, 'groups' => array( 3 ) ) );
$assert( is_wp_error( $over ) && str_contains( $over->get_error_message(), (string) VKT_Publisher::MAX_SERIES_SLOTS ), 'Предел серии назван числом' );

// Слот в прошлом отклоняется до create(): иначе пакет ушёл бы в VK залпом.
$wpdb->inserts = array();
$past = VKT_Publisher::create_series( array( 'slots' => array( array( 'scheduled_at' => gmdate( 'c', time() - 600 ), 'message' => 'Вчерашний' ) ), 'groups' => array( 3 ) ) );
$assert( is_wp_error( $past ) && str_contains( $past->get_error_message(), 'Время уже прошло' ), 'Прошедший слот объясняется словами' );
$assert( array() === $wpdb->inserts, 'Прошедший слот в базу не пишется' );

// Смешанный результат: годные слоты уходят, негодные объясняются.
$wpdb->inserts = array();
$mixed = VKT_Publisher::create_series( array(
    'slots' => array(
        array( 'scheduled_at' => $future( 2 ), 'message' => 'Первый пост серии' ),
        array( 'scheduled_at' => gmdate( 'c', time() - 60 ), 'message' => 'Опоздавший' ),
        array( 'scheduled_at' => $future( 26 ), 'message' => 'Второй пост серии' ),
        array( 'scheduled_at' => $future( 50 ), 'message' => '' ),
    ),
    'groups' => array( 3 ),
) );
$assert( ! is_wp_error( $mixed ) && 2 === $mixed['created'], 'Годные слоты серии поставлены в очередь' );
$assert( 2 === count( $mixed['failed'] ), 'Негодные слоты перечислены отдельно' );
$assert( str_contains( $mixed['failed'][0]['error'], 'Время уже прошло' ), 'Причина опоздавшего слота названа' );
$assert( str_contains( $mixed['failed'][1]['error'], 'Добавьте текст' ), 'Пустой слот отклонён теми же проверками, что и одиночная запись' );
$assert( 1 === $mixed['failed'][1]['index'] || 3 === $mixed['failed'][1]['index'], 'У отказа виден номер слота' );
$posts = array_values( array_filter( $wpdb->inserts, static fn( $insert ) => str_contains( $insert['table'], 'outbound_posts' ) ) );
$assert( 2 === count( $posts ), 'В базу ушли ровно две записи' );
$assert( 'scheduled' === $posts[0]['data']['status'], 'Записи серии ждут своего времени, а не публикуются сразу' );
$assert( 'manual' === $posts[0]['data']['origin'], 'Серия создаётся вручную, а не агентом' );

echo "All $checks offline publisher checks passed.\n";

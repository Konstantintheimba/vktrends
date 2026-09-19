<?php
/** Offline regression checks: php tests/commerce.php. No WordPress, network or persistent database. */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $value ) ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function esc_url_raw( $value, $protocols = array() ) { return preg_match( '~^https?://[^\s<>]+$~ui', $value ) ? $value : ''; }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
class VKT_Plugin { public static function settings() { return array( 'links' => true ); } }
// Посты видны только из своих источников.
class VKT_Account { public static int $id = 1; public static function id() { return self::$id; } }
class VKT_Subscriptions { public static function sources_sql() { return 'SELECT source_id FROM test_vkt_subscriptions WHERE user_id=' . (int) VKT_Account::id(); } }
// Follow the 0.8.0 bootstrap: callers must load their new parser dependencies.
require_once __DIR__ . '/../includes/class-store.php';
require_once __DIR__ . '/../includes/class-posts.php';
require_once __DIR__ . '/../includes/class-links.php';
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$checks = 0;
function check( $condition, $message ) {
    global $checks;
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
}
function cards( $row ) { return json_decode( $row['cards'], true ) ?: array(); }
function extract_text( $text ) { return VKT_Commerce::extract( array( 'text' => $text ) ); }

check( class_exists( 'VKT_Commerce', false ), 'Legacy bootstrap loads the commerce parser through its callers' );
check( class_exists( 'VKT_Product_Page', false ), 'Legacy bootstrap loads the product page parser through links' );
$row = extract_text( "Подборка https://vk.ru/wall-1_2 — Футболки из хлопка за 600 рублей https://market.yandex.ru/cc/Avcf2b" );
check( 'https://market.yandex.ru/cc/Avcf2b' === $row['link_url'], 'An internal VK link must not hide a later store link' );
check( 'Футболки из хлопка' === $row['text_product'] && 600.0 === $row['text_price'], 'Name and price belong to the adjacent product' );
check( '' === $row['product_source'], 'A text hint is never reported as recognized' );
$row = extract_text( "Обновили мамочке кроссовки 😍 Они до 42 размера и с хорошей шириной стопы.\nПоэтому нигде не жмут и не натирают 🙌\nСсылку оставляю:\nhttps://ozon.ru/product/3615443329?advRef=abc" );
check( 'кроссовки' === $row['text_product'], 'A conversational intro must become a short product hint' );
check( '3615443329' === $row['product_sku'], 'SKU works without a network request' );
$row = extract_text( 'Она была толстой и счастливой И это не помешало ей покорить новую страну, прилетев с семьёй в Китай. https://ozon.ru/product/3615443329' );
check( '' === $row['text_product'], 'Lifestyle prose must not become a product title' );
$row = extract_text( 'Я бы не смогла без них Забудь я шортики от натирания, даже не знаю как бы спасалась. https://ozon.ru/product/3615443329' );
check( 'шортики от натирания' === $row['text_product'], 'Product noun is found inside an introduction' );
$row = extract_text( 'Кроссовки https://ozon.ru/product/3615443329 — Дождевик за 234 рубля https://www.wildberries.ru/catalog/141692400/detail.aspx' );
$items = cards( $row );
check( count( $items ) === 2 && null === $items[0]['price'] && 234.0 === (float) $items[1]['price'], 'A later product price never leaks to an earlier URL' );
$row = extract_text( '[https://ozon.ru/product/3615443329|Кроссовки Nike]' );
check( 'Кроссовки Nike' === $row['text_product'], 'VK wiki link label is preserved' );
check( 'https://ozon.ru/product/3615443329?x=1&y=2' === VKT_Commerce::url( 'https://vk.com/away.php?to=https%3A%2F%2Fozon.ru%2Fproduct%2F3615443329%3Fx%3D1%26y%3D2' ), 'VK redirect wrapper is unwrapped without losing the target query' );
check( '' === VKT_Commerce::url( 'javascript:alert(1)' ), 'Non-HTTP schemes rejected' );
check( '-123_9' === VKT_Commerce::market_id( 'https://vk.ru/market-123?w=product-123_9' ), 'VK market w=product link recognized' );
$row = extract_text( 'Заказать: ozon.ru/product/3615443329' );
check( str_starts_with( $row['link_url'], 'https://ozon.ru/' ), 'Bare known-shop URLs recognized' );

$market = array( 'type' => 'market', 'market' => array( 'owner_id' => -123, 'id' => 9, 'title' => 'Лампа настольная', 'price' => array( 'amount' => '159900', 'old_amount' => '199900' ) ) );
$row = VKT_Commerce::extract( array( 'text' => 'Футболки за 600 рублей https://market.yandex.ru/cc/abc', 'attachments' => array( $market ) ) );
check( 'Лампа настольная' === $row['product_title'] && 1599.0 === $row['product_price'] && 1999.0 === $row['product_old_price'], 'VK market prices convert kopecks to rubles' );
check( 'https://vk.ru/market-123_9' === cards( $row )[0]['url'] && '' === $row['link_url'], 'Market title stays paired with its own URL, not an unrelated external product' );
$row = VKT_Commerce::extract( array( 'text' => 'Мой комментарий', 'copy_history' => array( array( 'copy_history' => array( array( 'attachments' => array( $market ) ) ) ) ) ) );
check( 'Лампа настольная' === $row['product_title'], 'Nested repost attachment is extracted despite outer commentary' );
$carousel = array( array( 'title' => 'Сумка кожаная', 'price' => '1299.90', 'link_url' => 'https://ozon.ru/product/3615443329' ), array( 'title' => 'Рюкзак', 'price' => '999', 'link_url' => 'https://www.wildberries.ru/catalog/141692400/detail.aspx' ) );
foreach ( array( array( 'pretty_cards' => $carousel ), array( 'attachments' => array( array( 'type' => 'pretty_cards', 'pretty_cards' => array( 'cards' => $carousel ) ) ) ) ) as $fixture ) {
    $row = VKT_Commerce::extract( $fixture );
    check( count( cards( $row ) ) === 2 && 'Сумка кожаная' === $row['product_title'] && 1299.9 === $row['product_price'], 'Root/nested carousel keeps separate products and decimal ruble prices' );
}
$link = array( 'type' => 'link', 'link' => array( 'url' => 'https://ozon.ru/product/3615443329', 'title' => 'Кроссовки Nike', 'product' => array( 'price' => array( 'amount' => '399900' ) ) ) );
$row = VKT_Commerce::extract( array( 'text' => 'Кроссовки за 500 рублей https://ozon.ru/product/3615443329', 'attachments' => array( $link ) ) );
check( 'Кроссовки Nike' === $row['product_title'] && 3999.0 === $row['product_price'] && count( cards( $row ) ) === 1, 'Product link metadata beats text for the same destination and deduplicates it' );
$row = VKT_Commerce::extract( array( 'attachments' => array( array( 'type' => 'video', 'video' => array( 'description' => 'Футболки за 600 рублей https://market.yandex.ru/cc/abc' ) ) ) ) );
check( 'Футболки' === $row['text_product'] && 600.0 === $row['text_price'], 'Video description links extracted' );
$row = VKT_Commerce::extract( array( 'attachments' => array( array( 'type' => 'link', 'link' => array( 'title' => 'Ozon', 'url' => 'https://ozon.ru/product/3615443329' ) ) ) ) );
check( '' === $row['product_title'] && '' === $row['text_product'], 'Store-only title is not a product name' );
check( '' === extract_text( 'Обычный пост без товара и ссылок' )['cards'], 'Plain posts produce no commerce cards' );
check( '' === VKT_Links::sku_from_url( 'https://evil.test/ozon.ru/product/3615443329' ), 'Merchant name in a foreign path cannot create a product SKU' );
check( '123456789' === VKT_Links::sku_from_url( 'https://market.yandex.ru/card/futbolka/123456789' ), 'Yandex card URLs supply their own product ID' );
$link['link']['product'] = array();
$row = VKT_Commerce::extract( array( 'text' => 'Кроссовки за 500 рублей https://ozon.ru/product/3615443329', 'attachments' => array( $link ) ) );
check( 'Кроссовки Nike' === $row['product_title'] && 500.0 === $row['product_price'] && 'text' === cards( $row )[0]['price_source'], 'Missing attachment price can use a labelled text price for the same URL' );

$html = '<script type="application/ld+json">[{"@type":"WebSite","name":"Shop"},{"@type":["Thing","Product"],"name":"Кроссовки Nike","offers":[{"price":"3 999,50"}],"image":{"url":"https://img.test/shoe.jpg"}}]</script>';
$page = VKT_Links::parse( $html );
check( $page['recognized'] && 'Кроссовки Nike' === $page['title'] && 3999.5 === $page['price'] && 'https://img.test/shoe.jpg' === $page['image'], 'JSON-LD root arrays, type arrays, offer arrays and image objects supported' );
$page = VKT_Links::parse( '<script type="application/ld+json">{"@graph":[{"mainEntity":{"@type":"Product","name":"Лампа","offers":{"@type":"AggregateOffer","lowPrice":"1200"}}}]}</script>' );
check( $page['recognized'] && 1200.0 === $page['price'], 'Nested mainEntity product and aggregate offer supported' );
$page = VKT_Links::parse( '<div itemscope itemtype="https://schema.org/Product"><h1 itemprop="name">Термос</h1><span itemprop="price" content="1999"></span><img itemprop="image" src="https://img.test/thermos.jpg"></div>' );
check( $page['recognized'] && 'Термос' === $page['title'] && 1999.0 === $page['price'], 'Scoped Product microdata supported' );
$page = VKT_Links::parse( '<script>window.state={"content":"Кроссовки \\u004eike","property":"og:title"};</script><meta property="og:type" content="product">' );
check( $page['recognized'] && 'Кроссовки Nike' === $page['title'], 'Serialized metadata supports reversed keys and Unicode escapes' );
$page = VKT_Links::parse( '<script type="application/json">{"products":[{"id":"9999999999","name":"Чужой товар"},{"id":"3615443329","name":"Нужные кроссовки","price":"2599"}]}</script>', 'https://ozon.ru/product/3615443329' );
check( $page['recognized'] && 'Нужные кроссовки' === $page['title'], 'Hydration data must match the product ID, not a recommendation' );
$page = VKT_Links::parse( '<title>Доступ ограничен</title>' );
check( VKT_Links::is_guard_page( $page ) && ! $page['recognized'], 'Anti-bot page is still classified as blocked' );
$page = VKT_Links::parse( '<title>Каталог магазина</title><meta property="og:image" content="https://img.test/logo.jpg">' );
check( ! $page['recognized'], 'Generic page title/logo is not a recognized product' );
check( ! VKT_Links::parse( '<script>broken { JSON</script>' )['recognized'], 'Malformed script safely ignored' );

/** Minimal SQL adapter to execute the real filtering queries in an in-memory SQLite DB. */
class Commerce_DB {
    public $prefix = 'test_';
    public $insert_id = 0;
    public $db;
    public function __construct() { $this->db = new PDO( 'sqlite::memory:' ); $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION ); }
    public function prepare( $sql, ...$args ) {
        $i = 0;
        return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
            $value = $args[ $i++ ];
            return '%s' === $m[0] ? $this->db->quote( (string) $value ) : (string) ( '%d' === $m[0] ? (int) $value : (float) $value );
        }, $sql );
    }
    public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
    public function get_results( $sql, $format = null ) { return $this->db->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
    public function get_row( $sql, $format = null ) { return $this->get_results( $sql )[0] ?? null; }
    public function get_var( $sql ) { $row = $this->db->query( $sql )->fetch( PDO::FETCH_NUM ); return $row ? $row[0] : null; }
    public function get_col( $sql ) { return $this->db->query( $sql )->fetchAll( PDO::FETCH_COLUMN ); }
    public function query( $sql ) {
        if ( 'START TRANSACTION' === $sql ) { $sql = 'BEGIN'; }
        if ( str_contains( $sql, 'ON DUPLICATE KEY UPDATE' ) ) {
            $key = str_contains( $sql, 'shop_links' ) ? 'url_hash' : 'owner_id,post_id';
            $sql = preg_replace( '/ON DUPLICATE KEY UPDATE.*$/s', 'ON CONFLICT (' . $key . ') DO UPDATE SET id=id RETURNING id', $sql );
            $this->insert_id = (int) $this->db->query( $sql )->fetchColumn();
            return 1;
        }
        return $this->db->exec( $sql );
    }
    private function conditions( $values ) { return implode( ' AND ', array_map( fn( $key ) => $key . '=' . $this->db->quote( (string) $values[ $key ] ), array_keys( $values ) ) ); }
    public function update( $table, $data, $where ) {
        $set = implode( ',', array_map( fn( $key ) => $key . '=' . ( null === $data[ $key ] ? 'NULL' : $this->db->quote( (string) $data[ $key ] ) ), array_keys( $data ) ) );
        return $this->db->exec( 'UPDATE ' . $table . ' SET ' . $set . ' WHERE ' . $this->conditions( $where ) );
    }
    public function insert( $table, $data ) {
        $statement = $this->db->prepare( 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')' );
        $statement->execute( array_values( $data ) );
        $this->insert_id = (int) $this->db->lastInsertId();
        return $statement->rowCount();
    }
    public function delete( $table, $where ) { return $this->db->exec( 'DELETE FROM ' . $table . ' WHERE ' . $this->conditions( $where ) ); }
    public function replace( $table, $data ) {
        $statement = $this->db->prepare( 'INSERT OR REPLACE INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')' );
        $statement->execute( array_values( $data ) );
        return $statement->rowCount();
    }
}
$wpdb = new Commerce_DB();
foreach ( array_merge( VKT_Posts::schema(), VKT_Links::schema() ) as $name => $schema ) {
    $schema = preg_replace( '/id bigint unsigned NOT NULL AUTO_INCREMENT/', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $schema );
    $schema = preg_replace( '/^\s*PRIMARY KEY\s+\(id\),?\s*$/m', '', $schema );
    $schema = preg_replace( '/^\s*KEY\s+\w+\s+\([^\n]+\),?\s*$/m', '', $schema );
    $schema = preg_replace( '/UNIQUE KEY\s+\w+/', 'UNIQUE', $schema );
    $schema = rtrim( trim( $schema ), ',' );
    $wpdb->query( 'CREATE TABLE ' . VKT_Store::table( $name ) . ' (' . $schema . ')' );
}
$wpdb->query( "CREATE TABLE test_vkt_sources (id INTEGER PRIMARY KEY,kind TEXT,title TEXT,value TEXT,members INTEGER,photo TEXT)" );
$wpdb->query( "INSERT INTO test_vkt_sources VALUES (1,'domain','Первое сообщество','first',5000,''),(2,'owner','Второе сообщество','-2',10000,'')" );
// Кабинет 1 следит за обоими сообществами, кабинет 2 — только за вторым.
$wpdb->query( "CREATE TABLE test_vkt_subscriptions (user_id INTEGER,source_id INTEGER,enabled INTEGER)" );
$wpdb->query( "INSERT INTO test_vkt_subscriptions VALUES (1,1,1),(1,2,1),(2,2,1)" );
function seed_post( $id, $source, $text, $extra = array() ) {
    global $wpdb;
    $data = array_merge( array( 'id' => $id, 'owner_id' => -$source, 'post_id' => $id, 'source_id' => $source, 'text' => $text, 'thumbnail' => '', 'link_url' => '', 'cards' => '', 'views' => 100 * $id, 'g1' => 10 * $id, 'err' => 2, 'measured_at' => '2026-09-09 01:00:00' ), $extra );
    $wpdb->insert( 'test_vkt_posts', $data );
}
seed_post( 1, 1, 'Футболки за 600 рублей https://market.yandex.ru/cc/abc — Лампа за 1999 рублей https://ozon.ru/product/3615443329' );
seed_post( 2, 2, 'Платье за 900 рублей https://ozon.ru/product/2222222222' );
seed_post( 3, 2, 'Пост без товара' );
check( 2 === VKT_Posts::reextract( 2 ), 'Legacy re-extraction uses bounded batches' );
check( 1 === VKT_Posts::reextract( 2 ) && 0 === VKT_Posts::reextract( 2 ), 'Re-extraction resumes and stops when complete' );
check( 100 === (int) $wpdb->get_var( 'SELECT views FROM test_vkt_posts WHERE id=1' ), 'Re-extraction preserves view counters' );
check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM test_vkt_post_snapshots' ), 'Re-extraction does not manufacture measurements' );
check( 3 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM test_vkt_post_products' ), 'Each product is separately indexed' );
check( 1 === VKT_Posts::query( array( 'source' => 1 ) )['total'], 'Community filter restricts the feed' );
$query = VKT_Posts::query( array( 'source' => 2 ) );
check( 2 === $query['total'] && 500 === (int) $query['summary']['views'] && count( $query['communities'] ) === 2, 'Community summary uses filtered rows and options remain complete' );
check( 0 === VKT_Posts::query( array( 'source' => 999 ) )['total'], 'Unknown community returns an empty result' );
check( 0 === VKT_Posts::query( array( 'filters' => array( 'recognized' => true ) ) )['total'], 'Text-only hints do not pass the recognized filter' );
$id = (int) $wpdb->get_var( "SELECT id FROM test_vkt_shop_links WHERE url='https://ozon.ru/product/3615443329'" );
$wpdb->update( 'test_vkt_shop_links', array( 'status' => 'ok', 'title' => 'Настольная лампа', 'price' => 2100 ), array( 'id' => $id ) );
$query = VKT_Posts::query( array( 'source' => 1, 'filters' => array( 'recognized' => true ) ) );
check( 1 === $query['total'] && 1 === (int) $query['summary']['recognized_posts'], 'A recognized secondary link counts and passes the filter' );
check( 'Настольная лампа' === $query['posts'][0]['product_items'][1]['page']['title'], 'Secondary page metadata stays with its product' );
check( 1 === VKT_Posts::query( array( 'search' => 'Настольная' ) )['total'], 'Search finds secondary product page titles' );
check( 1 === VKT_Posts::query( array( 'filters' => array( 'shop' => 'Ozon', 'price_min' => 2000 ) ) )['total'], 'Price filters use page metadata for the matching item' );
check( 0 === VKT_Posts::query( array( 'filters' => array( 'shop' => 'Яндекс Маркет', 'price_min' => 2000 ) ) )['total'], 'Shop and price cannot match two different items in one post' );
check( 1 === VKT_Posts::query( array( 'source' => 2, 'filters' => array( 'with_product' => true ) ) )['total'], 'Product flag combines with community selection' );
$post = array( 'owner_id' => -1, 'id' => 999, 'date' => time(), 'views' => array( 'count' => 1234 ), 'likes' => array( 'count' => 10 ), 'text' => 'Мой комментарий', 'copy_history' => array( array( 'attachments' => array( $market ) ) ) );
$saved_id = VKT_Posts::save( $post, 1, 5000 );
check( is_int( $saved_id ) && $saved_id > 0, 'Actual post save path writes commerce and measurements atomically' );
check( 'Лампа настольная' === $wpdb->get_var( 'SELECT title FROM test_vkt_post_products WHERE post_id=' . $saved_id ), 'Saved repost attachment is indexed' );
check( 1 === VKT_Posts::query( array( 'source' => 1, 'filters' => array( 'shop' => 'VK Маркет', 'recognized' => true ) ) )['total'], 'Native VK product recognized without a store page' );
// Чужой кабинет не видит постов из источника, на который не подписан.
VKT_Account::$id = 2;
check( 0 === VKT_Posts::query( array( 'source' => 1 ) )['total'], 'Посты чужого источника в кабинете не видны' );
check( VKT_Posts::query()['total'] === VKT_Posts::query( array( 'source' => 2 ) )['total'], 'В ленте кабинета только его источники' );
check( array( '2' ) === array_map( 'strval', array_column( VKT_Posts::query()['communities'], 'id' ) ), 'В фильтре сообществ только свои' );
VKT_Account::$id = 1;
unset( $post['copy_history'] );
VKT_Posts::save( $post, 1, 5000 );
check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM test_vkt_post_products WHERE post_id=' . $saved_id ), 'Refreshing a post removes obsolete product associations' );
check( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM test_vkt_post_snapshots WHERE post_id=' . $saved_id ), 'Unchanged measurements remain deduplicated' );
$wpdb->update( 'test_vkt_shop_links', array( 'status' => 'empty', 'attempts' => 3, 'parser_version' => 0 ), array( 'id' => $id ) );
check( in_array( $id, VKT_Links::due(), true ), 'Old empty pages get one retry with the improved parser' );
$wpdb->update( 'test_vkt_shop_links', array( 'parser_version' => 1 ), array( 'id' => $id ) );
check( ! in_array( $id, VKT_Links::due(), true ), 'An empty result is not retried indefinitely' );
$wpdb->update( 'test_vkt_shop_links', array( 'status' => 'blocked', 'parser_version' => 0 ), array( 'id' => $id ) );
check( ! in_array( $id, VKT_Links::due(), true ), 'Upgrade does not retry blocked pages' );
echo "All $checks offline commerce checks passed.\n";

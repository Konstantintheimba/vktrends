<?php
/** Run only in an isolated WordPress: wp eval-file tests/integration.php */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://127.0.0.1:8097' !== home_url() ) {
    throw new RuntimeException( 'Only the isolated VK Trends test installation is allowed.' );
}
global $wpdb, $checks;
$checks = 0;
function vkt_assert( $condition, $message ) {
    global $checks;
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
    echo "PASS: $message\n";
}
function vkt_action( $action, $data = array() ) {
    $request = new WP_REST_Request( 'POST', '/vk-trends/v1/action' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( array_merge( array( 'action' => $action ), $data ) ) );
    return VKT_Plugin::action( $request );
}
foreach ( array( 'videos', 'snapshots', 'posts', 'post_snapshots', 'post_products', 'shop_links', 'products', 'links', 'sources', 'jobs', 'logs', 'publishing_groups', 'outbound_posts', 'outbound_deliveries' ) as $table ) {
    $wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( $table ) );
}
update_option( 'vkt_settings', VKT_Plugin::defaults(), false );
delete_option( 'vkt_token' );
VKT_Store::unlock( 'api' );
VKT_Store::unlock( 'collector' );
VKT_Store::unlock( 'publisher' );

vkt_assert( count( VKT_API::methods() ) === 16, '16 read methods loaded' );
vkt_assert( VKT_Store::parse_video( 'https://vk.com/clip-123_456' ) === '-123_456', 'Clip URL parsing' );
vkt_assert( VKT_Store::parse_video( 'https://vkvideo.ru/video-123_456' ) === '-123_456', 'VK Video URL parsing' );
vkt_assert( VKT_Store::parse_video( 'https://vk.com/videos-123?z=video-123_456%2Fabc' ) === '-123_456', 'VK z parameter parsing' );
vkt_assert( is_wp_error( VKT_Store::parse_video( 'https://evil.test/video-123_456' ) ), 'Foreign video host rejected' );
vkt_assert( is_wp_error( VKT_Store::parse_video( 'https://vk.com/video-123_456_abcd' ) ), 'Private access_key URL rejected' );
vkt_assert( is_wp_error( VKT_API::request( 'wall.post', array() ) ), 'Write method rejected' );
vkt_assert( is_wp_error( VKT_API::request( 'execute', array() ) ), 'Execute rejected' );
vkt_assert( is_wp_error( VKT_API::request( 'video.get', array( 'access_token' => 'injected' ) ) ), 'Token parameter injection rejected' );
vkt_assert( is_wp_error( VKT_API::request( 'video.get', array( 'v' => '1' ) ) ), 'Version parameter injection rejected' );
vkt_assert( is_wp_error( VKT_API::request( 'video.search', array() ) ), 'Missing token returns controlled error' );
$token = 'TEST_ONLY_VKT_TOKEN_0123456789';
vkt_assert( true === VKT_API::save_token( $token ), 'Token saved' );
vkt_assert( VKT_API::token() === $token, 'Encrypted token round trip' );
vkt_assert( ! str_contains( get_option( 'vkt_token' ), $token ), 'Database option does not contain plain token' );
vkt_assert( ! str_contains( wp_json_encode( VKT_Plugin::public_settings() ), $token ), 'Settings never expose token' );

$mock_mode = 'success';
$refresh_mode = 'success'; // управляет ответом id.vk.ru при обновлении пользовательского токена
$shop_mode = 'success'; // управляет ответом страницы магазина: товар, антибот-заглушка или 403
$seen = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$mock_mode, &$refresh_mode, &$shop_mode, &$seen, $token ) {
    // Обновление пользовательской пары токенов VK ID — отдельный хост, отдельная логика ответа.
    if ( str_starts_with( $url, 'https://id.vk.ru/oauth2/auth' ) ) {
        $seen[] = array( 'url' => $url, 'args' => $args );
        if ( 'transport' === $refresh_mode ) { return new WP_Error( 'network', 'refresh unreachable' ); }
        if ( 'fail' === $refresh_mode ) { return array( 'response' => array( 'code' => 401 ), 'body' => wp_json_encode( array( 'error' => 'invalid_grant' ) ) ); }
        return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array(
            'access_token' => 'REFRESHED_TOKEN', 'refresh_token' => 'REFRESHED_REFRESH', 'expires_in' => 3600, 'device_id' => 'device-1',
        ) ) );
    }
    // Страница магазина: возвращаем разметку товара либо антибот-заглушку.
    if ( ! str_starts_with( $url, 'https://api.vk.com/method/' ) ) {
        if ( str_contains( $url, 'pu.vk.test' ) ) {
            $seen[] = array( 'url' => $url, 'args' => $args );
            return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'server' => 1234, 'photo' => '[{"photo":"x"}]', 'hash' => 'uploadhash' ) ) );
        }
        if ( str_contains( $url, 'ozon.ru' ) ) {
            $seen[] = array( 'url' => $url, 'args' => $args );
            if ( 'guard' === $shop_mode ) {
                return array( 'response' => array( 'code' => 200 ), 'body' => '<html><head><title>Доступ ограничен</title></head><body>Подтвердите, что вы не робот</body></html>' );
            }
            if ( 'blocked' === $shop_mode ) {
                return array( 'response' => array( 'code' => 403 ), 'body' => 'forbidden' );
            }
            return array( 'response' => array( 'code' => 200 ), 'body' => '<html><head><script type="application/ld+json">{"@type":"Product","name":"Обогреватель кварцевый ТеплоВита 600Вт","offers":{"price":"5906.00"}}</script></head><body><h1>h1</h1></body></html>' );
        }
        return $pre;
    }
    $method = basename( $url );
    $seen[] = array( 'url' => $url, 'args' => $args );
    if ( 'transport' === $mock_mode ) { return new WP_Error( 'network', 'Secret ' . $token ); }
    if ( 'malformed' === $mock_mode ) { return array( 'response' => array( 'code' => 200 ), 'body' => '<html>bad</html>' ); }
    $video = array( 'id' => 456, 'owner_id' => -123, 'title' => 'Тестовый ролик <script>alert(1)</script>', 'duration' => 30, 'views' => 100, 'likes' => array( 'count' => 5 ), 'comments' => 2, 'type' => 'short_video', 'access_key' => 'HIDDEN' );
    // video.get отдаёт ролики напрямую списком, а не вложением поста, как wall.get.
    // Пост стены: счётчики, товарное вложение и ссылка в тексте — всё, что разбирает VKT_Posts.
    $post = array(
        'id' => 77, 'owner_id' => -123, 'from_id' => -123, 'date' => time() - 7200,
        'text' => "Обогреватель кварцевый ТеплоВита \nЗаказать: https://ozon.ru/product/123",
        'marked_as_ads' => 0, 'post_type' => 'post',
        'views' => array( 'count' => 1000 ), 'likes' => array( 'count' => 40 ), 'comments' => array( 'count' => 6 ), 'reposts' => array( 'count' => 4 ),
        'attachments' => array(
            array( 'type' => 'video', 'video' => $video ),
            array( 'type' => 'photo', 'photo' => array( 'sizes' => array( array( 'width' => 800, 'url' => 'https://vk.test/photo-800.jpg' ), array( 'width' => 130, 'url' => 'https://vk.test/photo-130.jpg' ) ) ) ),
            array( 'type' => 'market', 'market' => array( 'id' => 9, 'owner_id' => -123, 'title' => 'Обогреватель кварцевый', 'url' => 'https://ozon.ru/product/123', 'sku' => '3643961413', 'price' => array( 'amount' => '590600', 'old_amount' => '1050000' ) ) ),
        ),
    );
    if ( 'groups.getTokenPermissions' === $method ) {
        $body = str_starts_with( (string) ( $args['body']['access_token'] ?? '' ), 'COMMUNITY_' )
            ? array( 'response' => array( 'mask' => 1048575, 'permissions' => array( 'wall' ) ) )
            : array( 'error' => array( 'error_code' => 27, 'error_msg' => 'group token required' ) );
    } elseif ( 'groups.get' === $method ) {
        $body = array( 'response' => array( 'count' => 1, 'items' => array( array( 'id' => 987, 'name' => 'Моя тестовая группа', 'screen_name' => 'my_test_group', 'admin_level' => 3, 'can_post' => 1, 'photo_200' => 'https://vk.test/group.jpg' ) ) ) );
    } elseif ( 'wall.post' === $method ) {
        $body = array( 'response' => array( 'post_id' => 501 ) );
    } elseif ( 'photos.getWallUploadServer' === $method ) {
        $body = array( 'response' => array( 'upload_url' => 'https://pu.vk.test/upload.php?act=do_add' ) );
    } elseif ( 'photos.saveWallPhoto' === $method ) {
        $body = array( 'response' => array( array( 'id' => 777, 'owner_id' => -987, 'access_key' => 'accesskey1' ) ) );
    } else {
        $body = 'video.get' === $method
        ? array( 'response' => array( 'count' => 1, 'items' => array( $video ) ) )
        : array( 'response' => array(
            'count' => 1, 'items' => array( $post ),
            'groups' => array( array( 'id' => 123, 'name' => 'Тестовое сообщество', 'screen_name' => 'team', 'members_count' => 5000, 'photo_200' => 'https://vk.test/avatar.jpg' ) ),
            'access_token' => $token, 'echo' => 'token=' . $token,
        ) );
    }
    if ( 'denied' === $mock_mode ) { $body = array( 'error' => array( 'error_code' => 28, 'error_msg' => $token, 'request_params' => array( 'access_token' => $token ) ) ); }
    if ( 'retry' === $mock_mode ) { $body = array( 'error' => array( 'error_code' => 6, 'error_msg' => $token ) ); }
    return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
}, 10, 3 );

$community_token = 'COMMUNITY_TEST_TOKEN_0123456789';
vkt_assert( 'community' === VKT_API::detect_token_kind( $community_token ), 'Community token is positively detected without storing it' );
vkt_assert( 'unknown' === VKT_API::detect_token_kind( $token ), 'Non-community token is not falsely classified as a community token' );
$community_save = vkt_action( 'settings', array( 'token' => $community_token, 'token_kind' => 'user' ) );
vkt_assert( is_wp_error( $community_save ) && VKT_API::token() === $token, 'Community token cannot replace the publishing credential' );
$seen = array();
$response = VKT_API::request( 'wall.get', array( 'domain' => 'team', 'count' => 200 ) );
vkt_assert( ! is_wp_error( $response ), 'Read request succeeds with mocked VK' );
vkt_assert( 100 === $seen[0]['args']['body']['count'], 'Result count bounded' );
vkt_assert( false === strpos( $seen[0]['url'], $token ) && $seen[0]['args']['body']['access_token'] === $token, 'Token sent only in server POST body' );
vkt_assert( ! str_contains( wp_json_encode( $response ), $token ) && ! str_contains( wp_json_encode( $response ), 'HIDDEN' ), 'Response secret redaction' );
vkt_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'videos' ) ), 'API tests do not save videos' );
vkt_assert( is_wp_error( VKT_API::request( 'video.get' ) ), 'Concurrent API request rejected' );
VKT_Store::unlock( 'api' );
$saved = vkt_action( 'save_video', array( 'video' => 'https://vk.com/video-123_456' ) );
vkt_assert( ! is_wp_error( $saved ) && $saved['id'] > 0, 'Save fetches the video from VK and persists it' );
$id = $saved['id'];
$video = $wpdb->get_row( 'SELECT * FROM ' . VKT_Store::table( 'videos' ), ARRAY_A );
vkt_assert( null === $video['velocity'] && null === $video['reposts'], 'Initial velocity and missing counters remain unknown' );
vkt_assert( 'short_video' === $video['kind'], 'Clip type stored from the VK attachment' );
$wpdb->update( VKT_Store::table( 'snapshots' ), array( 'measured_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'video_id' => $id ) );
$id2 = VKT_Store::save_video( array( 'id' => 456, 'owner_id' => -123, 'title' => 'Растущий ролик', 'views' => 160, 'likes' => array( 'count' => 9 ) ) );
$video = $wpdb->get_row( 'SELECT * FROM ' . VKT_Store::table( 'videos' ), ARRAY_A );
vkt_assert( $id2 === $id, 'Upsert preserves video ID' );
vkt_assert( abs( (float) $video['velocity'] - 60 ) < .1 && (int) $video['growth'] === 60, 'Velocity normalized by actual elapsed hours' );
vkt_assert( 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'snapshots' ) ), 'History contains both measurements' );
VKT_Store::save_video( array( 'id' => 456, 'owner_id' => -123, 'views' => 80 ) );
vkt_assert( null === $wpdb->get_row( 'SELECT velocity FROM ' . VKT_Store::table( 'videos' ) )->velocity, 'Counter reset is not ranked as growth' );
vkt_assert( ! is_wp_error( vkt_action( 'product', array( 'title' => 'Лампа', 'url' => 'https://example.com/lamp' ) ) ), 'Create product' );
$product_id = $wpdb->insert_id;
vkt_assert( ! is_wp_error( vkt_action( 'link', array( 'video_id' => $id, 'product_id' => $product_id ) ) ), 'Link product to existing video' );
vkt_assert( is_wp_error( vkt_action( 'link', array( 'video_id' => 99999, 'product_id' => $product_id ) ) ), 'Dangling product link rejected' );
vkt_assert( ! is_wp_error( vkt_action( 'source', array( 'kind' => 'domain', 'value' => 'team' ) ) ), 'Source saved' );
$source_id = $wpdb->insert_id;
vkt_action( 'source', array( 'kind' => 'domain', 'value' => 'team' ) );
vkt_assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'sources' ) ), 'Source deduplicated' );
vkt_assert( is_wp_error( vkt_action( 'source', array( 'kind' => 'owner', 'value' => 'not-an-id' ) ) ), 'Invalid owner source rejected' );
vkt_assert( is_wp_error( vkt_action( 'source', array( 'kind' => 'query', 'value' => 'товары для дома' ) ) ), 'Search sources rejected without a user token' );
vkt_assert( VKT_Collector::run()['processed'] === 0, 'Paused scheduled collector does no work' );
VKT_Store::unlock( 'api' );
$collected = VKT_Collector::run( true );
vkt_assert( ! is_wp_error( $collected ) && 1 === $collected['processed'], 'Manual collection seeds and runs due source while paused' );
// ——— Частота сбора и порядок очереди ———
vkt_assert( 1 === VKT_Plugin::snap_hours( 1 ) && 2 === VKT_Plugin::snap_hours( 2.5 ) && 6 === VKT_Plugin::snap_hours( 8 ) && 24 === VKT_Plugin::snap_hours( 100 ), 'Collection interval snaps to an allowed value' );
update_option( 'vkt_settings', array( 'interval' => 180 ), false );
vkt_assert( 3 === VKT_Plugin::settings()['source_hours'] && 3 === VKT_Plugin::settings()['video_hours'], 'Legacy interval in minutes migrates to hours' );
vkt_assert( ! is_wp_error( vkt_action( 'settings', array( 'source_hours' => 2, 'video_hours' => 12 ) ) ), 'Interval selectors saved' );
vkt_assert( 2 === VKT_Plugin::settings()['source_hours'] && 12 === VKT_Plugin::settings()['video_hours'], 'Sources and videos keep separate intervals' );
// Источник обязан обгонять ролик в очереди, даже если встал в неё позже.
$wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( 'jobs' ) );
VKT_Collector::enqueue( 'video', $id );
VKT_Collector::enqueue( 'source', $source_id );
$order = $wpdb->get_col( "SELECT kind FROM " . VKT_Store::table( 'jobs' ) . " WHERE status='pending' ORDER BY kind<>'source',available_at,id" );
vkt_assert( 'source' === $order[0], 'Source jobs run before video jobs' );
update_option( 'vkt_settings', array_merge( VKT_Plugin::defaults(), array( 'paused' => true ) ), false );
// ——— Посты сообществ ———
$post_row = $wpdb->get_row( 'SELECT * FROM ' . VKT_Store::table( 'posts' ), ARRAY_A );
vkt_assert( $post_row && 77 === (int) $post_row['post_id'], 'Wall post stored alongside its video' );
vkt_assert( 1000 === (int) $post_row['views'] && 40 === (int) $post_row['likes'] && 4 === (int) $post_row['reposts'], 'Post counters stored from wall.get' );
vkt_assert( 'Обогреватель кварцевый' === $post_row['product_title'] && abs( (float) $post_row['product_price'] - 5906 ) < .01 && abs( (float) $post_row['product_old_price'] - 10500 ) < .01, 'Market attachment parsed with prices in roubles' );
vkt_assert( '3643961413' === $post_row['product_sku'] && '-123_9' === $post_row['market_id'], 'Market SKU and id stored' );
vkt_assert( str_starts_with( $post_row['link_url'], 'https://ozon.ru/product/123' ), 'External link extracted from post text' );
vkt_assert( 'https://vk.test/photo-800.jpg' === $post_row['thumbnail'], 'Widest attachment image chosen as preview' );
vkt_assert( str_contains( $post_row['media'], 'video' ) && str_contains( $post_row['media'], 'market' ), 'Attachment types listed' );
vkt_assert( abs( (float) $post_row['err'] - 5.0 ) < .01, 'ERR counted as engagement over reach' );
vkt_assert( abs( (float) $post_row['viral'] - 0.2 ) < .01, 'Virality counted as reach over members' );
vkt_assert( null === $post_row['velocity'] && null === $post_row['g1'], 'First post measurement has no growth yet' );
$source_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'sources' ) . ' WHERE id=%d', $source_id ), ARRAY_A );
vkt_assert( 5000 === (int) $source_row['members'] && 'Тестовое сообщество' === $source_row['title'], 'Community card synced from extended wall.get' );
vkt_assert( 'https://vk.test/avatar.jpg' === $source_row['photo'] && $source_row['synced_at'], 'Community avatar and sync time stored' );
$post_id = (int) $post_row['id'];
// Сдвигаем историю на сутки назад и снимаем второй замер: появляются скорость и суточное окно.
$wpdb->update( VKT_Store::table( 'post_snapshots' ), array( 'measured_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'post_id' => $post_id ) );
VKT_Posts::save( array( 'id' => 77, 'owner_id' => -123, 'text' => 'Тот же пост', 'views' => array( 'count' => 25000 ), 'likes' => array( 'count' => 90 ) ), $source_id, 5000 );
$post_row = $wpdb->get_row( 'SELECT * FROM ' . VKT_Store::table( 'posts' ), ARRAY_A );
vkt_assert( 24000 === (int) $post_row['g1'] && 24000 === (int) $post_row['growth'], 'Daily window and growth counted from the previous measurement' );
vkt_assert( abs( (float) $post_row['velocity'] - 1000 ) < 1, 'Post velocity normalized per hour' );
vkt_assert( null === $post_row['g7'], 'Window without a reference measurement stays unknown' );
VKT_Posts::save( array( 'id' => 77, 'owner_id' => -123, 'views' => array( 'count' => 100 ) ), $source_id, 5000 );
vkt_assert( null === $wpdb->get_row( 'SELECT velocity FROM ' . VKT_Store::table( 'posts' ) )->velocity, 'Counter reset is not ranked as post growth' );
// Повторный обход без изменений счётчиков не должен добавлять точку в историю.
$snapshots_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'post_snapshots' ) );
VKT_Posts::save( array( 'id' => 77, 'owner_id' => -123, 'views' => array( 'count' => 100 ) ), $source_id, 5000 );
vkt_assert( $snapshots_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'post_snapshots' ) ), 'Unchanged counters do not duplicate history' );
// Прореживание: два замера в одном часе трёхдневной давности схлопываются в один.
$wpdb->insert( VKT_Store::table( 'post_snapshots' ), array( 'post_id' => $post_id, 'views' => 10, 'measured_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
$wpdb->insert( VKT_Store::table( 'post_snapshots' ), array( 'post_id' => $post_id, 'views' => 20, 'measured_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS + 600 ) ) );
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'post_snapshots' ) );
VKT_Posts::prune();
$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'post_snapshots' ) );
vkt_assert( $after < $before, 'Old measurements thinned by age' );
vkt_assert( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'post_snapshots' ) . ' WHERE post_id=%d AND measured_at>=%s', $post_id, gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ) >= 1, 'Recent measurements survive thinning' );
// ——— Товар из текста поста и артикул из адреса ———
vkt_assert( 'Обогреватель кварцевый ТеплоВита' === VKT_Posts::from_text( $post_row['text'] )['text_product'], 'Product hint can be pulled from the post text' );
vkt_assert( '' === $post_row['text_product'] && 'market' === $post_row['product_source'], 'Recognized attachment is not mixed with a text hint' );
vkt_assert( '3615443329' === VKT_Links::sku_from_url( 'https://www.ozon.ru/product/krossovki-nike-3615443329/?advRef=abc' ), 'Ozon article parsed from the URL' );
vkt_assert( '141692400' === VKT_Links::sku_from_url( 'https://www.wildberries.ru/catalog/141692400/detail.aspx' ), 'Wildberries article parsed from the URL' );
vkt_assert( '' === VKT_Links::sku_from_url( 'https://example.com/thing' ), 'Unknown shop has no article' );
vkt_assert( 600.0 === VKT_Posts::from_text( 'Футболки за 600 рублей: https://ozon.ru/product/1' )['text_price'], 'Price parsed from the post text' );
vkt_assert( null === VKT_Posts::from_text( 'Кроссовки https://vk.ru/wall-1_2 — дождевик за 234 рубля' )['text_price'], 'Price after the link is not attributed to the first product' );
// Сервис рендеринга: адрес проверяется и наружу не отдаётся.
vkt_assert( is_wp_error( VKT_Links::save_proxy( 'http://api.test/?url={url}' ) ), 'Plain HTTP render service rejected' );
vkt_assert( is_wp_error( VKT_Links::save_proxy( 'https://api.test/?key=1' ) ), 'Render service without {url} rejected' );
vkt_assert( true === VKT_Links::save_proxy( 'https://api.test/?key=secret&url={url}' ), 'Render service saved' );
vkt_assert( 'api.test' === VKT_Links::proxy_host(), 'Only the render service host is exposed' );
vkt_assert( ! str_contains( wp_json_encode( VKT_Plugin::public_settings() ), 'secret' ), 'Render service key never reaches the interface' );
vkt_assert( true === VKT_Links::save_proxy( '' ), 'Render service cleared' );
// ——— Ссылки на товары ———
$link_row = $wpdb->get_row( 'SELECT * FROM ' . VKT_Store::table( 'shop_links' ), ARRAY_A );
vkt_assert( $link_row && 'ozon.ru' === $link_row['domain'] && 'Ozon' === $link_row['shop'], 'Post link registered with a recognized shop' );
vkt_assert( (int) $post_row['link_id'] === (int) $link_row['id'], 'Post references the link record' );
vkt_assert( 'ok' === $link_row['status'] && 'Обогреватель кварцевый ТеплоВита 600Вт' === $link_row['title'] && abs( (float) $link_row['price'] - 5906 ) < .01, 'Shop page parsed through the collector' );
vkt_assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'shop_links' ) ), 'Same URL is stored once' );
vkt_assert( 0 === VKT_Links::register( 'https://vk.com/market-123_9' ), 'Internal VK links are not queued for fetching' );
vkt_assert( 'manual' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . VKT_Store::table( 'shop_links' ) . ' WHERE id=%d', VKT_Links::register( 'https://example.com/thing' ) ) ), 'Unknown domain waits for a manual run' );
$shop_mode = 'guard';
$guard_id = VKT_Links::register( 'https://www.ozon.ru/product/guard' );
vkt_assert( is_wp_error( VKT_Links::resolve( $guard_id ) ), 'Anti-bot page is reported as an error' );
vkt_assert( 'blocked' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . VKT_Store::table( 'shop_links' ) . ' WHERE id=%d', $guard_id ) ), 'Anti-bot page marked as blocked' );
vkt_assert( '' === $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM ' . VKT_Store::table( 'shop_links' ) . ' WHERE id=%d', $guard_id ) ), 'Anti-bot title is never stored as a product' );
$shop_mode = 'blocked';
$http_id = VKT_Links::register( 'https://www.ozon.ru/product/403' );
VKT_Links::resolve( $http_id );
vkt_assert( 'blocked' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . VKT_Store::table( 'shop_links' ) . ' WHERE id=%d', $http_id ) ), 'HTTP 403 marked as blocked' );
$shop_mode = 'success';
// Миграция старых постов: ссылка есть, записи в справочнике нет.
$wpdb->update( VKT_Store::table( 'posts' ), array( 'link_id' => null ), array( 'id' => $post_id ) );
vkt_assert( 1 === VKT_Links::backfill(), 'Backfill links existing posts' );
vkt_assert( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT link_id FROM ' . VKT_Store::table( 'posts' ) . ' WHERE id=%d', $post_id ) ) > 0, 'Post link restored by backfill' );
vkt_assert( 1 === VKT_Posts::query( array( 'filters' => array( 'shop' => 'Ozon' ) ) )['total'], 'Shop filter narrows the list' );
vkt_assert( 1 === VKT_Posts::query( array( 'filters' => array( 'recognized' => 1 ) ) )['total'], 'Recognized-only filter works' );
$query = VKT_Posts::query( array( 'search' => 'Обогреватель' ) );
vkt_assert( 1 === $query['total'] && $query['posts'], 'Post search matches text and product' );
vkt_assert( 0 === VKT_Posts::query( array( 'filters' => array( 'views_min' => 10000000 ) ) )['total'], 'Range filter narrows the list' );
vkt_assert( 1 === VKT_Posts::query( array( 'filters' => array( 'with_price' => 1, 'with_product' => 1 ) ) )['total'], 'Product flags filter the list' );
vkt_assert( 0 === VKT_Posts::query( array( 'source' => $source_id + 500 ) )['total'], 'Foreign community returns nothing' );
$communities = VKT_Posts::communities();
vkt_assert( $communities && 1 === (int) $communities[0]['posts'] && 5000 === (int) $communities[0]['members'], 'Community summary aggregates its posts' );
vkt_assert( count( VKT_Posts::history( $post_id ) ) >= 1, 'Post history returns measurements' );
vkt_assert( isset( VKT_Store::state()['stats']['posts'] ), 'Overview stats expose post counters' );
VKT_Collector::enqueue( 'video', $id );
VKT_Collector::enqueue( 'video', $id );
vkt_assert( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . VKT_Store::table( 'jobs' ) . " WHERE kind='video'" ), 'Pending jobs deduplicated' );
$mock_mode = 'retry';
VKT_Store::unlock( 'api' );
VKT_Collector::run( true );
$job = $wpdb->get_row( "SELECT * FROM " . VKT_Store::table( 'jobs' ) . " WHERE kind='video'", ARRAY_A );
vkt_assert( 'pending' === $job['status'] && 1 === (int) $job['attempts'] && strtotime( $job['available_at'] . ' UTC' ) > time(), 'Temporary errors delayed for retry' );
$wpdb->update( VKT_Store::table( 'jobs' ), array( 'attempts' => 3, 'available_at' => gmdate( 'Y-m-d H:i:s', time()-1 ) ), array( 'id' => $job['id'] ) );
VKT_Store::unlock( 'api' );
VKT_Collector::run( true );
$job = $wpdb->get_row( "SELECT * FROM " . VKT_Store::table( 'jobs' ) . " WHERE kind='video'", ARRAY_A );
vkt_assert( 'failed' === $job['status'] && 4 === (int) $job['attempts'], 'Retry limit stops after four attempts' );
$wpdb->update( VKT_Store::table( 'videos' ), array( 'next_run' => gmdate( 'Y-m-d H:i:s', time()-1 ) ), array( 'id' => $id ) );
VKT_Store::unlock( 'api' );
vkt_assert( 0 === VKT_Collector::run( true )['processed'], 'Failed job is not silently restarted by seed' );
vkt_action( 'retry', array( 'id' => $job['id'] ) );
$mock_mode = 'denied';
VKT_Store::unlock( 'api' );
VKT_Collector::run( true );
$job = $wpdb->get_row( "SELECT * FROM " . VKT_Store::table( 'jobs' ) . " WHERE kind='video'", ARRAY_A );
vkt_assert( 'failed' === $job['status'], 'Permanent VK permission errors are not retried automatically' );
foreach ( array( 'transport', 'malformed' ) as $mock_mode ) {
    VKT_Store::unlock( 'api' );
    vkt_assert( is_wp_error( VKT_API::request( 'video.get' ) ), 'Controlled ' . $mock_mode . ' error' );
}
vkt_assert( ! str_contains( wp_json_encode( VKT_Store::state() ), $token ), 'State and logs contain no token' );

// --- Пользовательский токен VK ID: режим, точечный замер и автообновление ---
vkt_assert( is_wp_error( VKT_API::save_token( 'USER_TOKEN_0123456789ABCDEF', 'user', array( 'refresh_token' => 'refresh-1' ) ) ), 'Incomplete user token rejected' );
$user_token = 'USER_TOKEN_0123456789ABCDEF';
vkt_assert( true === VKT_API::save_token( $user_token, 'user' ), 'Implicit-flow user token can be saved without refresh data' );
vkt_assert( false === VKT_API::status()['refreshable'], 'Implicit-flow token is reported as non-refreshable' );
vkt_assert( true === VKT_API::save_token( $user_token, 'user', array( 'refresh_token' => 'refresh-1', 'device_id' => 'device-1', 'client_id' => 'client-1', 'expires_in' => 3600 ) ), 'User token with refresh pair saved' );
vkt_assert( 'user' === VKT_API::mode(), 'Mode switches to user' );
$status = VKT_API::status();
vkt_assert( 'user' === $status['mode'] && $status['expires_in'] > 3500, 'Status exposes mode and remaining lifetime' );
$public = wp_json_encode( VKT_Plugin::public_settings() );
vkt_assert( ! str_contains( $public, $user_token ) && ! str_contains( $public, 'refresh-1' ) && ! str_contains( $public, 'device-1' ), 'Public settings never expose user token secrets' );

// --- Свои сообщества и автопостинг ---
VKT_Store::unlock( 'api' );
$seen = array();
$groups_synced = VKT_Publisher::sync_groups();
vkt_assert( ! is_wp_error( $groups_synced ) && 1 === $groups_synced['synced'], 'Managed communities sync through groups.get filter=editor' );
$publishing_group = $wpdb->get_row( 'SELECT * FROM ' . VKT_Store::table( 'publishing_groups' ), ARRAY_A );
vkt_assert( 987 === (int) $publishing_group['group_id'] && 1 === (int) $publishing_group['can_post'], 'Managed community and write capability stored separately from sources' );
vkt_assert( 'editor' === $seen[0]['args']['body']['filter'] && 'groups.get' === basename( $seen[0]['url'] ), 'Managed-community request uses the documented editor filter' );
vkt_assert( is_wp_error( VKT_Publisher::create( array( 'groups' => array( $publishing_group['id'] ), 'attachments' => 'javascript:alert(1)' ) ) ), 'Unsafe publishing attachment rejected' );
update_option( 'vkt_settings', array_merge( VKT_Plugin::settings(), array( 'publishing_review' => true ) ), false );
$forced = vkt_action( 'publishing_create', array(
    'groups' => array( $publishing_group['id'] ),
    'message' => 'Попытка обойти ручную проверку',
    'approval_required' => false,
    'origin' => 'agents',
) );
$forced_post = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'outbound_posts' ) . ' WHERE id=%d', $forced['id'] ), ARRAY_A );
vkt_assert( 'draft' === $forced_post['status'] && 'manual' === $forced_post['origin'], 'REST cannot bypass manual approval or impersonate an agent' );
VKT_Publisher::cancel( $forced['id'] );
$scheduled = VKT_Publisher::create( array( 'groups' => array( $publishing_group['id'] ), 'message' => 'Запланированная запись', 'scheduled_at' => gmdate( DATE_ATOM, time() + HOUR_IN_SECONDS ), 'approval_required' => false, 'origin' => 'agents' ) );
vkt_assert( ! is_wp_error( $scheduled ) && 'draft' === $scheduled['status'], 'Agent publication cannot bypass manual approval' );
$scheduled_delivery = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'outbound_deliveries' ) . ' WHERE outbound_post_id=%d', $scheduled['id'] ), ARRAY_A );
vkt_assert( 'waiting_approval' === $scheduled_delivery['status'], 'Cron cannot see an unapproved delivery' );
$scheduled_approval = VKT_Publisher::approve( $scheduled['id'] );
vkt_assert( ! is_wp_error( $scheduled_approval ) && 'scheduled' === $scheduled_approval['status'], 'Approved future publication enters the schedule' );
update_option( 'vkt_settings', array_merge( VKT_Plugin::settings(), array( 'publishing_review' => false ) ), false );
VKT_Store::unlock( 'api' );
VKT_Store::unlock( 'publisher' );
$seen = array();
$published = VKT_Publisher::create( array( 'groups' => array( $publishing_group['id'] ), 'message' => 'Опубликовать сейчас', 'attachments' => 'photo-987_42', 'signed' => 1 ) );
vkt_assert( ! is_wp_error( $published ) && 'published' === $published['status'] && 1 === $published['processed'], 'Default mode publishes immediately without manual review' );
$delivery = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'outbound_deliveries' ) . ' WHERE outbound_post_id=%d', $published['id'] ), ARRAY_A );
vkt_assert( 'published' === $delivery['status'] && 501 === (int) $delivery['vk_post_id'], 'VK post ID and successful per-group status stored' );
$wall_post = array_values( array_filter( $seen, static fn( $entry ) => str_ends_with( $entry['url'], '/wall.post' ) ) )[0];
vkt_assert( -987 === (int) $wall_post['args']['body']['owner_id'] && 1 === (int) $wall_post['args']['body']['from_group'] && $delivery['guid'] === $wall_post['args']['body']['guid'], 'wall.post uses negative owner, community authorship and stable delivery guid' );
$publishing_state = VKT_Publisher::state();
vkt_assert( isset( $publishing_state['posts'][0]['deliveries'] ), 'Publishing state exposes per-group results' );
vkt_assert( ! str_contains( wp_json_encode( $publishing_state ), $delivery['guid'] ), 'Publishing state does not expose idempotency keys' );

// Локальный файл: плагин сам загружает его в VK и подставляет ID вложения.
vkt_assert( is_wp_error( VKT_Publisher::create( array( 'groups' => array( $publishing_group['id'] ), 'message' => 'Нет такого файла', 'media' => array( 99999999 ) ) ) ), 'Missing local file rejected before the queue' );
$uploads = wp_upload_dir();
$media_path = trailingslashit( $uploads['path'] ) . 'vkt-test-media.jpg';
$image = imagecreatetruecolor( 12, 12 );
imagejpeg( $image, $media_path );
imagedestroy( $image );
$attachment_id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Тестовый файл', 'post_status' => 'inherit' ), $media_path );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $media_path ) );
VKT_Store::unlock( 'api' );
VKT_Store::unlock( 'publisher' );
$seen = array();
$with_media = VKT_Publisher::create( array( 'groups' => array( $publishing_group['id'] ), 'message' => 'Запись с файлом сайта', 'media' => array( $attachment_id ) ) );
vkt_assert( ! is_wp_error( $with_media ) && 'published' === $with_media['status'], 'Post with a local file publishes without a manual VK attachment ID' );
$media_methods = array_map( static fn( $entry ) => basename( parse_url( $entry['url'], PHP_URL_PATH ) ), $seen );
vkt_assert( in_array( 'photos.getWallUploadServer', $media_methods, true ) && in_array( 'photos.saveWallPhoto', $media_methods, true ), 'Local file travels through the documented VK upload flow' );
$upload_call = array_values( array_filter( $seen, static fn( $entry ) => str_contains( $entry['url'], 'pu.vk.test' ) ) )[0];
vkt_assert( str_contains( (string) ( $upload_call['args']['headers']['Content-Type'] ?? '' ), 'multipart/form-data; boundary=' ), 'File body is sent as multipart/form-data' );
$media_wall_post = array_values( array_filter( $seen, static fn( $entry ) => str_ends_with( $entry['url'], '/wall.post' ) ) )[0];
vkt_assert( 'photo-987_777_accesskey1' === $media_wall_post['args']['body']['attachments'], 'Saved photo ID is substituted into wall.post automatically' );
$media_delivery = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'outbound_deliveries' ) . ' WHERE outbound_post_id=%d', $with_media['id'] ), ARRAY_A );
vkt_assert( 'photo-987_777_accesskey1' === $media_delivery['media_attachments'], 'Resolved attachment cached so a retry does not upload the file twice' );
$media_state = VKT_Publisher::state();
vkt_assert( $attachment_id === (int) ( $media_state['posts'][0]['media_items'][0]['id'] ?? 0 ), 'Publishing state shows the attached local file' );
vkt_assert( ! isset( $media_state['posts'][0]['media_items'][0]['path'] ), 'Server paths are never exposed to the browser' );
wp_delete_attachment( $attachment_id, true );

// Точечный замер уже отслеживаемого ролика идёт через video.get, а не через переобход стены.
$mock_mode = 'success';
$wpdb->update( VKT_Store::table( 'jobs' ), array( 'status' => 'pending', 'attempts' => 0, 'available_at' => gmdate( 'Y-m-d H:i:s' ), 'message' => '' ), array( 'id' => $job['id'] ) );
VKT_Store::unlock( 'api' );
VKT_Store::unlock( 'collector' );
$seen = array();
$collected = VKT_Collector::run( true );
vkt_assert( ! is_wp_error( $collected ) && 1 === $collected['processed'], 'User-mode point measurement processes the job' );
vkt_assert( 1 === count( $seen ) && str_ends_with( $seen[0]['url'], '/video.get' ), 'User mode measures a tracked video via video.get, not wall.get' );
vkt_assert( '-123_456' === $seen[0]['args']['body']['videos'], 'video.get requested with the exact owner_id_video_id' );

// Автообновление: до истечения меньше 5 минут — плагин обновляет пару токенов через id.vk.ru перед запросом.
VKT_API::save_token( $user_token, 'user', array( 'refresh_token' => 'refresh-1', 'device_id' => 'device-1', 'client_id' => 'client-1', 'expires_in' => 120 ) );
$seen = array();
VKT_Store::unlock( 'api' );
$refreshed = VKT_API::request( 'wall.get', array( 'domain' => 'team', 'count' => 10 ), 'test' );
vkt_assert( ! is_wp_error( $refreshed ), 'Request succeeds after automatic refresh' );
vkt_assert( 1 === count( array_filter( $seen, static fn( $entry ) => str_starts_with( $entry['url'], 'https://id.vk.ru/' ) ) ), 'Expiring user token is refreshed via id.vk.ru before the VK request' );
vkt_assert( VKT_API::status()['expires_in'] > 3500, 'Refreshed token stores the new expiry' );

// Обновление, которое отклонил id.vk.ru, не должно ронять плагин — только понятная ошибка.
VKT_API::save_token( $user_token, 'user', array( 'refresh_token' => 'refresh-1', 'device_id' => 'device-1', 'client_id' => 'client-1', 'expires_in' => 60 ) );
$refresh_mode = 'fail';
VKT_Store::unlock( 'api' );
$denied = VKT_API::request( 'wall.get', array( 'domain' => 'team', 'count' => 10 ), 'test' );
vkt_assert( is_wp_error( $denied ) && 'refresh_failed' === $denied->get_error_code(), 'Rejected refresh returns a controlled error instead of crashing' );
$refresh_mode = 'success';

// Возвращаемся к сервисному ключу — оставшиеся проверки и очистка написаны под service-режим.
vkt_assert( true === VKT_API::save_token( $token ), 'Switching back to the service key' );
vkt_assert( 'service' === VKT_API::mode(), 'Mode returns to service' );
vkt_assert( is_wp_error( VKT_Publisher::create( array( 'groups' => array( $publishing_group['id'] ), 'message' => 'Нельзя сервисным ключом' ) ) ), 'Service key cannot create publishing jobs' );

// Test actual WordPress REST dispatch and permission callbacks.
$server = rest_get_server();
wp_set_current_user( 0 );
$request = new WP_REST_Request( 'GET', '/vk-trends/v1/state' );
vkt_assert( $server->dispatch( $request )->get_status() >= 400, 'Anonymous REST read denied' );
$request = new WP_REST_Request( 'POST', '/vk-trends/v1/action' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body( '{"action":"collect"}' );
vkt_assert( $server->dispatch( $request )->get_status() >= 400, 'Anonymous REST mutation denied' );
$admin = get_user_by( 'login', 'trendsadmin' );
wp_set_current_user( $admin->ID );
$request = new WP_REST_Request( 'GET', '/vk-trends/v1/state' );
vkt_assert( $server->dispatch( $request )->get_status() >= 400, 'Missing nonce denied even for admin' );
$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
vkt_assert( 200 === $server->dispatch( $request )->get_status(), 'Admin with valid nonce can read state' );
$subscriber_id = wp_insert_user( array( 'user_login' => 'vkt_subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
if ( is_wp_error( $subscriber_id ) ) { $subscriber_id = get_user_by( 'login', 'vkt_subscriber' )->ID; }
wp_set_current_user( $subscriber_id );
$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
vkt_assert( $server->dispatch( $request )->get_status() >= 400, 'Subscriber with nonce cannot read dashboard' );
wp_set_current_user( $admin->ID );
vkt_action( 'delete', array( 'entity' => 'videos', 'id' => $id ) );
vkt_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'snapshots' ) ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'links' ) ), 'Deleting a video removes history and links' );

// Leave the isolated browser preview with genuine empty states.
foreach ( array( 'videos', 'snapshots', 'posts', 'post_snapshots', 'post_products', 'shop_links', 'products', 'links', 'sources', 'jobs', 'logs', 'publishing_groups', 'outbound_posts', 'outbound_deliveries' ) as $table ) { $wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( $table ) ); }
if ( file_exists( $media_path ) ) { @unlink( $media_path ); }
delete_option( 'vkt_token' );
delete_option( 'vkt_last_run' );
VKT_Store::unlock( 'api' );
VKT_Store::unlock( 'collector' );
VKT_Store::unlock( 'publisher' );
echo "\nAll $checks integration checks passed.\n";

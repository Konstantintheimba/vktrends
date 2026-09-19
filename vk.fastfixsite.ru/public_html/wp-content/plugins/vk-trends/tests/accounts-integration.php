<?php
/**
 * Личные кабинеты на настоящем WordPress и MySQL.
 * Запуск только в изолированной установке: wp eval-file tests/accounts-integration.php
 * Сеть подменяется: ни один запрос не уходит в VK.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'http://127.0.0.1:8097' !== home_url() ) {
    throw new RuntimeException( 'Only the isolated VK Trends test installation is allowed.' );
}
global $wpdb;
$checks = 0;
$ok = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
    ++$checks;
    echo "PASS: $message\n";
};
foreach ( array( 'videos', 'snapshots', 'posts', 'post_snapshots', 'post_products', 'products', 'links', 'sources', 'jobs', 'publishing_groups', 'outbound_posts', 'outbound_deliveries', 'subscriptions', 'user_videos' ) as $table ) {
    $wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( $table ) );
}
foreach ( get_users( array( 'role__in' => array( 'vkt_member', 'subscriber' ) ) ) as $old ) { wp_delete_user( $old->ID ); }
$settings = VKT_Plugin::settings();
$settings['member_sources'] = 2;
$settings['ai_text_daily'] = 2;
$settings['ai_media_daily'] = 1;
update_option( 'vkt_settings', $settings, false );
VKT_Store::unlock( 'api' );
VKT_Store::unlock( 'publisher' );
VKT_Store::unlock( 'collector' );

$admin = get_user_by( 'login', 'admin' )->ID;
$member = static function ( $login, $status ) {
    $id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => VKT_Account::ROLE, 'display_name' => $login ) );
    update_user_meta( $id, 'vkt_status', $status );
    return (int) $id;
};
$a = $member( 'member_a', 'active' );
$b = $member( 'member_b', 'active' );
$c = $member( 'member_c', 'pending' );
$subscriber = (int) wp_insert_user( array( 'user_login' => 'plain_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$act = static function ( $user, $action, $data = array() ) {
    wp_set_current_user( $user );
    VKT_Store::unlock( 'api' );
    $request = new WP_REST_Request( 'POST', '/vk-trends/v1/action' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( array_merge( array( 'action' => $action ), $data ) ) );
    return VKT_Plugin::action( $request );
};
$rest = static function ( $user, $route ) {
    wp_set_current_user( $user );
    $request = new WP_REST_Request( 'GET', '/vk-trends/v1/' . $route );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    return rest_do_request( $request );
};

// Сеть: каждый токен получает свой список групп, запись в VK фиксируется.
$seen = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$seen ) {
    $seen[] = array( 'url' => $url, 'body' => $args['body'] ?? array() );
    $method = str_starts_with( $url, 'https://api.vk.com/method/' ) ? basename( $url ) : '';
    $token = (string) ( $args['body']['access_token'] ?? '' );
    $reply = static fn( $body ) => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
    if ( 'groups.get' === $method ) {
        $ids = str_contains( $token, 'USERA' ) ? array( 111, 222 ) : array( 222, 333 );
        return $reply( array( 'response' => array( 'count' => count( $ids ), 'items' => array_map( static fn( $id ) => array( 'id' => $id, 'name' => 'Группа ' . $id, 'screen_name' => 'g' . $id, 'admin_level' => 3, 'can_post' => 1 ), $ids ) ) ) );
    }
    if ( 'groups.getTokenPermissions' === $method ) {
        return $reply( str_starts_with( $token, 'COMMUNITY' ) ? array( 'response' => array( 'permissions' => array( array( 'name' => 'wall' ) ) ) ) : array( 'error' => array( 'error_code' => 27, 'error_msg' => 'group auth' ) ) );
    }
    if ( 'groups.getById' === $method ) {
        $wanted = explode( ',', (string) ( $args['body']['group_ids'] ?? $args['body']['group_id'] ?? '' ) );
        return $reply( array( 'response' => array( 'groups' => array_map( static fn( $id ) => array( 'id' => (int) ltrim( $id, 'club' ) ?: 900, 'name' => 'Сообщество ' . $id ), $wanted ) ) ) );
    }
    if ( 'wall.post' === $method ) {
        return $reply( array( 'response' => array( 'post_id' => 700 + count( $seen ) ) ) );
    }
    if ( 'wall.get' === $method ) {
        $video = array( 'id' => 456, 'owner_id' => -123, 'title' => 'Ролик', 'duration' => 30, 'views' => 100, 'type' => 'short_video' );
        return $reply( array( 'response' => array( 'count' => 1, 'items' => array( array( 'id' => 1, 'owner_id' => -123, 'date' => time(), 'text' => 'Пост', 'attachments' => array( array( 'type' => 'video', 'video' => $video ) ) ) ) ) ) );
    }
    if ( str_starts_with( $url, 'https://api.vk.com/' ) || str_starts_with( $url, 'https://id.vk.ru/' ) ) {
        return $reply( array( 'error' => array( 'error_code' => 100, 'error_msg' => 'unexpected in test' ) ) );
    }
    return $pre;
}, 10, 3 );

// ——— Статусы и доступ ———
$ok( 'active' === VKT_Account::status( $admin ) && 'active' === VKT_Account::status( $a ), 'Администратор и одобренный участник работают' );
$ok( 'pending' === VKT_Account::status( $c ) && ! VKT_Account::can_use( $c ), 'Заявка без одобрения кабинета не открывает' );
$ok( 'denied' === VKT_Account::status( $subscriber ) && 'guest' === VKT_Account::status( 0 ), 'Посторонняя роль и гость — без доступа' );
$ok( 403 === $rest( $c, 'state' )->get_status() || 401 === $rest( $c, 'state' )->get_status(), 'REST закрыт для заявки на рассмотрении' );
$ok( 200 === $rest( $a, 'state' )->get_status(), 'REST открыт одобренному участнику' );
$ok( in_array( $rest( $a, 'users' )->get_status(), array( 401, 403 ), true ), 'Список пользователей участнику закрыт' );
$ok( 200 === $rest( $admin, 'users' )->get_status(), 'Список пользователей открыт администратору' );
foreach ( array( 'api' => array( 'method' => 'users.get' ), 'collect' => array(), 'retry' => array( 'id' => 1 ), 'user_status' => array( 'id' => $b, 'status' => 'blocked' ) ) as $action => $data ) {
    $result = $act( $a, $action, $data );
    $ok( is_wp_error( $result ) && 403 === ( $result->get_error_data()['status'] ?? 0 ), "Действие $action участнику закрыто" );
}
$result = $act( $a, 'settings', array( 'paused' => false ) );
$ok( is_wp_error( $result ) && 403 === $result->get_error_data()['status'], 'Общие настройки сбора участник не меняет' );
$result = $act( $a, 'settings', array( 'community_id' => 222, 'publishing_review' => false ) );
$ok( ! is_wp_error( $result ) && 222 === (int) get_user_meta( $a, 'vkt_community_id', true ) && 222 === $result['community_id'], 'ID своего сообщества — личная настройка' );
wp_set_current_user( $b );
$ok( 0 === VKT_Community::group_id(), 'Сообщество участника не видно другому' );

// ——— Ключи ———
$saved = $act( $a, 'token_save', array( 'slot' => 'service', 'token' => 'SERVICE_' . str_repeat( 'z', 30 ) ) );
$ok( is_wp_error( $saved ) && 403 === $saved->get_error_data()['status'], 'Общий сервисный ключ участник не заменит' );
wp_set_current_user( $admin );
VKT_Tokens::save( 'service', 'SERVICE_' . str_repeat( 's', 30 ) );
VKT_Tokens::save( 'app_secret', 'SECRET_' . str_repeat( 'x', 20 ) );
wp_set_current_user( $a );
VKT_Tokens::save( 'user', 'vk1.a.USERA' . str_repeat( 'a', 50 ) );
wp_set_current_user( $b );
VKT_Tokens::save( 'user', 'vk1.a.USERB' . str_repeat( 'b', 50 ) );
$ok( str_contains( VKT_Tokens::token( 'user' ), 'USERB' ) && str_starts_with( VKT_Tokens::token( 'service' ), 'SERVICE_' ), 'У каждого свой токен при общем сервисном ключе' );
wp_set_current_user( $a );
$ok( str_contains( VKT_Tokens::token( 'user' ), 'USERA' ), 'Токен первого кабинета не затёрт вторым' );
$public = VKT_Plugin::public_settings();
$slots = array_column( $public['tokens'], 'slot' );
$ok( array( 'user', 'community' ) === $slots, 'Участнику видны только его слоты' );
$json = wp_json_encode( $public );
$ok( ! str_contains( $json, 'SERVICE_' ) && ! str_contains( $json, 'SECRET_' ) && '' === $public['token_preview'], 'Общие ключи сайта участнику не видны даже огрызком' );
$ok( ! str_contains( $json, VKT_Tokens::token( 'user' ) ) && str_starts_with( $public['tokens'][0]['preview'], 'vk1.a.USERAa' ), 'Свой токен виден только огрызком' );
$ok( false === $public['account']['is_admin'] && 2 === $public['limits']['sources'], 'Настройки сообщают роль и лимит кабинета' );

// ——— Источники: общий обход, личные списки ———
$r1 = $act( $a, 'source', array( 'kind' => 'domain', 'value' => 'team' ) );
$r2 = $act( $b, 'source', array( 'kind' => 'domain', 'value' => 'team' ) );
$ok( ! is_wp_error( $r1 ) && ! is_wp_error( $r2 ) && $r1['id'] === $r2['id'], 'Одно сообщество у двоих — один общий источник' );
$shared = $r1['id'];
$ok( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'sources' ) ), 'В базе один источник на двоих' );
$again = $act( $a, 'source', array( 'kind' => 'domain', 'value' => 'team' ) );
$ok( ! is_wp_error( $again ) && false === $again['added'], 'Повторное добавление не дублирует подписку' );
$solo = $act( $a, 'source', array( 'kind' => 'owner', 'value' => '-555' ) );
$over = $act( $a, 'source', array( 'kind' => 'owner', 'value' => '-556' ) );
$ok( is_wp_error( $over ) && str_contains( $over->get_error_message(), 'Достигнут предел: 2' ), 'Лимит источников кабинета соблюдается' );
$imported = $act( $b, 'sources_import', array( 'list' => "club901\nclub902\nclub903" ) );
$ok( ! is_wp_error( $imported ) && 1 === $imported['added'] && true === $imported['limited'], 'Импорт останавливается на лимите и сообщает об этом' );
$act( $b, 'source_toggle', array( 'id' => $shared, 'enabled' => 0 ) );
$ok( 1 === (int) $wpdb->get_var( 'SELECT enabled FROM ' . VKT_Store::table( 'sources' ) . " WHERE id=$shared" ), 'Пауза одного не останавливает обход для другого' );
$act( $a, 'source_toggle', array( 'id' => $shared, 'enabled' => 0 ) );
$ok( 0 === (int) $wpdb->get_var( 'SELECT enabled FROM ' . VKT_Store::table( 'sources' ) . " WHERE id=$shared" ), 'Пауза у всех — обход останавливается' );
$act( $a, 'source_toggle', array( 'id' => $shared, 'enabled' => 1 ) );
$foreign = $act( $a, 'source_toggle', array( 'id' => (int) $wpdb->get_var( 'SELECT source_id FROM ' . VKT_Store::table( 'subscriptions' ) . " WHERE user_id=$b AND source_id<>$shared" ), 'enabled' => 0 ) );
$ok( is_wp_error( $foreign ) && 404 === $foreign->get_error_data()['status'], 'Чужой источник не переключить' );

// Посты общие, видимость — по подписке.
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( VKT_Store::table( 'posts' ), array( 'owner_id' => -123, 'post_id' => 1, 'source_id' => $shared, 'text' => 'Общий пост', 'thumbnail' => '', 'link_url' => '', 'cards' => '', 'views' => 50, 'measured_at' => $now ) );
$shared_post = $wpdb->insert_id;
$wpdb->insert( VKT_Store::table( 'posts' ), array( 'owner_id' => -555, 'post_id' => 1, 'source_id' => $solo['id'], 'text' => 'Пост только A', 'thumbnail' => '', 'link_url' => '', 'cards' => '', 'views' => 70, 'measured_at' => $now ) );
$solo_post = $wpdb->insert_id;
wp_set_current_user( $a );
$ok( 2 === VKT_Posts::query()['total'] && 2 === count( VKT_Posts::communities() ), 'A видит посты обоих своих источников' );
wp_set_current_user( $b );
$feed = VKT_Posts::query();
$ok( 1 === $feed['total'] && 'Общий пост' === $feed['posts'][0]['text'], 'B видит только общий источник' );
$ok( ! in_array( (string) $solo['id'], array_map( 'strval', array_column( VKT_Posts::communities(), 'id' ) ), true ), 'Сводка сообществ B без чужого источника' );
$ok( 404 === $rest( $b, 'post-history/' . $solo_post )->get_status(), 'История чужого поста закрыта' );
$ok( 200 === $rest( $b, 'post-history/' . $shared_post )->get_status(), 'История своего поста открыта' );
$denied = $act( $b, 'delete', array( 'entity' => 'posts', 'id' => $shared_post ) );
$ok( is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'], 'Общий пост участник не удалит — его видят другие' );
$state_b = ( function () use ( $b ) { wp_set_current_user( $b ); return VKT_Store::state(); } )();
$ok( 1 === (int) $state_b['stats']['posts'] && array() === $state_b['logs'] && array() === $state_b['jobs'], 'Статистика B своя, журнала и очереди сбора нет' );

// ——— Ролики ———
$saved = $act( $a, 'save_video', array( 'video' => 'https://vk.com/video-123_456' ) );
$ok( ! is_wp_error( $saved ) && VKT_Subscriptions::sees_video( $saved['id'], $a ), 'Ручной ролик попал в подборку A' );
$ok( ! VKT_Subscriptions::sees_video( $saved['id'], $b ), 'В подборке B его нет' );
$ok( 404 === $rest( $b, 'history/' . $saved['id'] )->get_status(), 'Динамика чужого ролика закрыта' );
$enqueue = $act( $b, 'enqueue', array( 'id' => $saved['id'] ) );
$ok( is_wp_error( $enqueue ) && 404 === $enqueue->get_error_data()['status'], 'Чужой ролик в очередь не поставить' );
$item = array( 'id' => 999, 'owner_id' => -123, 'title' => 'Новый в источнике', 'views' => 10 );
$from_source = VKT_Store::save_video( $item, $shared );
$ok( VKT_Subscriptions::sees_video( $from_source, $a ) && VKT_Subscriptions::sees_video( $from_source, $b ), 'Новый ролик источника достаётся всем подписчикам' );
$act( $b, 'delete', array( 'entity' => 'videos', 'id' => $from_source ) );
$ok( ! VKT_Subscriptions::sees_video( $from_source, $b ) && VKT_Subscriptions::sees_video( $from_source, $a ), 'Удаление из подборки B не трогает A' );
VKT_Store::save_video( $item, $shared );
$ok( ! VKT_Subscriptions::sees_video( $from_source, $b ), 'Повторный замер не возвращает удалённый ролик' );
$act( $a, 'delete', array( 'entity' => 'videos', 'id' => $from_source ) );
$ok( null === $wpdb->get_var( 'SELECT id FROM ' . VKT_Store::table( 'videos' ) . " WHERE id=$from_source" ), 'Ролик без подборок удаляется целиком' );

// ——— Товары ———
$act( $a, 'product', array( 'title' => 'Лампа A' ) );
$product = (int) $wpdb->get_var( 'SELECT id FROM ' . VKT_Store::table( 'products' ) . " WHERE user_id=$a" );
$link = $act( $b, 'link', array( 'video_id' => $saved['id'], 'product_id' => $product ) );
$ok( is_wp_error( $link ), 'Чужой товар к ролику не привязать' );
$deleted = $act( $b, 'delete', array( 'entity' => 'products', 'id' => $product ) );
$ok( is_wp_error( $deleted ) && 404 === $deleted->get_error_data()['status'], 'Чужой товар не удалить' );
wp_set_current_user( $b );
$ok( array() === VKT_Store::state()['products'], 'Товары A в кабинете B не видны' );

// ——— Автопостинг ———
$sync_a = $act( $a, 'publishing_sync' );
$sync_b = $act( $b, 'publishing_sync' );
$ok( ! is_wp_error( $sync_a ) && ! is_wp_error( $sync_b ) && 2 === $sync_a['synced'] && 2 === $sync_b['synced'], 'Каждый получил свои группы своим токеном' );
$ok( 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE group_id=222' ), 'Одну группу VK ведут оба кабинета' );
$b_group = (int) $wpdb->get_var( 'SELECT id FROM ' . VKT_Store::table( 'publishing_groups' ) . " WHERE user_id=$b AND group_id=333" );
$a_group = (int) $wpdb->get_var( 'SELECT id FROM ' . VKT_Store::table( 'publishing_groups' ) . " WHERE user_id=$a AND group_id=111" );
$a_222 = (int) $wpdb->get_var( 'SELECT id FROM ' . VKT_Store::table( 'publishing_groups' ) . " WHERE user_id=$a AND group_id=222" );
$foreign_post = $act( $a, 'publishing_create', array( 'message' => 'В чужую группу', 'groups' => array( $b_group ) ) );
$ok( is_wp_error( $foreign_post ), 'В группу из чужого кабинета не опубликовать' );
$toggle = $act( $a, 'publishing_group_toggle', array( 'id' => $b_group, 'enabled' => 0 ) );
$ok( is_wp_error( $toggle ) && 404 === $toggle->get_error_data()['status'], 'Чужую группу не выключить' );
$later = gmdate( 'c', time() + 3600 );
$post_a = $act( $a, 'publishing_create', array( 'message' => 'Запись A', 'groups' => array( $a_group ), 'scheduled_at' => $later ) );
$post_b = $act( $b, 'publishing_create', array( 'message' => 'Запись B', 'groups' => array( $b_group ), 'scheduled_at' => $later ) );
$ok( ! is_wp_error( $post_a ) && ! is_wp_error( $post_b ) && 'scheduled' === $post_a['status'], 'Записи поставлены в расписание' );
wp_set_current_user( $b );
$ok( array( 'Запись B' ) === array_column( VKT_Publisher::state()['posts'], 'message' ), 'В очереди B только его запись' );
$cancel = $act( $b, 'publishing_cancel', array( 'id' => $post_a['id'] ) );
$ok( is_wp_error( $cancel ) && 404 === $cancel->get_error_data()['status'], 'Чужую запись не отменить' );
// Пора публиковать: cron без пользователя разбирает очередь обоих кабинетов.
$wpdb->query( 'UPDATE ' . VKT_Store::table( 'outbound_deliveries' ) . " SET available_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "'" );
wp_set_current_user( 0 );
$seen = array();
$run = VKT_Publisher::run_due( 5 );
$posted = array_values( array_filter( $seen, static fn( $call ) => str_ends_with( $call['url'], '/wall.post' ) ) );
$ok( ! is_wp_error( $run ) && 2 === $run['processed'] && 2 === count( $posted ), 'Cron опубликовал записи обоих кабинетов' );
$tokens_used = array();
foreach ( $posted as $call ) { $tokens_used[ (int) $call['body']['owner_id'] ] = (string) $call['body']['access_token']; }
$ok( str_contains( $tokens_used[-111] ?? '', 'USERA' ) && str_contains( $tokens_used[-333] ?? '', 'USERB' ), 'Каждая запись ушла токеном своего автора' );
$ok( 0 === VKT_Account::id(), 'После очереди контекст пользователя вернулся' );
// Ключ сообщества — личный: запись A в свою группу 222 уходит ключом A.
wp_set_current_user( $a );
VKT_Tokens::save( 'community', 'COMMUNITY_A' . str_repeat( 'k', 40 ) );
$seen = array();
$now_a = $act( $a, 'publishing_create', array( 'message' => 'Сразу', 'groups' => array( $a_222 ) ) );
$wall = array_values( array_filter( $seen, static fn( $call ) => str_ends_with( $call['url'], '/wall.post' ) ) );
$ok( ! is_wp_error( $now_a ) && 'published' === $now_a['status'] && str_starts_with( (string) $wall[0]['body']['access_token'], 'COMMUNITY_A' ), 'Своё сообщество публикуется своим ключом сообщества' );

// ——— Медиа ———
$upload = wp_upload_dir();
$file = trailingslashit( $upload['path'] ) . 'vkt-a.png';
file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
$attachment = wp_insert_attachment( array( 'post_title' => 'Файл A', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => $a ), $file );
wp_set_current_user( $b );
$ok( is_wp_error( VKT_Media::validate_ids( array( $attachment ) ) ), 'Чужой файл к записи не приложить' );
$ok( ! in_array( $attachment, array_column( VKT_Media::library( 60 ), 'id' ), true ), 'Чужой файл не виден в медиатеке' );
wp_set_current_user( $a );
$ok( array( $attachment ) === VKT_Media::validate_ids( array( $attachment ) ) && in_array( $attachment, array_column( VKT_Media::library( 60 ), 'id' ), true ), 'Свой файл виден и прикладывается' );
wp_set_current_user( $admin );
$ok( array( $attachment ) === VKT_Media::validate_ids( array( $attachment ) ), 'Администратор работает со всей медиатекой' );

// ——— Лимит xAI ———
wp_set_current_user( $a );
VKT_Account::ai_spend( 'text' );
VKT_Account::ai_spend( 'text' );
$limit = VKT_Account::ai_allow( 'text' );
$ok( is_wp_error( $limit ) && 429 === $limit->get_error_data()['status'] && true === VKT_Account::ai_allow( 'media' ), 'Суточный лимит текстов исчерпан, медиа ещё есть' );
$ok( 0 === VKT_AI::public_status()['quota']['text']['left'], 'Остаток виден в статусе генерации' );
$quota_error = $act( $a, 'ai_text', array( 'prompt' => 'Тема поста' ) );
$ok( is_wp_error( $quota_error ) && 429 === $quota_error->get_error_data()['status'], 'Генерация сверх лимита отклоняется до запроса к xAI' );
wp_set_current_user( $admin );
VKT_Account::ai_spend( 'text' );
$ok( true === VKT_Account::ai_allow( 'text' ) && null === VKT_AI::public_status()['quota'], 'У администратора лимита нет' );
set_transient( 'vkt_xai_owner_' . hash( 'sha256', 'req-owned-by-a' ), $a, HOUR_IN_SECONDS );
$stolen = $act( $b, 'ai_video_status', array( 'request_id' => 'req-owned-by-a' ) );
$ok( is_wp_error( $stolen ) && 404 === $stolen->get_error_data()['status'], 'Чужое видео xAI не забрать по ID задачи' );

// ——— Вход через VK ID ———
$user_for = new ReflectionMethod( VKT_Login::class, 'user_for' );
$user_for->setAccessible( true );
$first = $user_for->invoke( null, 38975563, array( 'first_name' => 'Иван', 'last_name' => 'Петров', 'avatar' => 'https://sun.userapi.com/a.jpg' ) );
$ok( is_int( $first ) && 'pending' === VKT_Account::status( $first ) && 'Иван Петров' === get_userdata( $first )->display_name, 'Первый вход создаёт заявку с именем из VK' );
$ok( 38975563 === (int) get_user_meta( $first, 'vkt_vk_id', true ) && str_starts_with( (string) get_user_meta( $first, 'vkt_avatar', true ), 'https://' ), 'К заявке привязан ID и аватар VK' );
$second = $user_for->invoke( null, 38975563, array( 'first_name' => 'Иван', 'last_name' => 'Сидоров' ) );
$ok( $second === $first && 'Иван Сидоров' === get_userdata( $first )->display_name, 'Повторный вход находит ту же учётную запись и обновляет имя' );
$act( $admin, 'user_status', array( 'id' => $first, 'status' => 'blocked' ) );
$ok( is_wp_error( $user_for->invoke( null, 38975563, array() ) ), 'Заблокированный по VK не входит' );
$sign = new ReflectionMethod( VKT_Login::class, 'sign' );
$sign->setAccessible( true );
$read = new ReflectionMethod( VKT_Login::class, 'read_cookie' );
$read->setAccessible( true );
$payload = rtrim( strtr( base64_encode( wp_json_encode( array( 's' => 'state1', 'v' => 'verifier1', 'e' => time() + 60 ) ) ), '+/', '-_' ), '=' );
$_COOKIE[ VKT_Login::COOKIE ] = $payload . '.' . $sign->invoke( null, $payload );
$ok( 'verifier1' === ( $read->invoke( null )['v'] ?? '' ), 'Подписанная cookie входа читается' );
$_COOKIE[ VKT_Login::COOKIE ] = $payload . '.' . str_repeat( '0', 64 );
$ok( null === $read->invoke( null ), 'Подделанная cookie входа отвергается' );
$expired = rtrim( strtr( base64_encode( wp_json_encode( array( 's' => 'state1', 'v' => 'verifier1', 'e' => time() - 1 ) ) ), '+/', '-_' ), '=' );
$_COOKIE[ VKT_Login::COOKIE ] = $expired . '.' . $sign->invoke( null, $expired );
$ok( null === $read->invoke( null ), 'Просроченная cookie входа отвергается' );
unset( $_COOKIE[ VKT_Login::COOKIE ] );

// ——— Блокировка и удаление ———
wp_set_current_user( $a );
$again_later = $act( $a, 'publishing_create', array( 'message' => 'После блокировки', 'groups' => array( $a_group ), 'scheduled_at' => $later ) );
$act( $admin, 'user_status', array( 'id' => $a, 'status' => 'blocked' ) );
$ok( ! VKT_Account::can_use( $a ) && 403 === $rest( $a, 'state' )->get_status(), 'Заблокированный теряет доступ к REST' );
$wpdb->query( 'UPDATE ' . VKT_Store::table( 'outbound_deliveries' ) . " SET available_at='" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE outbound_post_id=" . (int) $again_later['id'] );
wp_set_current_user( 0 );
VKT_Publisher::run_due( 5 );
$error = (string) $wpdb->get_var( 'SELECT error FROM ' . VKT_Store::table( 'outbound_deliveries' ) . ' WHERE outbound_post_id=' . (int) $again_later['id'] );
$ok( str_contains( $error, 'закрыт администратором' ), 'Запись заблокированного кабинета не уходит в VK' );
$act( $admin, 'user_status', array( 'id' => $a, 'status' => 'active' ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
$b_solo = (int) $wpdb->get_var( 'SELECT source_id FROM ' . VKT_Store::table( 'subscriptions' ) . " WHERE user_id=$b AND source_id<>$shared" );
wp_delete_user( $b );
$ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'subscriptions' ) . " WHERE user_id=$b" ), 'Удаление пользователя снимает его подписки' );
$ok( null === $wpdb->get_var( 'SELECT id FROM ' . VKT_Store::table( 'sources' ) . " WHERE id=$b_solo" ) && null !== $wpdb->get_var( 'SELECT id FROM ' . VKT_Store::table( 'sources' ) . " WHERE id=$shared" ), 'Источник только B удалён, общий остался у A' );
$ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'publishing_groups' ) . " WHERE user_id=$b" ) + (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'outbound_posts' ) . " WHERE user_id=$b" ), 'Группы и очередь B удалены вместе с ним' );
$unsubscribe = $act( $a, 'delete', array( 'entity' => 'sources', 'id' => $shared ) );
$ok( ! is_wp_error( $unsubscribe ) && null === $wpdb->get_var( 'SELECT id FROM ' . VKT_Store::table( 'posts' ) . " WHERE id=$shared_post" ), 'Последний подписчик уносит источник вместе с постами' );
$ok( VKT_Subscriptions::sees_video( $saved['id'], $a ), 'Ролики остаются в подборке после отписки' );

wp_set_current_user( 0 );
echo "All $checks accounts integration checks passed.\n";

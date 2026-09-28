<?php
/**
 * Неполадки и живучесть очередей на настоящем WordPress и MySQL: истёкший
 * токен, отказ ключа, упавшие задания сбора, простой планировщика.
 * Запуск только в изолированной установке: wp eval-file tests/health-integration.php
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
foreach ( array( 'videos', 'snapshots', 'posts', 'post_snapshots', 'sources', 'jobs', 'publishing_groups', 'outbound_posts', 'outbound_deliveries', 'subscriptions', 'user_videos', 'comment_replies' ) as $name ) {
    $wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( $name ) );
}
foreach ( array( 'api', 'publisher', 'collector', 'replies' ) as $lock ) { VKT_Store::unlock( $lock ); }
delete_option( 'vkt_cron_gap' );
delete_option( 'vkt_cron_tick' );
$settings = VKT_Plugin::settings();
$settings['paused'] = false;
update_option( 'vkt_settings', $settings, false );

$admin = VKT_Account::owner();
wp_set_current_user( $admin );
$issues = static fn() => VKT_Health::issues();
$find = static function ( $needle ) use ( $issues ) {
    foreach ( $issues() as $issue ) {
        if ( str_contains( $issue['title'], $needle ) ) { return $issue; }
    }
    return null;
};

// Сеть: токен со словом DEAD VK отвергает, остальное отвечает штатно.
$seen = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$seen ) {
    $method = str_starts_with( $url, 'https://api.vk.com/method/' ) ? basename( $url ) : '';
    $body = (array) ( $args['body'] ?? array() );
    $seen[] = $method;
    $reply = static fn( $data ) => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
    if ( str_contains( (string) ( $body['access_token'] ?? '' ), 'DEAD' ) ) {
        return $reply( array( 'error' => array( 'error_code' => 5, 'error_msg' => 'User authorization failed: access_token has expired.' ) ) );
    }
    if ( 'wall.get' === $method ) {
        $video = array( 'id' => 456, 'owner_id' => -123, 'title' => 'Ролик', 'duration' => 30, 'views' => 150, 'type' => 'short_video' );
        return $reply( array( 'response' => array( 'count' => 1, 'items' => array( array( 'id' => 1, 'owner_id' => -123, 'date' => time(), 'text' => 'Пост', 'views' => array( 'count' => 10 ), 'attachments' => array( array( 'type' => 'video', 'video' => $video ) ) ) ), 'groups' => array( array( 'id' => 123, 'name' => 'Источник', 'screen_name' => 'src', 'members_count' => 100 ) ) ) ) );
    }
    if ( 'groups.getTokenPermissions' === $method ) {
        return $reply( array( 'response' => array( 'permissions' => array( array( 'name' => 'wall' ) ) ) ) );
    }
    if ( 'groups.getById' === $method ) {
        return $reply( array( 'response' => array( 'groups' => array( array( 'id' => 222, 'name' => 'Группа 222', 'screen_name' => 'g222' ) ) ) ) );
    }
    if ( str_starts_with( $url, 'https://api.vk.com/' ) || str_starts_with( $url, 'https://id.vk.ru/' ) ) {
        return $reply( array( 'error' => array( 'error_code' => 100, 'error_msg' => 'unexpected ' . $method ) ) );
    }
    return $pre;
}, 10, 3 );

VKT_Tokens::save( 'service', 'SERVICE_' . str_repeat( 's', 30 ) );
VKT_Tokens::forget( 'community' );
update_user_meta( $admin, 'vkt_community_id', 0 );

// 1. Классический токен истёк: он сохранён, но не живой, и кабинет об этом знает.
$write = new ReflectionMethod( VKT_Tokens::class, 'write' );
$write->setAccessible( true );
$expired = array( 'access_token' => 'vk1.a.EXPIRED' . str_repeat( 'e', 40 ), 'refresh_token' => '', 'device_id' => '', 'client_id' => '', 'expires_at' => time() - 60, 'scope' => 'wall,photos,groups,video', 'saved_at' => time() - 86460 );
$write->invoke( null, 'user', $expired );
$ok( VKT_Tokens::has( 'user' ) && ! VKT_Tokens::alive( 'user' ), 'Истёкший токен без пары обновления не считается рабочим' );
$issue = $find( 'Пользовательский токен VK не действует' );
$ok( $issue && 'error' === $issue['level'] && 'posting' === $issue['view'], 'Кабинет показывает ошибку токена со ссылкой на «Публикацию»' );

// 2. Сбор не стоит: ролик перемеряется обходом стены вместо video.get истёкшим токеном.
$wpdb->insert( VKT_Store::table( 'sources' ), array( 'kind' => 'owner', 'value' => '-123', 'title' => 'Источник', 'photo' => '', 'enabled' => 1, 'next_run' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'synced_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$source_id = (int) $wpdb->insert_id;
$wpdb->insert( VKT_Store::table( 'videos' ), array( 'owner_id' => -123, 'video_id' => 456, 'title' => 'Ролик', 'thumbnail' => '', 'source_id' => $source_id, 'views' => 100, 'measured_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ), 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
$seen = array();
$result = VKT_Collector::run( true );
$ok( ! is_wp_error( $result ) && in_array( 'wall.get', $seen, true ) && ! in_array( 'video.get', $seen, true ), 'С истёкшим токеном ролик мерится сервисным ключом' );
$ok( 150 === (int) $wpdb->get_var( 'SELECT views FROM ' . VKT_Store::table( 'videos' ) . ' WHERE video_id=456' ), 'Замер ролика записан' );

// 3. Упавшее задание не висит вечно: через FAILED_COOLDOWN_HOURS сборщик берёт его снова.
$jobs = VKT_Store::table( 'jobs' );
$wpdb->query( "DELETE FROM $jobs" );
$wpdb->insert( $jobs, array( 'job_key' => 'source:' . $source_id, 'kind' => 'source', 'entity_id' => $source_id, 'status' => 'failed', 'attempts' => 1, 'available_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'message' => 'VK: доступ запрещён' ) );
$wpdb->update( VKT_Store::table( 'sources' ), array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $source_id ) );
$failed_issue = $find( 'Заданий сбора с ошибкой' );
$ok( $failed_issue && str_contains( $failed_issue['text'], 'VK: доступ запрещён' ) && 'collector' === $failed_issue['view'], 'Упавшее задание сбора видно с причиной' );
VKT_Tokens::save( 'service', 'SERVICE_' . str_repeat( 's', 30 ) ); // ключ сохранён уже после падения
$wpdb->update( $jobs, array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ), array( 'kind' => 'source' ) );
$seen = array();
VKT_Collector::run( true );
$ok( 'done' === $wpdb->get_var( "SELECT status FROM $jobs WHERE kind='source'" ), 'После замены ключа упавшее задание выполнено без ручного повтора' );
$wpdb->update( $jobs, array( 'status' => 'failed', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - ( VKT_Collector::FAILED_COOLDOWN_HOURS + 1 ) * HOUR_IN_SECONDS ) ), array( 'kind' => 'source' ) );
$wpdb->update( VKT_Store::table( 'sources' ), array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $source_id ) );
VKT_Collector::run( true );
$ok( 'done' === $wpdb->get_var( "SELECT status FROM $jobs WHERE kind='source'" ), 'Спустя паузу упавшее задание повторяется само' );

// 4. Простой планировщика запоминается, даже когда он уже ожил.
update_option( 'vkt_cron_tick', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), false );
VKT_Store::unlock( 'collector' );
VKT_Collector::run();
$gap = get_option( 'vkt_cron_gap' );
$ok( is_array( $gap ) && strtotime( $gap['from'] . ' UTC' ) < time() - DAY_IN_SECONDS, 'Провал в работе cron записан' );
$gap_issue = $find( 'Сбор простаивал' );
$ok( $gap_issue && str_contains( $gap_issue['text'], 'wp-cron.php' ), 'Кабинет объясняет простой и как настроить планировщик хостинга' );
update_option( 'vkt_cron_tick', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), false );
$ok( null !== $find( 'Планировщик не запускался' ), 'Идущий простой — ошибка в кабинете' );
update_option( 'vkt_cron_tick', gmdate( 'Y-m-d H:i:s' ), false );

// 5. Давно не обходившийся источник — признак простоя, какой бы ни была причина.
$wpdb->update( VKT_Store::table( 'sources' ), array( 'synced_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ), array( 'id' => $source_id ) );
$ok( null !== $find( 'Источники не обновлялись' ), 'Застывший источник заметен' );
$wpdb->update( VKT_Store::table( 'sources' ), array( 'synced_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $source_id ) );

// 6. Отказ токена кодом 5 выключает его, а запись с ним ждёт переподключения, а не падает.
VKT_Tokens::save( 'user', 'vk1.a.DEAD' . str_repeat( 'd', 40 ), array( 'expires_in' => 86400 ) );
VKT_Tokens::save( 'community', 'COMMUNITY_' . str_repeat( 'k', 40 ) );
update_user_meta( $admin, 'vkt_community_id', 222 );
$wpdb->insert( VKT_Store::table( 'publishing_groups' ), array( 'user_id' => $admin, 'group_id' => 333, 'name' => 'Группа токена', 'screen_name' => 'g333', 'photo' => '', 'admin_level' => 3, 'can_post' => 1, 'enabled' => 1, 'synced_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$seen = array();
$sync = VKT_Publisher::sync_groups();
$ok( ! is_wp_error( $sync ) && ! empty( $sync['warning'] ) && 'posting' === $sync['fix']['view'], 'Обновление групп с отвергнутым токеном доходит до конца и подсказывает, где починить' );
$ok( ! VKT_Tokens::alive( 'user' ) && true === VKT_Tokens::error( 'user' )['dead'], 'Код 5 выключил токен' );
$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT can_post FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND group_id=333', $admin ) ), 'Группы токена не потеряли право записи из-за его отказа' );
$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT can_post FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND group_id=222', $admin ) ), 'Группа ключа сообщества обновлена' );

$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( VKT_Store::table( 'outbound_posts' ), array( 'user_id' => $admin, 'message' => 'Запись в группу токена', 'attachments' => '', 'media' => '', 'status' => 'queued', 'editor_status' => 'approved', 'scheduled_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
$post_id = (int) $wpdb->insert_id;
$wpdb->insert( VKT_Store::table( 'outbound_deliveries' ), array( 'outbound_post_id' => $post_id, 'group_id' => 333, 'status' => 'pending', 'attempts' => 0, 'available_at' => $now, 'media_attachments' => '', 'guid' => 'g-' . $post_id, 'updated_at' => $now ) );
// Токен оживает на время отправки, чтобы запрос дошёл до VK и получил отказ.
VKT_Tokens::save( 'user', 'vk1.a.DEAD' . str_repeat( 'd', 40 ), array( 'expires_in' => 86400 ) );
VKT_Publisher::run_due( 3 );
$delivery = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'outbound_deliveries' ) . ' WHERE outbound_post_id=%d', $post_id ), ARRAY_A );
$ok( 'pending' === $delivery['status'] && 0 === (int) $delivery['attempts'] && str_contains( $delivery['error'], 'Ждём переподключения' ), 'Запись с отвергнутым ключом ждёт переподключения, попытка не списана' );
$ok( strtotime( $delivery['available_at'] . ' UTC' ) > time() + 600, 'Повтор не раньше чем через 15 минут' );
$ok( null !== $find( 'Публикации ждут повтора' ), 'Ожидающая запись видна в кабинете' );

// Ошибка из REST несёт подсказку, где чинить.
VKT_Tokens::save( 'user', 'vk1.a.DEAD' . str_repeat( 'd', 40 ), array( 'expires_in' => 86400 ) );
VKT_Store::unlock( 'api' );
$error = VKT_API::publishing_request( 'groups.get', array( 'filter' => 'editor' ) );
$ok( is_wp_error( $error ) && 'posting' === $error->get_error_data()['fix']['view'], 'Ошибка ключа в ответе REST ведёт на «Публикацию»' );

$state = VKT_Store::state();
$ok( isset( $state['health'] ) && count( $state['health'] ) > 0 && 'error' === $state['health'][0]['level'], 'Неполадки приходят в дашборд, ошибки первыми' );

VKT_Tokens::forget( 'user' );
VKT_Tokens::forget( 'community' );
echo "All $checks health integration checks passed.\n";

<?php
/**
 * Ответы на комментарии на настоящем WordPress и MySQL.
 * Запуск только в изолированной установке: wp eval-file tests/replies-integration.php
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
$table = VKT_Store::table( 'comment_replies' );
$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
$ok( in_array( 'guid', $columns, true ) && in_array( 'comment_id', $columns, true ), 'Обновление создало таблицу очереди ответов' );
$ok( (bool) $wpdb->get_var( "SHOW INDEX FROM $table WHERE Key_name='ready'" ), 'У очереди есть индекс готовности' );
foreach ( array( 'publishing_groups', 'comment_replies' ) as $name ) {
    $wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( $name ) );
}
foreach ( get_users( array( 'role__in' => array( 'vkt_member', 'subscriber' ) ) ) as $old ) { wp_delete_user( $old->ID ); }
VKT_Store::unlock( 'api' );
VKT_Store::unlock( 'replies' );

$admin = get_user_by( 'login', 'admin' )->ID;
$member = static function ( $login ) {
    $id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => VKT_Account::ROLE, 'display_name' => $login ) );
    update_user_meta( $id, 'vkt_status', 'active' );
    return (int) $id;
};
$a = $member( 'reply_a' );
$b = $member( 'reply_b' );
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

// Сеть: у A группы 111 и 222, у B — 222 и 333; B держит ключ сообщества 222.
$seen = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$seen ) {
    $method = str_starts_with( $url, 'https://api.vk.com/method/' ) ? basename( $url ) : '';
    $body = (array) ( $args['body'] ?? array() );
    $seen[] = array( 'method' => $method, 'body' => $body );
    $token = (string) ( $body['access_token'] ?? '' );
    $reply = static fn( $data ) => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
    if ( 'groups.get' === $method ) {
        $ids = str_contains( $token, 'USERA' ) ? array( 111, 222 ) : array( 222, 333 );
        return $reply( array( 'response' => array( 'count' => 2, 'items' => array_map( static fn( $id ) => array( 'id' => $id, 'name' => 'Группа ' . $id, 'screen_name' => 'g' . $id, 'admin_level' => 3, 'can_post' => 1 ), $ids ) ) ) );
    }
    if ( 'groups.getTokenPermissions' === $method ) {
        return $reply( array( 'response' => array( 'permissions' => array( array( 'name' => 'wall' ) ) ) ) );
    }
    if ( 'groups.getById' === $method ) {
        return $reply( array( 'response' => array( 'groups' => array( array( 'id' => 222, 'name' => 'Группа 222', 'screen_name' => 'g222' ) ) ) ) );
    }
    if ( 'wall.get' === $method ) {
        return $reply( array( 'response' => array( 'count' => 1, 'items' => array( array( 'id' => 5, 'date' => time(), 'text' => 'Пост про термосы', 'comments' => array( 'count' => 2, 'can_post' => 1 ) ) ) ) ) );
    }
    if ( 'wall.getComments' === $method ) {
        return $reply( array( 'response' => array( 'count' => 2, 'current_level_count' => 2, 'items' => array(
            array( 'id' => 50, 'from_id' => 42, 'date' => time(), 'text' => 'Сколько стоит?', 'thread' => array( 'count' => 0, 'items' => array() ) ),
            array( 'id' => 51, 'from_id' => 43, 'date' => time(), 'text' => 'Есть доставка?', 'thread' => array( 'count' => 1, 'items' => array( array( 'id' => 52, 'from_id' => -111, 'date' => time(), 'text' => 'Есть' ) ) ) ),
        ), 'profiles' => array( array( 'id' => 42, 'first_name' => 'Анна', 'last_name' => 'К', 'photo_50' => 'https://img.test/a.jpg' ) ), 'groups' => array() ) ) );
    }
    if ( 'wall.createComment' === $method ) {
        return $reply( array( 'response' => array( 'comment_id' => 800 + count( $seen ) ) ) );
    }
    if ( str_starts_with( $url, 'https://api.vk.com/' ) ) {
        return $reply( array( 'error' => array( 'error_code' => 100, 'error_msg' => 'unexpected ' . $method ) ) );
    }
    return $pre;
}, 10, 3 );
$last = static function ( $method ) use ( &$seen ) {
    foreach ( array_reverse( $seen ) as $call ) {
        if ( $call['method'] === $method ) { return $call['body']; }
    }
    return null;
};

wp_set_current_user( $admin );
VKT_Tokens::save( 'service', 'SERVICE_' . str_repeat( 's', 30 ) );
wp_set_current_user( $a );
VKT_Tokens::save( 'user', 'vk1.a.USERA' . str_repeat( 'a', 50 ) );
wp_set_current_user( $b );
VKT_Tokens::save( 'user', 'vk1.a.USERB' . str_repeat( 'b', 50 ) );
VKT_Tokens::save( 'community', 'COMMUNITY_B' . str_repeat( 'k', 40 ) );
VKT_Account::set( 'community_id', 222 );
$ok( ! is_wp_error( $act( $a, 'publishing_sync' ) ) && ! is_wp_error( $act( $b, 'publishing_sync' ) ), 'Оба кабинета загрузили свои группы' );

// Чтение: только свои группы, общим ключом сбора.
$posts = $act( $a, 'comments_posts', array( 'group_id' => 111 ) );
$ok( ! is_wp_error( $posts ) && 5 === $posts['posts'][0]['id'] && 2 === $posts['posts'][0]['comments'], 'Записи своей группы прочитаны' );
$ok( str_starts_with( (string) $last( 'wall.get' )['access_token'], 'SERVICE_' ), 'Стену читает сервисный ключ сбора' );
$ok( is_wp_error( $act( $a, 'comments_posts', array( 'group_id' => 333 ) ) ), 'Стена группы другого кабинета закрыта' );
$thread = $act( $a, 'comments_thread', array( 'group_id' => 111, 'post_id' => 5 ) );
$ok( ! is_wp_error( $thread ) && 'Анна К' === $thread['comments'][0]['author'] && true === $thread['comments'][1]['answered'], 'Комментарии и ответ группы в ветке видны' );
$ok( '0' === (string) $last( 'wall.getComments' )['preview_length'], 'Текст комментариев не обрезается' );

// Пачка в очередь.
$item = static fn( $comment ) => array( 'post_id' => 5, 'comment_id' => $comment, 'author_id' => 42, 'author' => 'Анна К', 'comment_text' => 'Вопрос', 'message' => 'Спасибо, ответили в сообщениях!' );
$queued = $act( $a, 'comments_queue', array( 'group_id' => 111, 'interval' => 2, 'jitter' => false, 'items' => array( $item( 50 ), $item( 53 ), $item( 54 ) ) ) );
$ok( ! is_wp_error( $queued ) && 3 === $queued['created'], 'Пачка ответов встала в очередь' );
$ok( 240 === strtotime( $queued['last_at'] . ' UTC' ) - strtotime( $queued['first_at'] . ' UTC' ), 'Ответы разнесены по интервалу' );
$state_b = $rest( $b, 'comments' )->get_data();
$ok( 0 === count( $state_b['queue'] ), 'Чужая очередь ответов не видна' );
$state_a = $rest( $a, 'comments' )->get_data();
$ok( 3 === count( $state_a['queue'] ) && 'user' === array_column( $state_a['groups'], 'sender', 'group_id' )[111], 'Своя очередь видна, отправка — токеном' );

// Одиночный ответ ключом сообщества.
$sent = $act( $b, 'comments_reply', array( 'group_id' => 222, 'items' => array( $item( 60 ) ) ) );
$body = $last( 'wall.createComment' );
$ok( 'sent' === $sent['status'] && str_starts_with( (string) $body['access_token'], 'COMMUNITY_B' ) && '222' === (string) $body['from_group'] && '60' === (string) $body['reply_to_comment'], 'Ответ ушёл ключом сообщества в ветку комментария' );
// Та же группа VK из другого кабинета ждёт паузу — она общая для группы.
$waiting = $act( $a, 'comments_reply', array( 'group_id' => 222, 'items' => array( $item( 61 ) ) ) );
$ok( 'pending' === $waiting['status'], 'Пауза считается по группе VK, а не по кабинету' );

// Cron: созревшие ответы уходят, в каждую группу по одному.
$wpdb->query( "UPDATE $table SET available_at='2000-01-01 00:00:00' WHERE status='pending'" );
$wpdb->query( "UPDATE $table SET sent_at='2000-01-01 00:00:00' WHERE status='sent'" );
$before = count( array_filter( $seen, static fn( $call ) => 'wall.createComment' === $call['method'] ) );
wp_set_current_user( 0 );
VKT_Store::unlock( 'publisher' );
do_action( 'vkt_publish' );
$after = count( array_filter( $seen, static fn( $call ) => 'wall.createComment' === $call['method'] ) );
$ok( 2 === $after - $before, 'Cron отправил по одному ответу в группы 111 и 222' );
$body = $last( 'wall.createComment' );
$ok( in_array( (int) $body['owner_id'], array( -111, -222 ), true ) && str_starts_with( (string) $body['access_token'], 'vk1.a.USERA' ), 'Cron отвечает от имени автора — его токеном' );
$ok( 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE user_id=%d AND status='pending'", $a ) ), 'Остальные ответы ждут следующего прохода' );

// Удаление кабинета уносит очередь.
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $a );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE user_id=%d", $a ) ), 'Удалённый кабинет не оставляет ответов в очереди' );
wp_delete_user( $b );
echo "All $checks reply integration checks passed.\n";

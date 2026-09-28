<?php
/**
 * Запущенные серии, лента комментариев и Callback по группам на настоящем
 * WordPress и MySQL. Запуск только в изолированной установке:
 * wp eval-file tests/series-feed-integration.php
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
foreach ( array( 'publishing_groups', 'outbound_posts', 'outbound_deliveries', 'comment_replies', 'comment_inbox' ) as $name ) {
    $wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( $name ) );
}
foreach ( array( 'api', 'publisher', 'replies' ) as $lock ) { VKT_Store::unlock( $lock ); }
foreach ( get_users( array( 'role__in' => array( 'vkt_member' ) ) ) as $old ) { wp_delete_user( $old->ID ); }
$a = (int) wp_insert_user( array( 'user_login' => 'feed_a', 'user_pass' => wp_generate_password(), 'role' => VKT_Account::ROLE, 'display_name' => 'feed_a' ) );
update_user_meta( $a, 'vkt_status', 'active' );
$act = static function ( $user, $action, $data = array() ) {
    wp_set_current_user( $user );
    VKT_Store::unlock( 'api' );
    $request = new WP_REST_Request( 'POST', '/vk-trends/v1/action' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( array_merge( array( 'action' => $action ), $data ) ) );
    return VKT_Plugin::action( $request );
};

// Сеть: ключ KEY_111_ — группа 111 с правом управления.
$seen = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$seen ) {
    $method = str_starts_with( $url, 'https://api.vk.com/method/' ) ? basename( $url ) : '';
    $body = (array) ( $args['body'] ?? array() );
    $seen[] = array( 'method' => $method, 'body' => $body );
    $reply = static fn( $data ) => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
    switch ( $method ) {
        case 'groups.getTokenPermissions': return $reply( array( 'response' => array( 'permissions' => array( array( 'name' => 'wall' ), array( 'name' => 'manage' ) ) ) ) );
        case 'groups.getById': return $reply( array( 'response' => array( 'groups' => array( array( 'id' => 111, 'name' => 'Группа 111', 'screen_name' => 'g111' ) ) ) ) );
        case 'groups.getCallbackConfirmationCode': return $reply( array( 'response' => array( 'code' => 'a1b2c3d4' ) ) );
        case 'groups.getCallbackServers':
            return $reply( array( 'response' => array( 'count' => 1, 'items' => ! empty( $body['server_ids'] ) ? array( array( 'id' => 7, 'url' => admin_url( 'admin-post.php?action=vkt_callback' ), 'status' => 'ok' ) ) : array() ) ) );
        case 'groups.addCallbackServer': $GLOBALS['vkt_added_secret'] = $body['secret_key'] ?? ''; return $reply( array( 'response' => array( 'server_id' => 7 ) ) );
        case 'groups.setCallbackSettings': $GLOBALS['vkt_events'] = $body; return $reply( array( 'response' => 1 ) );
        case 'wall.get': return $reply( array( 'response' => array( 'count' => 1, 'items' => array( array( 'id' => 5, 'date' => time(), 'text' => 'Пост про осень', 'comments' => array( 'count' => 2 ) ) ) ) ) );
        case 'wall.getComments':
            return $reply( array( 'response' => array( 'count' => 2, 'current_level_count' => 2, 'items' => array(
                array( 'id' => 50, 'from_id' => 42, 'date' => time() - 60, 'text' => 'Сколько стоит?', 'thread' => array( 'count' => 1, 'items' => array( array( 'id' => 52, 'from_id' => -111, 'date' => time() - 30, 'text' => 'Написали в ЛС' ) ) ) ),
                array( 'id' => 51, 'from_id' => 43, 'date' => time() - 50, 'text' => 'Есть доставка?', 'thread' => array( 'count' => 0, 'items' => array() ) ),
            ), 'profiles' => array( array( 'id' => 42, 'first_name' => 'Анна', 'last_name' => 'К', 'photo_50' => 'https://img.test/a.jpg' ), array( 'id' => 43, 'first_name' => 'Олег', 'last_name' => 'П', 'photo_50' => '' ) ), 'groups' => array() ) ) );
        case 'users.get': return $reply( array( 'response' => array( array( 'id' => 44, 'first_name' => 'Ира', 'last_name' => 'С', 'photo_50' => 'https://img.test/i.jpg' ) ) ) );
        case 'wall.post': return $reply( array( 'response' => array( 'post_id' => 900 ) ) );
    }
    return str_starts_with( $url, 'https://api.vk.com/' ) ? $reply( array( 'error' => array( 'error_code' => 100, 'error_msg' => 'unexpected ' . $method ) ) ) : $pre;
}, 10, 3 );

wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
VKT_Tokens::save( 'service', 'SERVICE_' . str_repeat( 's', 30 ) );
$added = $act( $a, 'community_key_add', array( 'token' => 'KEY_111_' . str_repeat( 'a', 30 ) ) );
$ok( ! is_wp_error( $added ) && 111 === $added['group_id'], 'Группа добавлена ключом' );
$local = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND group_id=111', $a ) );

// 1. Серия: помечена, видна в сетке и отменяется целиком.
$slots = array();
foreach ( array( 1, 2, 3 ) as $day ) {
    $slots[] = array( 'scheduled_at' => gmdate( 'c', time() + $day * DAY_IN_SECONDS ), 'message' => 'Пост серии ' . $day );
}
$series = $act( $a, 'series_queue', array( 'title' => 'Неделя про осень', 'groups' => array( $local ), 'slots' => $slots ) );
$ok( ! is_wp_error( $series ) && 3 === $series['created'] && str_starts_with( $series['series_id'], 's' ), 'Серия встала в очередь со своей отметкой' );
wp_set_current_user( $a );
$overview = VKT_Publisher::state()['series'];
$ok( 1 === count( $overview['list'] ) && 'Неделя про осень' === $overview['list'][0]['title'] && 3 === (int) $overview['list'][0]['waiting'], 'Запущенная серия видна с названием и числом ждущих записей' );
$ok( 3 === count( array_filter( $overview['posts'], static fn( $post ) => $post['series_id'] === $series['series_id'] && str_contains( $post['groups_names'], 'Группа 111' ) ) ), 'Записи серии попадают в сетку с названием группы' );
$single = $act( $a, 'publishing_create', array( 'message' => 'Одиночная', 'groups' => array( $local ), 'scheduled_at' => gmdate( 'c', time() + 5 * DAY_IN_SECONDS ), 'series_id' => 'sforged123' ) );
wp_set_current_user( $a );
$overview = VKT_Publisher::state()['series'];
$ok( ! is_wp_error( $single ) && 1 === count( $overview['list'] ) && 4 === count( $overview['posts'] ), 'Запланированная одиночная запись тоже в сетке, но серию себе не подделывает' );
$cancelled = $act( $a, 'series_cancel', array( 'series_id' => $series['series_id'] ) );
wp_set_current_user( $a );
$overview = VKT_Publisher::state()['series'];
$ok( 3 === $cancelled['cancelled'] && 0 === (int) $overview['list'][0]['waiting'] && 3 === (int) $overview['list'][0]['cancelled'], 'Серия отменена целиком, одиночная запись осталась' );

// 2. Лента: обход последних записей складывает комментарии, ответ группы помечает ветку.
$scan = $act( $a, 'comments_scan', array( 'group_id' => 111 ) );
$ok( ! is_wp_error( $scan ) && 1 === $scan['posts'] && 3 === $scan['comments'], 'Обход записей собрал комментарии и ответ группы в ветке' );
$open = $act( $a, 'comments_inbox', array( 'group_id' => 111, 'filter' => 'open' ) );
$ok( 1 === count( $open['comments'] ) && 51 === $open['comments'][0]['id'] && 'Олег П' === $open['comments'][0]['author'], 'Без ответа — только комментарий, где группа ещё не отвечала' );
$all = $act( $a, 'comments_inbox', array( 'group_id' => 111, 'filter' => 'all' ) );
$ok( 2 === count( $all['comments'] ) && true === array_column( $all['comments'], 'answered', 'id' )[50] && 'Пост про осень' === $all['comments'][0]['post_text'], 'Во «Всех» видно, на что уже ответила группа, и текст записи' );

// 3. Callback: автоматическое подключение ключом группы.
$info = $act( $a, 'callback_setup', array( 'group_id' => 111 ) );
$ok( ! is_wp_error( $info ) && 'ok' === $info['status'] && 7 === $info['server_id'] && 'a1b2c3d4' === $info['code'], 'Callback подключён автоматически и подтверждён' );
$ok( '1' === (string) ( $GLOBALS['vkt_events']['wall_reply_new'] ?? '' ) && '1' === (string) ( $GLOBALS['vkt_events']['wall_reply_delete'] ?? '' ), 'Группе включены события комментариев' );
$stored = (string) $wpdb->get_var( 'SELECT callback_secret FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE group_id=111' );
$secret = $GLOBALS['vkt_added_secret'];
$ok( '' !== $secret && ! str_contains( $stored, $secret ), 'Секрет Callback хранится зашифрованным' );

// 4. Приём событий.
list( $body, $status ) = VKT_Community::process( wp_json_encode( array( 'type' => 'confirmation', 'group_id' => 111 ) ) );
$ok( 'a1b2c3d4' === $body && 200 === $status, 'На подтверждение отвечаем строкой группы' );
$event = static fn( $type, $object, $secret_key ) => VKT_Community::process( wp_json_encode( array( 'type' => $type, 'group_id' => 111, 'secret' => $secret_key, 'object' => $object ) ) );
list( $body, $status ) = $event( 'wall_reply_new', array( 'id' => 60, 'from_id' => 44, 'post_id' => 5, 'date' => time(), 'text' => 'Новый вопрос' ), 'wrong' );
$ok( 403 === $status, 'Событие с чужим секретом отклонено' );
list( $body, $status ) = $event( 'wall_reply_new', array( 'id' => 60, 'from_id' => 44, 'post_id' => 5, 'date' => time(), 'text' => 'Новый вопрос' ), $secret );
$open = $act( $a, 'comments_inbox', array( 'group_id' => 111, 'filter' => 'open' ) );
$ok( 'ok' === $body && 60 === $open['comments'][0]['id'] && 'Ира С' === $open['comments'][0]['author'], 'Новый комментарий пришёл в ленту, имя автора дотянуто' );
$event( 'wall_reply_new', array( 'id' => 61, 'from_id' => -111, 'post_id' => 5, 'date' => time() + 1, 'text' => 'Ответили', 'parents_stack' => array( 51 ) ), $secret );
$open = $act( $a, 'comments_inbox', array( 'group_id' => 111, 'filter' => 'open' ) );
$ok( ! in_array( 51, array_column( $open['comments'], 'id' ), true ), 'Ответ группы в ветке снимает комментарий из «Без ответа»' );
$event( 'wall_reply_delete', array( 'id' => 60, 'post_id' => 5 ), $secret );
$open = $act( $a, 'comments_inbox', array( 'group_id' => 111, 'filter' => 'open' ) );
$ok( ! in_array( 60, array_column( $open['comments'], 'id' ), true ), 'Удалённый в VK комментарий пропал из ленты' );
$group = $wpdb->get_row( 'SELECT callback_status,callback_at FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE group_id=111', ARRAY_A );
$ok( 'ok' === $group['callback_status'] && null !== $group['callback_at'], 'Время последнего события видно в настройках группы' );

// 5. Ручная настройка.
$manual = $act( $a, 'callback_save', array( 'group_id' => 111, 'code' => '12381946', 'secret' => 'MySecret42' ) );
list( $body ) = VKT_Community::process( wp_json_encode( array( 'type' => 'confirmation', 'group_id' => 111 ) ) );
$ok( ! is_wp_error( $manual ) && '12381946' === $body, 'Ручная строка подтверждения из VK отдаётся на подтверждение' );
list( $body, $status ) = $event( 'wall_reply_new', array( 'id' => 70, 'from_id' => 42, 'post_id' => 5, 'date' => time(), 'text' => 'Ещё' ), 'MySecret42' );
$ok( 200 === $status, 'События с ручным секретом принимаются' );
$ok( is_wp_error( $act( $a, 'callback_save', array( 'group_id' => 111, 'code' => '12381946', 'secret' => '' ) ) ), 'Без секретного ключа ручная настройка не сохраняется' );
$ok( is_wp_error( $act( $a, 'callback_setup', array( 'group_id' => 999 ) ) ), 'Callback чужой группы не подключить' );

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $a );
echo "All $checks series and feed integration checks passed.\n";

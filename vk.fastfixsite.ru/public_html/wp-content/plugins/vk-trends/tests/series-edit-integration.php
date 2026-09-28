<?php
/**
 * Правка и дополнение серии, привязка к одному сообществу и чтение
 * комментариев после отказа VK сервисному ключу (код 1051). Только в
 * изолированной установке: wp eval-file tests/series-edit-integration.php
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
$make = static function ( $login ) {
    $id = (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => VKT_Account::ROLE, 'display_name' => $login ) );
    update_user_meta( $id, 'vkt_status', 'active' );
    return $id;
};
$a = $make( 'edit_a' );
$b = $make( 'edit_b' );
$act = static function ( $user, $action, $data = array() ) {
    wp_set_current_user( $user );
    VKT_Store::unlock( 'api' );
    $request = new WP_REST_Request( 'POST', '/vk-trends/v1/action' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( array_merge( array( 'action' => $action ), $data ) ) );
    return VKT_Plugin::action( $request );
};

// Сеть: ключ KEY_<id>_ — группа <id>. Сервисному ключу wall.getComments отвечает 1051, как VK сейчас.
$seen = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$seen ) {
    $method = str_starts_with( $url, 'https://api.vk.com/method/' ) ? basename( $url ) : '';
    $body = (array) ( $args['body'] ?? array() );
    $token = (string) ( $body['access_token'] ?? '' );
    $seen[] = array( 'method' => $method, 'token' => $token );
    $reply = static fn( $data ) => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
    $group = preg_match( '/^KEY_(\d+)_/', $token, $m ) ? (int) $m[1] : 0;
    switch ( $method ) {
        case 'groups.getTokenPermissions': return $reply( array( 'response' => array( 'permissions' => array( array( 'name' => 'wall' ), array( 'name' => 'manage' ) ) ) ) );
        case 'groups.getById': return $reply( array( 'response' => array( 'groups' => array( array( 'id' => $group, 'name' => 'Группа ' . $group, 'screen_name' => 'g' . $group ) ) ) ) );
        case 'wall.getComments':
            if ( str_starts_with( $token, 'SERVICE_' ) ) {
                return $reply( array( 'error' => array( 'error_code' => 1051, 'error_msg' => 'wall.getComments: Method is not available for this profile type' ) ) );
            }
            if ( $group && ! empty( $GLOBALS['vkt_group_denies'] ) ) {
                return $reply( array( 'error' => array( 'error_code' => 27, 'error_msg' => 'Group authorization failed: method is unavailable with group auth.' ) ) );
            }
            return $reply( array( 'response' => array( 'count' => 1, 'current_level_count' => 1, 'items' => array(
                array( 'id' => 80, 'from_id' => 42, 'date' => time() - 60, 'text' => 'Когда следующий пост?', 'thread' => array( 'count' => 0, 'items' => array() ) ),
            ), 'profiles' => array( array( 'id' => 42, 'first_name' => 'Анна', 'last_name' => 'К', 'photo_50' => '' ) ), 'groups' => array() ) ) );
    }
    return str_starts_with( $url, 'https://api.vk.com/' ) ? $reply( array( 'error' => array( 'error_code' => 100, 'error_msg' => 'unexpected ' . $method ) ) ) : $pre;
}, 10, 3 );

wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
VKT_Tokens::save( 'service', 'SERVICE_' . str_repeat( 's', 30 ) );
foreach ( array( 111, 222 ) as $id ) {
    $added = $act( $a, 'community_key_add', array( 'token' => 'KEY_' . $id . '_' . str_repeat( 'a', 30 ) ) );
    $ok( ! is_wp_error( $added ) && $id === $added['group_id'], "Группа $id добавлена ключом" );
}
$local = static fn( $group ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND group_id=%d', $a, $group ) );
$g1 = $local( 111 );
$g2 = $local( 222 );
$slots = static fn( $days, $text ) => array_map( static fn( $day ) => array( 'scheduled_at' => gmdate( 'c', time() + $day * DAY_IN_SECONDS ), 'message' => $text . ' ' . $day ), $days );

// 1. Серия — одно сообщество.
$ok( is_wp_error( $act( $a, 'series_queue', array( 'groups' => array( $g1, $g2 ), 'slots' => $slots( array( 1 ), 'Две группы' ) ) ) ), 'Серию в две группы сразу не поставить' );
$series = $act( $a, 'series_queue', array( 'title' => 'Осень', 'groups' => array( $g1 ), 'slots' => $slots( array( 1, 2, 3 ), 'Пост' ) ) );
$ok( ! is_wp_error( $series ) && 3 === $series['created'], 'Серия в одно сообщество встала в очередь' );
wp_set_current_user( $a );
$posts = VKT_Publisher::series_overview()['posts'];
$ok( 3 === count( $posts ) && array( $g1 ) === $posts[0]['group_ids'] && true === $posts[0]['editable'] && 0 === $posts[0]['media_count'] && ! isset( $posts[0]['media'] ), 'В сетке у записи её сообщество, признак правки и число файлов' );

// 2. Дополнение: та же серия и название, только её сообщество.
$more = $act( $a, 'series_queue', array( 'title' => 'Другое имя', 'series_id' => $series['series_id'], 'groups' => array( $g1 ), 'slots' => $slots( array( 4 ), 'Добавка' ) ) );
$ok( ! is_wp_error( $more ) && $series['series_id'] === $more['series_id'] && 'Осень' === $more['title'], 'Новые слоты дописываются в ту же серию под её названием' );
$ok( is_wp_error( $act( $a, 'series_queue', array( 'series_id' => $series['series_id'], 'groups' => array( $g2 ), 'slots' => $slots( array( 5 ), 'Не туда' ) ) ) ), 'Дописать серию в чужое для неё сообщество нельзя' );
$ok( is_wp_error( $act( $b, 'series_queue', array( 'series_id' => $series['series_id'], 'groups' => array( $g1 ), 'slots' => $slots( array( 5 ), 'Чужая' ) ) ) ), 'Чужую серию не дополнить' );
wp_set_current_user( $a );
$list = VKT_Publisher::series_overview()['list'];
$ok( 1 === count( $list ) && 4 === (int) $list[0]['total'], 'Серия одна, в ней четыре записи' );

// 3. Правка записи: текст и время.
$first = (int) $series['posts'][0]['id'];
$full = $act( $a, 'publishing_get', array( 'id' => $first ) );
$ok( 'Пост 1' === $full['message'] && true === $full['editable'] && array() === $full['media_items'], 'Запись отдаётся целиком для окна правки' );
$ok( is_wp_error( $act( $b, 'publishing_get', array( 'id' => $first ) ) ) && is_wp_error( $act( $b, 'publishing_update', array( 'id' => $first, 'post' => array( 'message' => 'взлом' ) ) ) ), 'Чужую запись не открыть и не поправить' );
$deliveries = VKT_Store::table( 'outbound_deliveries' );
$wpdb->update( $deliveries, array( 'media_attachments' => 'photo-111_1' ), array( 'outbound_post_id' => $first ) );
$when = time() + 6 * DAY_IN_SECONDS;
$updated = $act( $a, 'publishing_update', array( 'id' => $first, 'post' => array( 'message' => 'Новый текст', 'scheduled_at' => gmdate( 'c', $when ) ) ) );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT available_at,media_attachments FROM $deliveries WHERE outbound_post_id=%d", $first ), ARRAY_A );
$ok( ! is_wp_error( $updated ) && 'Новый текст' === $updated['message'] && gmdate( 'Y-m-d H:i:s', $when ) === $updated['scheduled_at'], 'Текст и время записи сохранены' );
$ok( gmdate( 'Y-m-d H:i:s', $when ) === $row['available_at'] && '' === $row['media_attachments'], 'Задание переехало на новое время, старые вложения VK сброшены' );
$kept = $act( $a, 'publishing_update', array( 'id' => $first, 'post' => array( 'attachments' => 'https://example.com/page' ) ) );
$ok( ! is_wp_error( $kept ) && 'Новый текст' === $kept['message'] && 'https://example.com/page' === $kept['attachments'], 'Поле без изменения в запросе остаётся прежним' );
$ok( is_wp_error( $act( $a, 'publishing_update', array( 'id' => $first, 'post' => array( 'scheduled_at' => gmdate( 'c', time() - 60 ) ) ) ) ), 'Перенос в прошлое отклоняется' );
$ok( is_wp_error( $act( $a, 'publishing_update', array( 'id' => $first, 'post' => array( 'message' => '', 'attachments' => '' ) ) ) ), 'Пустую запись правка не пропускает' );

// 4. Файл к записи: нужен пользовательский токен, как и при создании.
$upload = wp_upload_bits( 'series-edit.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'series-edit', 'post_status' => 'inherit', 'post_author' => $a ), $upload['file'] );
$ok( is_wp_error( $act( $a, 'publishing_update', array( 'id' => $first, 'post' => array( 'media' => array( $attachment ) ) ) ) ), 'Без пользовательского токена файл к записи не прикрепить' );

// 5. Комментарии: сервисному ключу VK отвечает 1051 — читаем ключом группы.
wp_set_current_user( $a );
$seen = array();
$thread = $act( $a, 'comments_thread', array( 'group_id' => 111, 'post_id' => 5 ) );
$tokens = array_column( array_filter( $seen, static fn( $row ) => 'wall.getComments' === $row['method'] ), 'token' );
$ok( ! is_wp_error( $thread ) && 80 === $thread['comments'][0]['id'], 'После 1051 комментарии прочитаны ключом сообщества' );
$ok( 2 === count( $tokens ) && str_starts_with( $tokens[1], 'KEY_111_' ), 'Сначала сервисный ключ, затем ключ этой группы' );
$GLOBALS['vkt_group_denies'] = true;
$denied = $act( $a, 'comments_thread', array( 'group_id' => 111, 'post_id' => 5 ) );
$ok( is_wp_error( $denied ) && 'posting' === ( $denied->get_error_data()['fix']['view'] ?? '' ) && str_contains( $denied->get_error_message(), '1051' ), 'Когда отказали оба ключа — понятная ошибка со ссылкой на токен' );
wp_set_current_user( $a );
$ok( '' === (string) VKT_Tokens::error( 'group:111' ) && '' === (string) VKT_Tokens::error( 'community' ) && VKT_Community::key_alive( 111 ), 'Отказ в чтении не записывает ключ группы в неполадки' );
unset( $GLOBALS['vkt_group_denies'] );
VKT_Tokens::save( 'user', 'USER_' . str_repeat( 'u', 40 ), array( 'expires_in' => 3600 ) );
$seen = array();
$thread = $act( $a, 'comments_thread', array( 'group_id' => 111, 'post_id' => 5 ) );
$tokens = array_column( array_filter( $seen, static fn( $row ) => 'wall.getComments' === $row['method'] ), 'token' );
$ok( ! is_wp_error( $thread ) && 1 === count( $tokens ) && str_starts_with( $tokens[0], 'USER_' ), 'С живым пользовательским токеном комментарии читает он, одним запросом' );

// 6. С токеном файл прикрепляется; запись, которая уже уходит, не правится.
$with = $act( $a, 'publishing_update', array( 'id' => $first, 'post' => array( 'media' => array( $attachment ) ) ) );
$ok( ! is_wp_error( $with ) && 1 === count( $with['media_items'] ) && 'Новый текст' === $with['message'], 'Картинка прикреплена к записи в очереди' );
wp_set_current_user( $a );
$ok( 1 === VKT_Publisher::series_overview()['posts'][ array_search( $first, array_map( 'intval', array_column( VKT_Publisher::series_overview()['posts'], 'id' ) ), true ) ]['media_count'], 'Сетка видит число файлов записи' );
VKT_Store::lock( 'publisher', 60 );
$busy = $act( $a, 'publishing_update', array( 'id' => $first, 'post' => array( 'message' => 'Во время отправки' ) ) );
VKT_Store::unlock( 'publisher' );
$ok( is_wp_error( $busy ) && 409 === $busy->get_error_data()['status'], 'Пока идёт отправка очереди, правка ждёт' );
$wpdb->update( $deliveries, array( 'status' => 'publishing' ), array( 'outbound_post_id' => $first ) );
$late = $act( $a, 'publishing_update', array( 'id' => $first, 'post' => array( 'message' => 'Поздно' ) ) );
$ok( is_wp_error( $late ) && false === $act( $a, 'publishing_get', array( 'id' => $first ) )['editable'], 'Запись, которая уже уходит в VK, не правится' );

// 7. Одну запись серии можно убрать, остальные остаются.
$second = (int) $series['posts'][1]['id'];
$act( $a, 'publishing_cancel', array( 'id' => $second ) );
wp_set_current_user( $a );
$list = VKT_Publisher::series_overview()['list'];
$ok( 1 === (int) $list[0]['cancelled'] && 3 === (int) $list[0]['waiting'], 'Убрана одна запись серии, остальные ждут' );

wp_delete_attachment( $attachment, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $a );
wp_delete_user( $b );
echo "All $checks series edit integration checks passed.\n";

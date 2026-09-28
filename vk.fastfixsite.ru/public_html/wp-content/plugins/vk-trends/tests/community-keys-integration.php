<?php
/**
 * Несколько групп по ключам сообществ на настоящем WordPress и MySQL.
 * Запуск только в изолированной установке: wp eval-file tests/community-keys-integration.php
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
foreach ( array( 'publishing_groups', 'outbound_posts', 'outbound_deliveries', 'comment_replies' ) as $name ) {
    $wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( $name ) );
}
foreach ( array( 'api', 'publisher', 'replies' ) as $lock ) { VKT_Store::unlock( $lock ); }
foreach ( get_users( array( 'role__in' => array( 'vkt_member' ) ) ) as $old ) { wp_delete_user( $old->ID ); }
$member = static function ( $login ) {
    $id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => VKT_Account::ROLE, 'display_name' => $login ) );
    update_user_meta( $id, 'vkt_status', 'active' );
    return (int) $id;
};
$a = $member( 'keys_a' );
$b = $member( 'keys_b' );
$act = static function ( $user, $action, $data = array() ) {
    wp_set_current_user( $user );
    VKT_Store::unlock( 'api' );
    $request = new WP_REST_Request( 'POST', '/vk-trends/v1/action' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( array_merge( array( 'action' => $action ), $data ) ) );
    return VKT_Plugin::action( $request );
};

// Сеть: ключ KEY_<ID> принадлежит группе <ID>, пользовательский токен A мёртв.
$seen = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$seen ) {
    $method = str_starts_with( $url, 'https://api.vk.com/method/' ) ? basename( $url ) : '';
    $body = (array) ( $args['body'] ?? array() );
    $token = (string) ( $body['access_token'] ?? '' );
    $seen[] = array( 'method' => $method, 'token' => $token, 'body' => $body );
    $reply = static fn( $data ) => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
    if ( str_contains( $token, 'DEAD' ) ) {
        return $reply( array( 'error' => array( 'error_code' => 5, 'error_msg' => 'User authorization failed: access_token has expired.' ) ) );
    }
    if ( preg_match( '/^KEY_(\d+)_/', $token, $m ) ) {
        $group = (int) $m[1];
        if ( 'groups.getTokenPermissions' === $method ) {
            return $reply( array( 'response' => array( 'permissions' => array( array( 'name' => 'wall' ), array( 'name' => 'manage' ) ) ) ) );
        }
        if ( 'groups.getById' === $method ) {
            return $reply( array( 'response' => array( 'groups' => array( array( 'id' => $group, 'name' => 'Группа ' . $group, 'screen_name' => 'g' . $group, 'photo_200' => 'https://img.test/' . $group . '.jpg' ) ) ) ) );
        }
        if ( in_array( $method, array( 'wall.post', 'wall.createComment' ), true ) ) {
            return $reply( array( 'response' => array( 'post_id' => 900 + $group % 100, 'comment_id' => 800 + $group % 100 ) ) );
        }
    }
    if ( str_starts_with( $url, 'https://api.vk.com/' ) ) {
        return $reply( array( 'error' => array( 'error_code' => 5, 'error_msg' => 'unexpected ' . $method ) ) );
    }
    return $pre;
}, 10, 3 );

wp_set_current_user( $a );
VKT_Tokens::save( 'user', 'vk1.a.DEAD' . str_repeat( 'd', 40 ), array( 'expires_in' => 86400 ) );

// Добавление групп ключами — без живого пользовательского токена.
$first = $act( $a, 'community_key_add', array( 'token' => 'KEY_111_' . str_repeat( 'a', 30 ) ) );
$second = $act( $a, 'community_key_add', array( 'token' => 'KEY_222_' . str_repeat( 'b', 30 ), 'group' => 'https://vk.com/club222' ) );
$ok( ! is_wp_error( $first ) && 111 === $first['group_id'] && ! is_wp_error( $second ) && 222 === $second['group_id'], 'Две группы добавлены по ключам' );
$groups = $wpdb->get_results( $wpdb->prepare( 'SELECT group_id,can_post,enabled FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d ORDER BY group_id', $a ), ARRAY_A );
$ok( 2 === count( $groups ) && '1' === (string) $groups[0]['can_post'] && '1' === (string) $groups[1]['enabled'], 'Обе группы сразу в «Моих сообществах» и доступны для записи' );
$raw = (string) get_user_meta( $a, VKT_Tokens::META_GROUP_KEYS, true );
$ok( '' !== $raw && ! str_contains( $raw, 'KEY_111' ), 'Ключи групп хранятся зашифрованными' );

// Обновление групп с мёртвым токеном: группы с ключами не теряются.
wp_set_current_user( $a );
$sync = VKT_Publisher::sync_groups();
$ok( ! is_wp_error( $sync ) && 2 === $sync['synced'] && ! empty( $sync['warning'] ), 'Обновление групп с мёртвым токеном прошло по ключам и предупредило' );
$ok( 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND can_post=1', $a ) ), 'Право записи у групп с ключами сохранилось' );

// Публикация в обе группы — каждая своим ключом.
$created = $act( $a, 'publishing_create', array( 'message' => 'В обе группы', 'groups' => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d', $a ) ) ) ) );
$ok( ! is_wp_error( $created ), 'Запись в две группы создана' );
$posts = array_values( array_filter( $seen, static fn( $call ) => 'wall.post' === $call['method'] ) );
$tokens_by_owner = array();
foreach ( $posts as $call ) { $tokens_by_owner[ (int) $call['body']['owner_id'] ] = substr( $call['token'], 0, 8 ); }
$ok( 'KEY_111_' === ( $tokens_by_owner[-111] ?? '' ) && 'KEY_222_' === ( $tokens_by_owner[-222] ?? '' ), 'Каждая группа получила запись своим ключом' );

// Ответ на комментарий во второй группе — её ключом.
$reply = $act( $a, 'comments_reply', array( 'group_id' => 222, 'items' => array( array( 'post_id' => 5, 'comment_id' => 50, 'author_id' => 1, 'author' => 'Анна', 'comment_text' => '?', 'message' => 'Спасибо!' ) ) ) );
$last = end( $seen );
$ok( 'sent' === $reply['status'] && str_starts_with( $last['token'], 'KEY_222_' ) && '222' === (string) $last['body']['from_group'], 'Ответ ушёл ключом своей группы' );

// Кабинеты изолированы: ключи A не видны B и не работают за него.
wp_set_current_user( $b );
$ok( ! VKT_Community::has_key( 111 ) && ! VKT_Community::key_list(), 'Ключи одного кабинета не видны другому' );
$ok( is_wp_error( VKT_Community::publish( array( 'owner_id' => -111, 'from_group' => 1, 'message' => 'Чужая' ) ) ), 'Чужим ключом не опубликовать' );

// Мёртвый ключ — уведомление именно про эту группу.
wp_set_current_user( $a );
$map = VKT_Tokens::group_keys();
VKT_Tokens::save_group_key( 111, 'KEY_DEAD_' . str_repeat( 'z', 30 ) );
$broken = VKT_Community::publish( array( 'owner_id' => -111, 'from_group' => 1, 'message' => 'x' ) );
$issue = null;
foreach ( VKT_Health::issues() as $item ) { if ( str_contains( $item['title'], 'Группа 111' ) ) { $issue = $item; } }
$ok( is_wp_error( $broken ) && ! VKT_Community::key_alive( 111 ) && VKT_Community::key_alive( 222 ), 'Отказ ключа выключает только его группу' );
$ok( $issue && 'error' === $issue['level'] && 'posting' === $issue['view'], 'Кабинет называет группу с мёртвым ключом и ведёт к замене' );
$replaced = $act( $a, 'community_key_add', array( 'token' => 'KEY_111_' . str_repeat( 'n', 30 ) ) );
$ok( ! is_wp_error( $replaced ) && VKT_Community::key_alive( 111 ), 'Новый ключ оживляет группу' );

// Убрать ключ: группа остаётся, но без права записи.
$forgot = $act( $a, 'community_key_forget', array( 'group_id' => 222 ) );
$ok( ! is_wp_error( $forgot ) && ! VKT_Community::has_key( 222 ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT can_post FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND group_id=222', $a ) ), 'Ключ убран, группа без права записи' );

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $a );
wp_delete_user( $b );
echo "All $checks community key integration checks passed.\n";

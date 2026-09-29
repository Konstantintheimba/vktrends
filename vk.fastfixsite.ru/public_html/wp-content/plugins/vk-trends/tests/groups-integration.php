<?php
/**
 * «Мои сообщества»: слежение за своими группами вне лимита, сводка, охваты,
 * паспорт и его учёт в генерации. Только в изолированной установке:
 * wp eval-file tests/groups-integration.php
 * Сеть подменяется: ни один запрос не уходит в VK или к модели.
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
foreach ( array( 'publishing_groups', 'outbound_posts', 'outbound_deliveries', 'subscriptions', 'sources', 'posts', 'post_snapshots', 'jobs' ) as $name ) {
    $wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( $name ) );
}
foreach ( array( 'api', 'publisher', 'replies', 'collector' ) as $lock ) { VKT_Store::unlock( $lock ); }
foreach ( get_users( array( 'role__in' => array( 'vkt_member' ) ) ) as $old ) { wp_delete_user( $old->ID ); }
delete_option( 'vkt_text_models' );
// Суточный лимит текстов участника: прошлые тесты могли оставить его крошечным.
$settings_before = get_option( 'vkt_settings' );
update_option( 'vkt_settings', array_merge( (array) $settings_before, array( 'ai_text_daily' => 100 ) ), false );
$make = static function ( $login ) {
    $id = (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => VKT_Account::ROLE, 'display_name' => $login ) );
    update_user_meta( $id, 'vkt_status', 'active' );
    return $id;
};
$a = $make( 'groups_a' );
$b = $make( 'groups_b' );
$act = static function ( $user, $action, $data = array() ) {
    wp_set_current_user( $user );
    VKT_Store::unlock( 'api' );
    $request = new WP_REST_Request( 'POST', '/vk-trends/v1/action' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( array_merge( array( 'action' => $action ), $data ) ) );
    return VKT_Plugin::action( $request );
};

// Сеть: ключ KEY_<id>_ — группа <id>; stats.get отдаёт ключ группы 111 и отклоняет 222; модель пишет JSON.
$GLOBALS['vkt_prompts'] = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    $reply = static fn( $data ) => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
    if ( str_ends_with( $url, '/chat/completions' ) ) {
        $body = json_decode( (string) $args['body'], true );
        $prompt = (string) end( $body['messages'] )['content'];
        $GLOBALS['vkt_prompts'][] = $prompt;
        // Паспорт узнаём первым: в его запросе тоже есть JSON с ключом posts — цифры сводки.
        $content = str_contains( $prompt, 'Составь паспорт' ) ? "```markdown\n# Паспорт\n## Тон общения\n- На «вы», тепло\n```" : ( str_contains( $prompt, '{"posts"' ) ? wp_json_encode( array( 'posts' => array( 'Первый', 'Второй' ) ) ) : 'готово' );
        return $reply( array( 'choices' => array( array( 'message' => array( 'content' => $content ) ) ) ) );
    }
    $method = str_starts_with( $url, 'https://api.vk.com/method/' ) ? basename( $url ) : '';
    $body = (array) ( $args['body'] ?? array() );
    $group = preg_match( '/^KEY_(\d+)_/', (string) ( $body['access_token'] ?? '' ), $m ) ? (int) $m[1] : 0;
    switch ( $method ) {
        case 'groups.getTokenPermissions': return $reply( array( 'response' => array( 'permissions' => array( array( 'name' => 'wall' ) ) ) ) );
        case 'groups.getById': return $reply( array( 'response' => array( 'groups' => array( array( 'id' => $group, 'name' => 'Группа ' . $group, 'screen_name' => 'g' . $group ) ) ) ) );
        case 'stats.get':
            if ( 111 !== $group ) {
                return $reply( array( 'error' => array( 'error_code' => 15, 'error_msg' => 'Access denied: no access to stats' ) ) );
            }
            $day = strtotime( 'today' );
            return $reply( array( 'response' => array(
                array( 'period_from' => $day - DAY_IN_SECONDS, 'period_to' => $day, 'reach' => array( 'reach' => 500, 'reach_subscribers' => 300 ), 'visitors' => array( 'views' => 90, 'visitors' => 40 ), 'activity' => array( 'subscribed' => 5, 'unsubscribed' => 2 ) ),
                array( 'period_from' => $day, 'period_to' => $day + DAY_IN_SECONDS, 'reach' => array( 'reach' => 700 ), 'visitors' => array( 'visitors' => 60 ) ),
            ) ) );
    }
    return str_starts_with( $url, 'https://api.vk.com/' ) ? $reply( array( 'error' => array( 'error_code' => 100, 'error_msg' => 'unexpected ' . $method ) ) ) : $pre;
}, 10, 3 );

wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
$model = VKT_AI::save_model( array( 'preset' => 'deepseek', 'key' => 'sk-test-' . str_repeat( 'k', 20 ) ) );
$ok( ! is_wp_error( $model ), 'Тестовая модель для текстов подключена' );
foreach ( array( 111, 222 ) as $id ) {
    $added = $act( $a, 'community_key_add', array( 'token' => 'KEY_' . $id . '_' . str_repeat( 'a', 30 ) ) );
    $ok( ! is_wp_error( $added ), "Группа $id добавлена ключом" );
}
$local = static fn( $group, $user ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND group_id=%d', $user, $group ) );
$g1 = $local( 111, $a );
$g2 = $local( 222, $a );

// 1. Свои группы собираются, но не занимают лимит и не видны среди чужих источников.
wp_set_current_user( $a );
$state = VKT_Groups::state();
$ok( 2 === count( $state['groups'] ) && '' === $state['warning'], 'В «Моих сообществах» обе группы' );
$own = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT s.value,s.enabled,sub.own FROM ' . VKT_Store::table( 'subscriptions' ) . ' sub JOIN ' . VKT_Store::table( 'sources' ) . ' s ON s.id=sub.source_id WHERE sub.user_id=%d ORDER BY s.value', $a ), ARRAY_A );
$ok( 2 === count( $own ) && '-111' === $own[0]['value'] && 1 === (int) $own[0]['own'] && 1 === (int) $own[0]['enabled'], 'Каждая группа стала источником сборщика с отметкой «своя»' );
$ok( 0 === VKT_Subscriptions::count( $a ), 'Свои группы не расходуют лимит источников' );
$ok( ! VKT_Store::state()['sources'] && ! VKT_Posts::communities(), 'В «Источниках» и «Сообществах» своих групп нет' );
VKT_Groups::state();
$ok( 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'subscriptions' ) . ' WHERE user_id=%d', $a ) ), 'Повторное открытие не плодит подписок' );

// 2. Посты группы: 10 штук в 19:00 и 10:00 по времени сайта, старше двух суток, и один свежий.
$source = (int) $wpdb->get_var( 'SELECT id FROM ' . VKT_Store::table( 'sources' ) . " WHERE value='-111'" );
$zone = wp_timezone();
for ( $i = 0; $i < 10; ++$i ) {
    $evening = 0 === $i % 2;
    $at = ( new DateTimeImmutable( 'today', $zone ) )->modify( '-' . ( 3 + $i ) . ' days' )->setTime( $evening ? 19 : 10, 0 );
    VKT_Posts::save( array( 'id' => 100 + $i, 'owner_id' => -111, 'from_id' => -111, 'date' => $at->getTimestamp(), 'text' => 'Пост номер ' . $i . ' #осень' . ( $evening ? ' #вечер' : '' ), 'views' => array( 'count' => $evening ? 1000 + $i : 100 + $i ), 'likes' => array( 'count' => 5 ), 'comments' => array( 'count' => 1 ), 'reposts' => array( 'count' => 0 ) ), $source, 2000 );
}
VKT_Posts::save( array( 'id' => 200, 'owner_id' => -111, 'from_id' => -111, 'date' => time() - 3600, 'text' => 'Свежий пост про зиму', 'views' => array( 'count' => 5 ), 'likes' => array( 'count' => 0 ), 'comments' => array( 'count' => 0 ), 'reposts' => array( 'count' => 0 ) ), $source, 2000 );
$digest = VKT_Groups::digest( 111 );
$ok( 11 === $digest['posts'] && '19:00' === $digest['best_hours'][0]['key'] && '10:00' === $digest['best_hours'][1]['key'], 'Сводка: лучшее время — вечер, затем утро' );
$ok( 'Пост номер 8 #осень #вечер' === $digest['top'][0]['text'] && 'осень' === $digest['hashtags'][0], 'Лучший пост и частый хештег найдены' );
$ok( ! in_array( 'Свежий пост про зиму', array_column( $digest['top'], 'text' ), true ) && 'Свежий пост про зиму' === $digest['recent'][0]['text'], 'Свежий пост не портит средние, но виден среди недавних тем' );
$ok( 3 === count( $digest['weak'] ) && null !== $digest['avg_views'] && $digest['avg_views'] > $digest['median_views'] - 1000, 'Слабые посты и средние просмотры посчитаны' );
wp_set_current_user( $a );
$card = array_values( array_filter( VKT_Groups::state()['groups'], static fn( $group ) => (int) $group['id'] === $g1 ) )[0];
$ok( 11 === (int) $card['metrics']['posts'] && '19:00' === $card['best_times'][0]['key'] && $source === $card['metrics']['source_id'], 'В списке у группы метрики, лучшее время и ссылка на её посты' );

// 3. Карточка группы — только своя.
$detail = $act( $a, 'group_detail', array( 'id' => $g1 ) );
$ok( ! is_wp_error( $detail ) && 11 === count( $detail['posts'] ) && ! isset( $detail['callback_secret'] ), 'Карточка: посты группы без секретов Callback' );
$ok( is_wp_error( $act( $b, 'group_detail', array( 'id' => $g1 ) ) ) && is_wp_error( $act( $b, 'group_passport', array( 'id' => $g1, 'passport' => 'взлом' ) ) ), 'Чужую группу не открыть и не поправить' );

// 4. Охваты: ключ группы 111 отдаёт, у 222 — понятный отказ.
$stats = $act( $a, 'group_stats', array( 'id' => $g1 ) );
$ok( ! is_wp_error( $stats ) && 'community' === $stats['via'] && 2 === count( $stats['days'] ) && 1200 === $stats['week']['reach'] && 5 === $stats['week']['subscribed'], 'Охваты ключом сообщества: дни и сумма за неделю' );
$denied = $act( $a, 'group_stats', array( 'id' => $g2 ) );
$ok( ! is_wp_error( $denied ) && str_contains( $denied['error'], 'Access denied' ), 'Отказ VK в статистике сохранён дословно' );
wp_set_current_user( $a );
$ok( VKT_Community::key_alive( 222 ), 'Отказ в статистике не помечает ключ группы неисправным' );

// 5. Паспорт и его учёт в генерации.
$ok( is_wp_error( $act( $a, 'group_passport', array( 'id' => $g1, 'passport' => str_repeat( 'а', VKT_Groups::PASSPORT_MAX + 1 ) ) ) ), 'Слишком длинный паспорт не сохраняется' );
$saved = $act( $a, 'group_passport', array( 'id' => $g1, 'passport' => "# Паспорт\n- Закреплено за: Анна\n- Призыв: пишите в сообщения" ) );
$ok( ! is_wp_error( $saved ), 'Паспорт сохранён' );
$GLOBALS['vkt_prompts'] = array();
$series = $act( $a, 'series_generate', array( 'prompt' => 'Неделя про осень', 'count' => 2, 'group_id' => $g1 ) );
$prompt = (string) end( $GLOBALS['vkt_prompts'] );
$ok( ! is_wp_error( $series ) && str_contains( $prompt, 'Закреплено за: Анна' ) && str_contains( $prompt, 'не повторяй' ) && str_contains( $prompt, 'Свежий пост про зиму' ), 'Серия пишется с паспортом и недавними темами группы' );
$before = count( $GLOBALS['vkt_prompts'] );
$act( $a, 'series_generate', array( 'prompt' => 'Неделя про осень', 'count' => 2, 'group_id' => $local( 111, $a ) + 1000 ) );
$ok( count( $GLOBALS['vkt_prompts'] ) === $before + 1 && ! str_contains( (string) end( $GLOBALS['vkt_prompts'] ), 'Анна' ), 'Чужая или несуществующая группа в запрос не попадает' );
$act( $a, 'comments_generate', array( 'group_id' => 111, 'items' => array( array( 'author' => 'Ира', 'post' => 'Пост', 'comment' => 'Сколько стоит?' ) ) ) );
$prompt = (string) end( $GLOBALS['vkt_prompts'] );
$ok( str_contains( $prompt, 'пишите в сообщения' ) && ! str_contains( $prompt, 'не повторяй' ), 'Ответам на комментарии — паспорт без истории постов' );
$draft = $act( $a, 'group_passport_draft', array( 'id' => $g1 ) );
$prompt = (string) end( $GLOBALS['vkt_prompts'] );
$ok( ! is_wp_error( $draft ) && str_starts_with( $draft['passport'], '# Паспорт' ) && ! str_contains( $draft['passport'], '```' ) && str_contains( $prompt, 'Закреплено за: Анна' ) && str_contains( $prompt, '19:00' ), 'Черновик паспорта: по постам и цифрам, заполненное сохраняется, без обёртки кода' );
$ok( is_wp_error( $act( $a, 'group_passport_draft', array( 'id' => $g2 ) ) ), 'Без собранных постов черновик не делается — сначала сборщик' );

// 6. Скрытие: сбор на паузе, история на месте, возврат включает сбор.
$act( $a, 'group_hide', array( 'id' => $g1, 'hidden' => true ) );
wp_set_current_user( $a );
VKT_Groups::state();
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT sub.enabled,s.enabled AS source_enabled FROM ' . VKT_Store::table( 'subscriptions' ) . ' sub JOIN ' . VKT_Store::table( 'sources' ) . ' s ON s.id=sub.source_id WHERE sub.user_id=%d AND s.value=%s', $a, '-111' ), ARRAY_A );
$ok( 0 === (int) $row['enabled'] && 0 === (int) $row['source_enabled'] && 11 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'posts' ) . ' WHERE owner_id=-111' ), 'Скрытая группа не собирается, её посты сохранены' );
$act( $a, 'group_hide', array( 'id' => $g1, 'hidden' => false ) );
wp_set_current_user( $a );
VKT_Groups::state();
$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT sub.enabled FROM ' . VKT_Store::table( 'subscriptions' ) . ' sub JOIN ' . VKT_Store::table( 'sources' ) . ' s ON s.id=sub.source_id WHERE sub.user_id=%d AND s.value=%s', $a, '-111' ) ), 'Возвращённая группа снова собирается' );

// 7. Та же группа, добавленная руками в «Источники», становится обычным источником.
$manual = $act( $a, 'source', array( 'kind' => 'owner', 'value' => '-111' ) );
wp_set_current_user( $a );
$ok( ! is_wp_error( $manual ) && 1 === VKT_Subscriptions::count( $a ) && 1 === count( VKT_Store::state()['sources'] ), 'Добавленная руками своя группа видна в «Источниках» и занимает лимит' );

// 8. Группа ушла из кабинета — её слежение снимается.
$wpdb->delete( VKT_Store::table( 'publishing_groups' ), array( 'id' => $g2 ) );
wp_set_current_user( $a );
VKT_Groups::state();
$ok( ! $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . VKT_Store::table( 'subscriptions' ) . ' sub JOIN ' . VKT_Store::table( 'sources' ) . ' s ON s.id=sub.source_id WHERE sub.user_id=%d AND s.value=%s', $a, '-222' ) ), 'Ушедшая из кабинета группа больше не собирается' );

delete_option( 'vkt_text_models' );
update_option( 'vkt_settings', $settings_before, false );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $a );
wp_delete_user( $b );
echo "All $checks groups integration checks passed.\n";

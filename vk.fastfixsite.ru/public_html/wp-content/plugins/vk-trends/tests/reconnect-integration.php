<?php
/**
 * Переподключение пользовательского токена из мессенджера на настоящем
 * WordPress и MySQL: напоминание с кнопкой «Обновить токен», приём адреса со
 * страницы VK ботом Telegram и сообществом VK, одно напоминание вместо
 * стопки. Запуск только в изолированной установке:
 * wp eval-file tests/reconnect-integration.php
 * Сеть подменяется: ни один запрос не уходит наружу.
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
$table = VKT_Store::table( 'notifications' );
$wpdb->query( "TRUNCATE TABLE $table" );
foreach ( array( 'api', 'tg_poll', 'vk_poll' ) as $lock ) { VKT_Store::unlock( $lock ); }
foreach ( get_users( array( 'role__in' => array( 'vkt_member' ) ) ) as $old ) { wp_delete_user( $old->ID ); }
$options = array( 'vkt_notify', 'vkt_tg_offset', 'vkt_tg_pending', 'vkt_reconnect_pending', 'vkt_notify_health_at', 'vkt_notify_funds_at' );
foreach ( $options as $option ) { delete_option( $option ); }
$settings_before = get_option( 'vkt_settings' );
update_option( 'vkt_settings', array_merge( (array) $settings_before, array( 'app_id' => 54770323 ) ), false );
$admin = (int) get_user_by( 'login', 'admin' )->ID;
$secret_before = VKT_Account::act_as( $admin, static fn() => VKT_Tokens::get( 'app_secret' ) );
VKT_Account::act_as( $admin, static fn() => VKT_Tokens::save( 'app_secret', 'APPSECRET_' . str_repeat( 's', 20 ) ) );
$a = (int) wp_insert_user( array( 'user_login' => 'reconnect_a', 'user_pass' => wp_generate_password(), 'role' => VKT_Account::ROLE, 'display_name' => 'reconnect_a' ) );
update_user_meta( $a, 'vkt_status', 'active' );
update_user_meta( $a, 'vkt_vk_id', 555 );
update_user_meta( $a, 'vkt_notify', array( 'vk' => true, 'tg' => 9001, 'tg_name' => 'reconnect_tg' ) );

$GLOBALS['vkt_vk_sent'] = array();
$GLOBALS['vkt_vk_read'] = array();
$GLOBALS['vkt_vk_dialogs'] = array();
$GLOBALS['vkt_tg_sent'] = array();
$GLOBALS['vkt_tg_updates'] = array();
$GLOBALS['vkt_exchanged'] = array();
$GLOBALS['vkt_order'] = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    $reply = static fn( $data, $code = 200 ) => array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $data ) );
    if ( str_starts_with( $url, 'https://oauth.vk.com/access_token?' ) ) {
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
        $GLOBALS['vkt_exchanged'][] = $query['code'] ?? '';
        if ( ! str_starts_with( (string) ( $query['code'] ?? '' ), 'GOOD' ) ) {
            return $reply( array( 'error' => 'invalid_grant', 'error_description' => 'Code is invalid or expired' ), 401 );
        }
        return $reply( array( 'access_token' => 'vk1.a.' . $query['code'] . str_repeat( 'z', 40 ), 'expires_in' => 86400, 'user_id' => 555 ) );
    }
    if ( str_starts_with( $url, 'https://api.telegram.org/' ) ) {
        $method = basename( $url );
        $body = json_decode( (string) ( $args['body'] ?? '' ), true );
        if ( 'getMe' === $method ) { return $reply( array( 'ok' => true, 'result' => array( 'username' => 'vkt_test_bot' ) ) ); }
        if ( 'getUpdates' === $method ) { return $reply( array( 'ok' => true, 'result' => $GLOBALS['vkt_tg_updates'] ) ); }
        if ( 'sendMessage' === $method ) { $GLOBALS['vkt_tg_sent'][] = $body; return $reply( array( 'ok' => true, 'result' => array( 'message_id' => 1 ) ) ); }
    }
    if ( str_starts_with( $url, 'https://api.vk.com/method/' ) ) {
        $method = basename( $url );
        $body = (array) ( $args['body'] ?? array() );
        $GLOBALS['vkt_order'][] = $method;
        switch ( $method ) {
            case 'groups.getTokenPermissions': return $reply( array( 'response' => array( 'mask' => 4096 + 8192 ) ) );
            case 'groups.getById': return $reply( array( 'response' => array( 'groups' => array( array( 'id' => 111, 'name' => 'Бот' ) ) ) ) );
            case 'messages.getConversations': return $reply( array( 'response' => array( 'count' => count( $GLOBALS['vkt_vk_dialogs'] ), 'items' => $GLOBALS['vkt_vk_dialogs'] ) ) );
            case 'messages.markAsRead': $GLOBALS['vkt_vk_read'][] = (int) $body['peer_id']; return $reply( array( 'response' => 1 ) );
            case 'messages.send': $GLOBALS['vkt_vk_sent'][] = $body; return $reply( array( 'response' => 1 ) );
            case 'users.get': return $reply( array( 'response' => array( array( 'id' => 555, 'first_name' => 'A', 'last_name' => 'B' ) ) ) );
        }
        return $reply( array( 'error' => array( 'error_code' => 100, 'error_msg' => 'unexpected ' . $method ) ) );
    }
    return $pre;
}, 10, 3 );
$site = VKT_Account::act_as( $admin, static fn() => VKT_Notify::save_site( array( 'vk_token' => 'BOT_111_' . str_repeat( 'b', 30 ), 'tg_token' => '123456789:' . str_repeat( 'T', 35 ) ) ) );
$ok( ! is_wp_error( $site ) && $site['notify']['vk']['ready'] && $site['notify']['tg']['ready'], 'Боты VK и Telegram подключены' );
$ok( VKT_Account::act_as( $a, array( 'VKT_OAuth', 'configured' ) ), 'Приложение сайта настроено для кабинета' );

$kinds = static fn( $prefix ) => (array) $wpdb->get_results( $wpdb->prepare( "SELECT id,kind,push,read_at,title FROM $table WHERE user_id=%d AND kind LIKE %s", $a, $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
$issues = static fn() => VKT_Account::act_as( $a, array( 'VKT_Health', 'issues' ) );
$quiet_sweeps = static function () {
    update_option( 'vkt_notify_health_at', time(), false );
    update_option( 'vkt_notify_funds_at', time(), false );
};

// ——— Напоминание до конца срока ———
VKT_Account::act_as( $a, static fn() => VKT_Tokens::save( 'user', 'vk1.a.OLD' . str_repeat( 'o', 40 ), array( 'expires_in' => 2 * HOUR_IN_SECONDS, 'scope' => 'wall,photos,groups,video' ) ) );
$soon = array_values( array_filter( $issues(), static fn( $issue ) => ! empty( $issue['reconnect'] ) ) );
$ok( 1 === count( $soon ) && 'warning' === $soon[0]['level'] && str_contains( $soon[0]['title'], 'истекает через 2 ч' ) && str_contains( $soon[0]['text'], 'Действует до' ), 'Скорый конец срока — предупреждение с пометкой «переподключить из сообщения»' );
// Стопка карточек прошлых версий: одинаковые «не действует» по штуке в день.
$legacy = 'health_' . substr( md5( 'Пользовательский токен VK не действует' ), 0, 12 );
for ( $day = 3; $day >= 1; --$day ) {
    $wpdb->insert( $table, array( 'user_id' => $a, 'kind' => $legacy, 'level' => 'error', 'title' => 'Пользовательский токен VK не действует', 'body' => 'старое', 'view' => 'posting', 'push' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $day * DAY_IN_SECONDS ) ) );
}
VKT_Notify::health( $a, $issues() );
$rows = $kinds( VKT_Notify::RECONNECT );
$ok( 1 === count( $rows ) && 1 === (int) $rows[0]['push'], 'Предупреждение о токене записано и ждёт отправки' );
$ok( time() < (int) get_option( 'vkt_reconnect_pending' ), 'Пока токен истекает, cron слушает ботов' );
$quiet_sweeps();
wp_set_current_user( 0 );
VKT_Notify::cron();
$tg = (array) end( $GLOBALS['vkt_tg_sent'] );
$button = $tg['reply_markup']['inline_keyboard'][0][0] ?? array();
$ok( 9001 === (int) $tg['chat_id'] && 'Обновить токен' === ( $button['text'] ?? '' ) && str_starts_with( (string) ( $button['url'] ?? '' ), 'https://oauth.vk.com/authorize?' ) && str_contains( $button['url'], 'client_id=54770323' ) && str_contains( $button['url'], 'response_type=code' ), 'В Telegram — кнопка «Обновить токен» со ссылкой согласия VK' );
$ok( str_contains( $tg['text'], 'пришлите его сюда' ) && ! str_contains( $tg['text'], 'APPSECRET_' ), 'В тексте — что делать дальше; защищённого ключа в сообщении нет' );
$vk = (array) end( $GLOBALS['vkt_vk_sent'] );
$ok( 555 === (int) $vk['user_id'] && str_contains( $vk['message'], 'Обновить токен: https://oauth.vk.com/authorize?' ), 'В VK — та же ссылка строкой' );
$ok( 0 === (int) $kinds( VKT_Notify::RECONNECT )[0]['push'], 'Отправленное напоминание повторно не уходит' );

// ——— Адрес из Telegram ———
$GLOBALS['vkt_tg_sent'] = array();
$GLOBALS['vkt_tg_updates'] = array(
    array( 'update_id' => 70, 'message' => array( 'text' => 'https://oauth.vk.com/blank.html?code=BADCODE123', 'chat' => array( 'id' => 9001, 'type' => 'private' ), 'from' => array( 'username' => 'reconnect_tg' ) ) ),
    array( 'update_id' => 71, 'message' => array( 'text' => 'https://oauth.vk.com/blank.html?code=GOODTG12345', 'chat' => array( 'id' => 9555, 'type' => 'private' ), 'from' => array( 'username' => 'stranger' ) ) ),
    array( 'update_id' => 72, 'message' => array( 'text' => "вот\nhttps://oauth.vk.com/blank.html?code=GOODTG12345", 'chat' => array( 'id' => 9001, 'type' => 'private' ), 'from' => array( 'username' => 'reconnect_tg' ) ) ),
);
$quiet_sweeps();
VKT_Notify::cron();
$GLOBALS['vkt_tg_updates'] = array();
$answers = array_column( $GLOBALS['vkt_tg_sent'], 'text', 'chat_id' );
$to_owner = array_values( array_filter( $GLOBALS['vkt_tg_sent'], static fn( $sent ) => 9001 === (int) $sent['chat_id'] ) );
$ok( 2 === count( $to_owner ) && str_starts_with( $to_owner[0]['text'], 'Не получилось' ) && str_contains( $to_owner[0]['text'], 'Новая ссылка: https://oauth.vk.com/authorize?' ), 'Негодный код — объяснение и сразу новая ссылка' );
$ok( str_contains( (string) ( $answers[9555] ?? '' ), 'не привязан' ), 'Чужому чату токен не подключается' );
$ok( array( 'BADCODE123', 'GOODTG12345' ) === $GLOBALS['vkt_exchanged'], 'Код из чужого чата на токен не меняется' );
$entry = VKT_Account::act_as( $a, static fn() => VKT_Tokens::get( 'user' ) );
$ok( str_starts_with( $entry['access_token'], 'vk1.a.GOODTG12345' ) && $entry['expires_at'] > time() + 23 * HOUR_IN_SECONDS, 'Код из привязанного чата обменян: новый токен на сутки у владельца чата' );
$ok( str_starts_with( $to_owner[1]['text'], 'Готово' ) && str_contains( $to_owner[1]['text'], 'действует до' ), 'Бот отвечает «Готово» и называет срок' );
$ok( ! array_filter( $kinds( VKT_Notify::RECONNECT ), static fn( $row ) => null === $row['read_at'] || 1 === (int) $row['push'] ), 'Напоминание о токене погашено' );
$ok( ! array_filter( $kinds( 'health_' ), static fn( $row ) => null === $row['read_at'] ), 'Старая стопка «токен не действует» тоже погашена' );
$ok( ! array_filter( $issues(), static fn( $issue ) => ! empty( $issue['reconnect'] ) ), 'Неполадки с токеном больше нет' );

// ——— Истёкший токен: одно напоминание в день вместо стопки ———
// Короче минуты срок не сохранить — переписываем его в прошлое в обход save(): так выглядит токен, проживший сутки.
$expire = Closure::bind( static function ( $user_id ) {
    VKT_Account::act_as( $user_id, static function () {
        $entry = self::get( 'user' );
        $entry['expires_at'] = time() - 60;
        self::write( 'user', $entry );
    } );
}, null, VKT_Tokens::class );
$dead = static function () use ( $a ) {
    $issues = VKT_Account::act_as( $a, array( 'VKT_Health', 'issues' ) );
    return array_values( array_filter( $issues, static fn( $issue ) => ! empty( $issue['reconnect'] ) ) );
};
VKT_Account::act_as( $a, static fn() => VKT_Tokens::save( 'user', 'vk1.a.DEAD' . str_repeat( 'd', 40 ), array( 'expires_in' => 3600, 'scope' => 'wall,photos,groups,video' ) ) );
$expire( $a );
$now_dead = $dead();
$ok( 1 === count( $now_dead ) && 'error' === $now_dead[0]['level'] && str_contains( $now_dead[0]['text'], 'Срок действия истёк' ) && ! str_contains( $now_dead[0]['text'], 'UTC' ), 'Истёкший токен — ошибка со сроком по времени сайта' );
VKT_Notify::health( $a, $now_dead );
$seen = get_user_meta( $a, 'vkt_notify_health', true );
foreach ( $seen as $key => $sent ) { $seen[ $key ] = $sent - DAY_IN_SECONDS - 60; }
update_user_meta( $a, 'vkt_notify_health', $seen );
VKT_Notify::health( $a, $now_dead );
$dead_rows = array_values( array_filter( $kinds( VKT_Notify::RECONNECT ), static fn( $row ) => str_contains( $row['title'], 'не действует' ) ) );
$ok( 1 === count( $dead_rows ) && 1 === (int) $dead_rows[0]['push'] && null === $dead_rows[0]['read_at'], 'Назавтра напоминание заменяет вчерашнее, а не ложится рядом' );
$ok( ! $kinds( 'health_' ), 'Старая стопка удалена при первом новом напоминании' );

// ——— Адрес из VK ———
$GLOBALS['vkt_order'] = array();
$GLOBALS['vkt_vk_sent'] = array();
$GLOBALS['vkt_exchanged'] = array();
$GLOBALS['vkt_vk_dialogs'] = array(
    array( 'conversation' => array( 'peer' => array( 'id' => 777 ) ), 'last_message' => array( 'from_id' => 777, 'text' => 'Здравствуйте, вопрос по рекламе' ) ),
    array( 'conversation' => array( 'peer' => array( 'id' => 555 ) ), 'last_message' => array( 'from_id' => 555, 'text' => 'https://oauth.vk.com/blank.html?code=GOODVK12345' ) ),
);
$quiet_sweeps();
VKT_Notify::cron();
$GLOBALS['vkt_vk_dialogs'] = array();
$entry = VKT_Account::act_as( $a, static fn() => VKT_Tokens::get( 'user' ) );
$ok( str_starts_with( $entry['access_token'], 'vk1.a.GOODVK12345' ), 'Адрес, присланный сообществу-боту в VK, подключил токен' );
$ok( array( 555 ) === $GLOBALS['vkt_vk_read'], 'Прочитанным отмечен только диалог с кодом — вопрос человека остался непрочитанным' );
$ok( array_search( 'messages.markAsRead', $GLOBALS['vkt_order'], true ) < array_search( 'messages.send', $GLOBALS['vkt_order'], true ), 'Диалог отмечен прочитанным до ответа — код не потратится второй раз' );
$reply = array_values( array_filter( $GLOBALS['vkt_vk_sent'], static fn( $sent ) => 555 === (int) $sent['user_id'] && str_starts_with( (string) $sent['message'], 'Готово' ) ) );
$ok( 1 === count( $reply ), 'Сообщество ответило «Готово»' );

// Закрытый кабинет по присланному адресу токен не получает.
update_user_meta( $a, 'vkt_status', 'blocked' );
$GLOBALS['vkt_vk_sent'] = array();
$GLOBALS['vkt_exchanged'] = array();
$GLOBALS['vkt_vk_dialogs'] = array( array( 'conversation' => array( 'peer' => array( 'id' => 555 ) ), 'last_message' => array( 'from_id' => 555, 'text' => 'https://oauth.vk.com/blank.html?code=GOODVK99999' ) ) );
update_option( 'vkt_reconnect_pending', time() + 60, false );
$quiet_sweeps();
VKT_Notify::cron();
$GLOBALS['vkt_vk_dialogs'] = array();
$ok( ! $GLOBALS['vkt_exchanged'] && str_contains( (string) ( $GLOBALS['vkt_vk_sent'][0]['message'] ?? '' ), 'не связан' ), 'Заблокированному кабинету код не обменивается' );

// Когда токен жив, ботов не слушаем.
update_option( 'vkt_reconnect_pending', 0, false );
$GLOBALS['vkt_order'] = array();
$quiet_sweeps();
VKT_Notify::cron();
$ok( ! in_array( 'messages.getConversations', $GLOBALS['vkt_order'], true ), 'Без ожидания переподключения диалоги VK не читаются' );

wp_delete_user( $a );
update_option( 'vkt_settings', $settings_before, false );
VKT_Account::act_as( $admin, static fn() => '' !== (string) $secret_before['access_token'] ? VKT_Tokens::save( 'app_secret', $secret_before['access_token'] ) : VKT_Tokens::forget( 'app_secret' ) );
foreach ( $options as $option ) { delete_option( $option ); }
echo "All $checks reconnect integration checks passed.\n";

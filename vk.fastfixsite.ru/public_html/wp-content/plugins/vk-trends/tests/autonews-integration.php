<?php
/**
 * Автосбор новостей и оповещения на настоящем WordPress и MySQL: расписание
 * в колонке группы, заход cron, раскладка в серию, лимиты, колокольчик,
 * сообщения VK и Telegram. Запуск только в изолированной установке:
 * wp eval-file tests/autonews-integration.php
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
foreach ( array( 'publishing_groups', 'outbound_posts', 'outbound_deliveries', 'notifications', 'logs' ) as $name ) {
    $wpdb->query( 'TRUNCATE TABLE ' . VKT_Store::table( $name ) );
}
foreach ( array( 'api', 'publisher', 'replies', 'autonews', 'tg_poll' ) as $lock ) { VKT_Store::unlock( $lock ); }
foreach ( get_users( array( 'role__in' => array( 'vkt_member' ) ) ) as $old ) { wp_delete_user( $old->ID ); }
foreach ( array( 'vkt_text_models', 'vkt_notify', 'vkt_tg_offset', 'vkt_tg_pending', 'vkt_notify_health_at', 'vkt_notify_funds_at' ) as $option ) { delete_option( $option ); }
$settings_before = get_option( 'vkt_settings' );
update_option( 'vkt_settings', array_merge( (array) $settings_before, array( 'ai_text_daily' => 5 ) ), false );
$admin = (int) get_user_by( 'login', 'admin' )->ID;
delete_user_meta( $admin, 'vkt_notify' );
delete_user_meta( $admin, 'vkt_notify_health' );
$a = (int) wp_insert_user( array( 'user_login' => 'auto_a', 'user_pass' => wp_generate_password(), 'role' => VKT_Account::ROLE, 'display_name' => 'auto_a' ) );
update_user_meta( $a, 'vkt_status', 'active' );
update_user_meta( $a, 'vkt_vk_id', 555 );
$act = static function ( $user, $action, $data = array() ) {
    wp_set_current_user( $user );
    VKT_Store::unlock( 'api' );
    $request = new WP_REST_Request( 'POST', '/vk-trends/v1/action' );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( array_merge( array( 'action' => $action ), $data ) ) );
    return VKT_Plugin::action( $request );
};

// Три ленты: о матче пишут все три издания, о трансфере — два, о погоде — одно.
$feed = static function ( $items ) {
    $xml = '<rss><channel>';
    foreach ( $items as $index => $item ) {
        $xml .= '<item><title>' . $item[0] . '</title><link>' . $item[1] . '</link><pubDate>' . gmdate( 'r', time() - 600 - $index * 60 ) . '</pubDate><description>Анонс: ' . $item[0] . '</description></item>';
    }
    return $xml . '</channel></rss>';
};
$feeds = array(
    'https://example.com/vkt-a/rss' => $feed( array( array( 'Погода в Иркутске испортится к выходным', 'https://example.com/vkt-a/weather' ), array( 'Зенит обыграл Спартак в матче тура со счётом 3:1', 'https://example.com/vkt-a/match' ) ) ),
    'https://example.org/vkt-b/rss' => $feed( array( array( 'Спартак проиграл Зениту матч тура — 1:3', 'https://example.org/vkt-b/match' ), array( 'Нападающий Иванов перешёл в Динамо за рекордную сумму', 'https://example.org/vkt-b/transfer' ) ) ),
    'https://example.net/vkt-c/rss' => $feed( array( array( 'Иванов перешёл в Динамо: рекордная сумма трансфера', 'https://example.net/vkt-c/transfer' ), array( 'Матч тура: Зенит победил Спартак', 'https://example.net/vkt-c/match' ) ) ),
);
$GLOBALS['vkt_prompts'] = array();
$GLOBALS['vkt_sent'] = array();
$GLOBALS['vkt_tg_sent'] = array();
$GLOBALS['vkt_tg_updates'] = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $feeds ) {
    $reply = static fn( $data, $code = 200 ) => array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $data ) );
    if ( isset( $feeds[ $url ] ) ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => $feeds[ $url ] );
    }
    // Страница статьи объявляет главное фото; сам снимок скачивается в файл.
    if ( preg_match( '~/vkt-[abc]/(match|transfer|weather)$~', $url ) ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => '<html><head><meta property="og:image" content="https://example.com/vkt-img/photo.jpg"></head><body><article><p>Текст статьи.</p></article></body></html>' );
    }
    if ( 'https://example.com/vkt-img/photo.jpg' === $url ) {
        $image = imagecreatetruecolor( 800, 500 );
        imagejpeg( $image, $args['filename'] );
        return array( 'response' => array( 'code' => 200 ), 'body' => '' );
    }
    if ( str_ends_with( $url, '/chat/completions' ) ) {
        if ( ! empty( $GLOBALS['vkt_ai_broke'] ) ) {
            return $reply( array( 'error' => array( 'message' => 'Insufficient Balance' ) ), 402 );
        }
        $prompt = (string) end( json_decode( (string) $args['body'], true )['messages'] )['content'];
        $GLOBALS['vkt_prompts'][] = $prompt;
        if ( str_contains( $prompt, 'Ниже список свежих новостей' ) ) {
            return $reply( array( 'choices' => array( array( 'message' => array( 'content' => '{"news":[{"id":1,"text":"Первая новость\nТекст первой"},{"id":2,"text":"Вторая новость\nТекст второй"}]}' ) ) ) ) );
        }
        return $reply( array( 'choices' => array( array( 'message' => array( 'content' => 'готово' ) ) ) ) );
    }
    if ( str_starts_with( $url, 'https://api.telegram.org/' ) ) {
        $method = basename( $url );
        $body = json_decode( (string) ( $args['body'] ?? '' ), true );
        if ( 'getMe' === $method ) { return $reply( array( 'ok' => true, 'result' => array( 'username' => 'vkt_test_bot' ) ) ); }
        if ( 'getUpdates' === $method ) { $GLOBALS['vkt_tg_offset_seen'] = $body['offset'] ?? null; return $reply( array( 'ok' => true, 'result' => $GLOBALS['vkt_tg_updates'] ) ); }
        if ( 'sendMessage' === $method ) {
            if ( ! empty( $GLOBALS['vkt_tg_blocked'] ) ) { return $reply( array( 'ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user' ), 403 ); }
            $GLOBALS['vkt_tg_sent'][] = $body;
            return $reply( array( 'ok' => true, 'result' => array( 'message_id' => 1 ) ) );
        }
    }
    if ( str_starts_with( $url, 'https://api.vk.com/method/' ) ) {
        $method = basename( $url );
        $body = (array) ( $args['body'] ?? array() );
        switch ( $method ) {
            case 'groups.getTokenPermissions':
                $messages = str_starts_with( (string) $body['access_token'], 'BOT_' );
                return $reply( array( 'response' => array( 'mask' => $messages ? 4096 + 8192 : 8192, 'permissions' => array( array( 'name' => 'wall' ), array( 'name' => 'manage' ) ) ) ) );
            case 'groups.getById': return $reply( array( 'response' => array( 'groups' => array( array( 'id' => 111, 'name' => 'Группа 111', 'screen_name' => 'g111' ) ) ) ) );
            case 'messages.send':
                if ( ! empty( $GLOBALS['vkt_vk_closed'] ) ) { return $reply( array( 'error' => array( 'error_code' => 901, 'error_msg' => "Can't send messages for users without permission" ) ) ); }
                $GLOBALS['vkt_sent'][] = $body;
                return $reply( array( 'response' => 1 ) );
            case 'wall.post': return $reply( array( 'response' => array( 'post_id' => 900 ) ) );
        }
        return $reply( array( 'error' => array( 'error_code' => 100, 'error_msg' => 'unexpected ' . $method ) ) );
    }
    return $pre;
}, 10, 3 );

wp_set_current_user( $admin );
$model = $act( $admin, 'ai_model_save', array( 'preset' => 'deepseek', 'key' => 'sk-test-' . str_repeat( 'k', 20 ) ) );
$ok( ! is_wp_error( $model ), 'Модель для текста подключена' );
$added = $act( $a, 'community_key_add', array( 'token' => 'KEY_111_' . str_repeat( 'a', 30 ) ) );
$ok( ! is_wp_error( $added ) && 111 === $added['group_id'], 'Группа добавлена ключом' );
$table = VKT_Store::table( 'publishing_groups' );
$local = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id=%d AND group_id=111", $a ) );
$due = static fn() => $wpdb->get_var( $wpdb->prepare( "SELECT news_auto_at FROM $table WHERE id=%d", $local ) );
$news = array( 'enabled' => true, 'method' => 'rss', 'sources' => implode( "\n", array_keys( $feeds ) ), 'topic' => 'Спорт', 'count' => 10, 'days' => 1, 'mode' => 'posts' );

// ——— Расписание ———
$saved = $act( $a, 'group_news_save', array( 'id' => $local, 'news' => $news ) );
$ok( ! is_wp_error( $saved ) && ! $saved['news']['auto']['enabled'] && null === $due(), 'Без автосбора срок не назначен' );
$saved = $act( $a, 'group_news_save', array( 'id' => $local, 'news' => $news + array( 'auto' => array( 'enabled' => true, 'hours' => 2, 'pick' => 'mentions', 'limit' => 2, 'photo' => 'none', 'last' => 'подделка' ) ) ) );
$first = $due();
$ok( ! is_wp_error( $saved ) && $saved['news']['auto']['enabled'] && 2 === $saved['news']['auto']['hours'] && '' === $saved['news']['auto']['last'], 'Автосбор включён; итог прошлого захода из формы не принимается' );
$ok( null !== $first && strtotime( $first . ' UTC' ) <= time() + 61 && $first === $saved['news']['auto']['next_at'], 'Первый заход назначен на ближайшую минуту и виден в настройках' );
$act( $a, 'group_news_save', array( 'id' => $local, 'news' => $news + array( 'auto' => array( 'enabled' => true, 'hours' => 6, 'pick' => 'mentions', 'limit' => 2, 'photo' => 'none' ) ) ) );
$ok( $first === $due(), 'Повторное сохранение не сбивает назначенный срок' );

// ——— Заход cron ———
wp_set_current_user( 0 );
VKT_Autonews::cron();
$ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'outbound_posts' ) ), 'Раньше срока cron группу не трогает' );
$wpdb->update( $table, array( 'news_auto_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $local ) );
VKT_Autonews::cron();
$posts = (array) $wpdb->get_results( 'SELECT * FROM ' . VKT_Store::table( 'outbound_posts' ) . ' ORDER BY scheduled_at', ARRAY_A );
$ok( 2 === count( $posts ) && (int) $posts[0]['user_id'] === $a, 'Заход создал две записи в кабинете владельца группы' );
$ok( '' !== $posts[0]['series_id'] && $posts[0]['series_id'] === $posts[1]['series_id'] && str_starts_with( $posts[0]['series_title'], 'Новости · авто · ' ), 'Записи встали в одну серию дня' );
$gap = strtotime( $posts[1]['scheduled_at'] ) - strtotime( $posts[0]['scheduled_at'] );
$ok( 'scheduled' === $posts[0]['status'] && strtotime( $posts[0]['scheduled_at'] . ' UTC' ) > time() + 200 && abs( $gap - 3 * HOUR_IN_SECONDS ) < 5, 'Первая запись через пять минут, вторая — через половину интервала' );
$ok( str_contains( $posts[0]['message'], 'Первая новость' ) && str_contains( $posts[0]['message'], 'Источник: https://example.' ) && str_contains( $posts[0]['message'], '/match' ), 'Самое упоминаемое — матч — идёт первым, источник приписан плагином' );
$ok( str_contains( $posts[1]['message'], '/transfer' ), 'Вторая по упоминаниям — трансфер' );
$prompt = (string) end( $GLOBALS['vkt_prompts'] );
$ok( str_contains( $prompt, '"mentions":3' ) && str_contains( $prompt, '"mentions":2' ) && 3 === substr_count( $prompt, '"mentions":' ) && str_contains( $prompt, 'по числу изданий' ), 'Модель получила три события вместо шести статей, со счётчиками упоминаний' );
$next = $due();
$ok( abs( strtotime( $next . ' UTC' ) - ( time() + 6 * HOUR_IN_SECONDS ) ) < 30, 'Следующий заход — через интервал' );
$detail = $act( $a, 'group_detail', array( 'id' => $local ) );
$auto = is_wp_error( $detail ) ? array() : $detail['news']['auto'];
$ok( ! empty( $auto['last_ok'] ) && str_contains( (string) $auto['last'], 'в сетку поставлено: 2' ) && $auto['series'] === $posts[0]['series_id'], 'Итог захода и серия дня записаны в настройки группы' );
$ok( 5 === $detail['news']['used'], 'Использованными отмечены все статьи о взятых событиях, а не только пересказанные' );
$ok( 1 === (int) get_user_meta( $a, 'vkt_ai_usage', true )['text'], 'Заход списал один текст лимита владельца' );
$bell = VKT_Notify::inbox( $a );
$ok( 1 === $bell['unread'] && str_starts_with( $bell['items'][0]['title'], 'Автосбор новостей' ) && 'info' === $bell['items'][0]['level'], 'Итог захода попал в колокольчик' );
$ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'notifications' ) . ' WHERE push=1' ), 'Удачный заход без ручной проверки сообщением не шлётся' );

// Второй заход в тот же день: матч и трансфер уже взяты, осталась погода.
$hurry = $act( $a, 'group_news_auto_run', array( 'id' => $local ) );
$ok( ! is_wp_error( $hurry ) && strtotime( $due() . ' UTC' ) <= time(), '«Собрать сейчас» переносит срок на эту минуту' );
wp_set_current_user( 0 );
VKT_Autonews::cron();
$messages = (array) $wpdb->get_col( 'SELECT message FROM ' . VKT_Store::table( 'outbound_posts' ) . ' ORDER BY id' );
$ok( 3 === count( $messages ) && str_contains( $messages[2], '/weather' ) && 1 === count( array_filter( $messages, static fn( $text ) => str_contains( $text, '/match' ) ) ), 'Повторный заход берёт только ещё не взятое событие' );
// Третий заход: всё найденное использовано.
$act( $a, 'group_news_auto_run', array( 'id' => $local ) );
wp_set_current_user( 0 );
VKT_Autonews::cron();
$detail = $act( $a, 'group_detail', array( 'id' => $local ) );
$ok( 3 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'outbound_posts' ) ) && $detail['news']['auto']['last_ok'] && str_contains( $detail['news']['auto']['last'], 'нет' ), 'Когда свежего нет, заход ничего не ставит' );
// Назавтра о матче выходит новая статья с новой ссылкой — событие то же, публиковать его снова нельзя.
$again = VKT_News::rank( array( array( 'title' => 'Зенит обыграл Спартак: итоги матча тура', 'link' => 'https://example.com/vkt-a/match-2', 'date' => time(), 'summary' => '', 'source' => 'example.com' ), array( 'title' => 'В Иркутске открыли новый мост через Ангару', 'link' => 'https://example.com/vkt-a/bridge', 'date' => time(), 'summary' => '', 'source' => 'example.com' ) ), array( 'Зенит обыграл Спартак в матче тура со счётом 3:1' ) );
$ok( 1 === count( $again ) && str_contains( $again[0]['title'], 'мост' ), 'Новая статья об уже опубликованном событии отбрасывается' );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'notifications' ) . " WHERE user_id=%d AND kind LIKE 'autonews_fail%%'", $a ) ), '«Свежего нет» не считается сбоем' );

// ——— Лимит текстов ———
update_user_meta( $a, 'vkt_ai_usage', array( 'day' => wp_date( 'Y-m-d' ), 'text' => 3, 'media' => 0 ) );
VKT_Account::act_as( $a, static fn() => VKT_Account::ai_spend( 'text' ) );
$kinds = static fn( $user ) => (array) $wpdb->get_col( $wpdb->prepare( 'SELECT kind FROM ' . VKT_Store::table( 'notifications' ) . ' WHERE user_id=%d', $user ) );
$ok( in_array( 'limit_text_low', $kinds( $a ), true ) && ! in_array( 'limit_text_out', $kinds( $a ), true ), 'Осталась пятая часть лимита — предупреждение' );
VKT_Account::act_as( $a, static fn() => VKT_Account::ai_spend( 'text' ) );
$ok( in_array( 'limit_text_out', $kinds( $a ), true ), 'Лимит исчерпан — оповещение' );
$act( $a, 'group_news_reset', array( 'id' => $local ) );
$act( $a, 'group_news_auto_run', array( 'id' => $local ) );
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'outbound_posts' ) );
wp_set_current_user( 0 );
VKT_Autonews::cron();
$detail = $act( $a, 'group_detail', array( 'id' => $local ) );
$ok( $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'outbound_posts' ) ) && ! $detail['news']['auto']['last_ok'] && str_contains( $detail['news']['auto']['last'], 'Лимит' ), 'Без лимита заход ничего не ставит и объясняет почему' );
$ok( ! in_array( 'autonews_fail_' . $local, $kinds( $a ), true ), 'Про лимит второе оповещение не шлётся' );

// ——— Каналы ———
$ok( is_wp_error( $act( $a, 'notify_site', array( 'vk_token' => 'BOT_111_' . str_repeat( 'b', 30 ) ) ) ), 'Ключи бота сохраняет только администратор' );
$ok( is_wp_error( $act( $admin, 'notify_site', array( 'vk_token' => 'KEY_111_' . str_repeat( 'a', 30 ) ) ) ), 'Ключ без права «Сообщения» отклонён' );
$site = $act( $admin, 'notify_site', array( 'vk_token' => 'BOT_111_' . str_repeat( 'b', 30 ), 'tg_token' => '123456789:' . str_repeat( 'T', 35 ), 'bfl_min' => 50 ) );
$ok( ! is_wp_error( $site ) && $site['notify']['vk']['ready'] && 111 === $site['notify']['vk']['group'] && 'vkt_test_bot' === $site['notify']['tg']['bot'] && 50 === $site['notify']['bfl_min'], 'Бот VK и бот Telegram подключены' );
$ok( ! str_contains( wp_json_encode( get_option( 'vkt_notify' ) ), 'BOT_111_' ) && ! str_contains( wp_json_encode( VKT_Plugin::public_settings() ), 'BOT_111_' ), 'Ключи бота лежат зашифрованными и в браузер не уходят' );
$ok( is_wp_error( $act( $a, 'notify_test' ) ), 'Без включённого канала проверка объясняет, что его нет' );
$on = $act( $a, 'notify_save', array( 'vk' => true ) );
$ok( ! is_wp_error( $on ) && $on['notify']['vk']['on'] && 555 === $on['notify']['vk']['vk_id'] && ! $on['notify']['vk']['manual'], 'Участник включил сообщения VK: адрес известен по входу через VK ID' );
$ok( is_wp_error( $act( $admin, 'notify_save', array( 'vk' => true ) ) ), 'Администратору без VK ID нужен ID вручную' );
$ok( ! is_wp_error( $act( $admin, 'notify_save', array( 'vk' => true, 'vk_id' => 777 ) ) ), 'ID вручную принят' );
$test = $act( $a, 'notify_test' );
$ok( ! is_wp_error( $test ) && $test['results'][0]['ok'] && '555' === (string) end( $GLOBALS['vkt_sent'] )['user_id'], 'Проверочное сообщение ушло в VK' );
$GLOBALS['vkt_vk_closed'] = true;
$test = $act( $a, 'notify_test' );
$ok( ! $test['results'][0]['ok'] && str_contains( $test['notify']['vk']['error'], 'отправьте ему любое сообщение' ), 'Отказ VK 901 объяснён человеку' );
$GLOBALS['vkt_vk_closed'] = false;

// Telegram: код из ссылки привязывает чат.
$link = $act( $a, 'notify_tg_link' );
$ok( ! is_wp_error( $link ) && preg_match( '~^https://t\.me/vkt_test_bot\?start=([a-z0-9]{16})$~', $link['url'], $code ), 'Ссылка на бота с одноразовым кодом' );
$ok( is_wp_error( $act( $a, 'notify_tg_check' ) ), 'Пока бот не запущен, привязки нет' );
$GLOBALS['vkt_tg_updates'] = array(
    array( 'update_id' => 40, 'message' => array( 'text' => '/start', 'chat' => array( 'id' => 9000, 'type' => 'private' ), 'from' => array( 'first_name' => 'Чужой' ) ) ),
    array( 'update_id' => 41, 'message' => array( 'text' => '/start ' . $code[1], 'chat' => array( 'id' => 9001, 'type' => 'private' ), 'from' => array( 'username' => 'auto_a_tg' ) ) ),
);
wp_set_current_user( 0 );
VKT_Notify::cron();
$status = VKT_Account::act_as( $a, array( 'VKT_Notify', 'public_status' ) );
$ok( $status['tg']['linked'] && 'auto_a_tg' === $status['tg']['name'] && 42 === (int) get_option( 'vkt_tg_offset' ), 'Cron сам прочитал команду боту и привязал чат' );
$GLOBALS['vkt_tg_updates'] = array();
$ok( ! is_wp_error( $act( $a, 'notify_tg_check' ) ) && 42 === $GLOBALS['vkt_tg_offset_seen'], 'Прочитанные сообщения боту второй раз не запрашиваются' );

// ——— Доставка из очереди ———
$GLOBALS['vkt_sent'] = array();
$GLOBALS['vkt_tg_sent'] = array();
$wpdb->query( 'UPDATE ' . VKT_Store::table( 'notifications' ) . ' SET push=0' );
VKT_Notify::add( $a, 'demo', 'error', 'Ключ группы не действует', 'VK отверг ключ.', 'posting' );
$ok( ! VKT_Notify::add( $a, 'demo', 'error', 'Ключ группы не действует', 'VK отверг ключ.', 'posting' ), 'Одинаковое оповещение в пределах паузы не повторяется' );
update_option( 'vkt_notify_health_at', time(), false );
update_option( 'vkt_notify_funds_at', time(), false );
VKT_Notify::cron();
$ok( 1 === count( $GLOBALS['vkt_sent'] ) && 1 === count( $GLOBALS['vkt_tg_sent'] ) && str_contains( $GLOBALS['vkt_sent'][0]['message'], 'Ключ группы не действует' ) && str_contains( $GLOBALS['vkt_sent'][0]['message'], '#posting' ) && 9001 === $GLOBALS['vkt_tg_sent'][0]['chat_id'], 'Оповещение ушло в оба канала со ссылкой на раздел' );
VKT_Notify::cron();
$ok( 1 === count( $GLOBALS['vkt_sent'] ), 'Отправленное повторно не уходит' );
$GLOBALS['vkt_tg_blocked'] = true;
$GLOBALS['vkt_vk_closed'] = true;
VKT_Notify::add( $a, 'demo2', 'warning', 'Проверка отказа', 'Текст', '' );
VKT_Notify::cron();
$row = $wpdb->get_row( 'SELECT push,attempts,sent_at FROM ' . VKT_Store::table( 'notifications' ) . " WHERE kind='demo2'", ARRAY_A );
$status = VKT_Account::act_as( $a, array( 'VKT_Notify', 'public_status' ) );
$ok( 1 === (int) $row['push'] && 1 === (int) $row['attempts'] && null === $row['sent_at'] && ! $status['tg']['linked'], 'Неудачная отправка ждёт повтора; остановленный бот Telegram отвязан' );
VKT_Notify::cron();
VKT_Notify::cron();
$ok( 0 === (int) $wpdb->get_var( 'SELECT push FROM ' . VKT_Store::table( 'notifications' ) . " WHERE kind='demo2'" ), 'После трёх неудач запись остаётся только в колокольчике' );
$GLOBALS['vkt_tg_blocked'] = false;
$GLOBALS['vkt_vk_closed'] = false;
$read = $act( $a, 'notify_read' );
$ok( ! is_wp_error( $read ) && 0 === $read['unread'] && $read['items'] && '1' === (string) $read['items'][0]['seen'], 'Колокольчик отмечает прочитанное' );

// ——— Неполадки и средства ———
$wpdb->query( 'UPDATE ' . VKT_Store::table( 'notifications' ) . ' SET push=0' );
$issue = array( array( 'level' => 'error', 'title' => 'Публикации с ошибкой: 3', 'text' => 'Причина указана у каждой.', 'view' => 'publishing', 'action' => '', 'at' => null ) );
VKT_Notify::health( $a, $issue );
$issue[0]['title'] = 'Публикации с ошибкой: 7';
VKT_Notify::health( $a, $issue );
$ok( 1 === count( array_filter( $kinds( $a ), static fn( $kind ) => str_starts_with( $kind, 'health_' ) ) ), 'Та же неполадка с другим счётчиком — одно оповещение' );
VKT_Notify::health( $a, array() );
VKT_Notify::health( $a, $issue );
$ok( 1 === count( array_filter( $kinds( $a ), static fn( $kind ) => str_starts_with( $kind, 'health_' ) ) ), 'Мигающая неполадка не шлёт сообщение заново' );
delete_option( 'vkt_notify_health_at' );
wp_set_current_user( 0 );
VKT_Notify::cron();
$ok( time() - (int) get_option( 'vkt_notify_health_at' ) < 5, 'Сверка неполадок кабинетов прошла из cron без пользователя' );
$GLOBALS['vkt_ai_broke'] = true;
$broke = VKT_Account::act_as( $admin, static fn() => VKT_AI::generate_text( 'Напиши пост про осень' ) );
$funds = array_values( array_filter( VKT_Notify::inbox( $admin )['items'], static fn( $item ) => str_contains( $item['title'], 'закончились средства' ) ) );
$ok( is_wp_error( $broke ) && 1 === count( $funds ) && str_contains( $funds[0]['title'], 'DeepSeek' ) && 'settings' === $funds[0]['view'], 'Отказ модели из-за баланса — оповещение администратору' );
$ok( ! array_filter( VKT_Notify::inbox( $a )['items'], static fn( $item ) => str_contains( $item['title'], 'закончились средства' ) ), 'Участнику про счёт сервиса не пишут' );
$GLOBALS['vkt_ai_broke'] = false;

// ——— Ручная проверка публикаций ———
update_user_meta( $a, 'vkt_ai_usage', array( 'day' => wp_date( 'Y-m-d' ), 'text' => 0, 'media' => 0 ) );
update_user_meta( $a, 'vkt_publishing_review', 1 );
VKT_Account::act_as( $a, static fn() => VKT_Tokens::save( 'user', 'USER_' . str_repeat( 'u', 30 ) ) );
$act( $a, 'group_news_save', array( 'id' => $local, 'news' => $news + array( 'auto' => array( 'enabled' => true, 'hours' => 6, 'pick' => 'mentions', 'limit' => 2, 'photo' => 'article' ) ) ) );
$act( $a, 'group_news_reset', array( 'id' => $local ) );
$act( $a, 'group_news_auto_run', array( 'id' => $local ) );
$wpdb->query( 'UPDATE ' . VKT_Store::table( 'notifications' ) . ' SET push=0' );
wp_set_current_user( 0 );
VKT_Autonews::cron();
$drafts = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'outbound_posts' ) . " WHERE status='draft'" );
$pushed = (string) $wpdb->get_var( 'SELECT body FROM ' . VKT_Store::table( 'notifications' ) . " WHERE push=1 AND kind LIKE 'autonews_ok%'" );
$ok( 2 === $drafts && str_contains( $pushed, 'ждут вашей проверки' ), 'С ручной проверкой записи встают черновиками, а человеку уходит сообщение' );
$media = array_filter( array_map( 'absint', (array) $wpdb->get_col( 'SELECT media FROM ' . VKT_Store::table( 'outbound_posts' ) . " WHERE status='draft'" ) ) );
$ok( 2 === count( $media ) && $a === (int) get_post_field( 'post_author', reset( $media ) ), 'К записям приложено главное фото статьи, файл записан за владельцем группы' );
foreach ( $media as $attachment ) { wp_delete_attachment( $attachment, true ); }
$same = (array) $wpdb->get_col( 'SELECT DISTINCT series_id FROM ' . VKT_Store::table( 'outbound_posts' ) );
$ok( 1 === count( $same ), 'Все заходы дня кладут записи в одну серию' );

// Выключение снимает срок.
$off = $act( $a, 'group_news_save', array( 'id' => $local, 'news' => $news + array( 'auto' => array( 'enabled' => false ) ) ) );
$ok( ! is_wp_error( $off ) && null === $due(), 'Выключенный автосбор снимает срок' );
wp_delete_user( $a );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'notifications' ) . ' WHERE user_id=%d', $a ) ), 'Оповещения уходят вместе с кабинетом' );

update_option( 'vkt_settings', $settings_before, false );
foreach ( array( 'vkt_text_models', 'vkt_notify', 'vkt_tg_offset', 'vkt_tg_pending' ) as $option ) { delete_option( $option ); }
delete_user_meta( $admin, 'vkt_notify' );
echo "All $checks autonews and notification integration checks passed.\n";

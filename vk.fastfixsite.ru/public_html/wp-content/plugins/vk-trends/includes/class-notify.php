<?php
defined( 'ABSPATH' ) || exit;

/**
 * Оповещения кабинета: колокольчик в дашборде и личные сообщения.
 *
 * Плагин работает в фоне, и о кончившемся лимите, отвалившемся токене или
 * пустом счёте сервиса человек узнавал, только зайдя в кабинет. Здесь каждое
 * такое событие становится записью в колокольчике и, если подключён канал,
 * сообщением: от имени сообщества в VK или от бота в Telegram.
 *
 * Сообщения уходят из cron, а не в момент события: запрос, на котором
 * кончился лимит, не должен ждать ответа мессенджера.
 */
final class VKT_Notify {
    const OPTION = 'vkt_notify';
    const META = 'vkt_notify';
    const LEVELS = array( 'error', 'warning', 'info' );
    const INBOX = 30;
    const MAX_ATTEMPTS = 3;
    // Неполадки кабинетов сверяем раз в десять минут, баланс сервисов — раз в шесть часов.
    const HEALTH_EVERY = 10 * MINUTE_IN_SECONDS;
    const FUNDS_EVERY = 6 * HOUR_IN_SECONDS;
    // Неполадка, которая то исчезает, то возвращается (повторы очереди), не должна слать сообщение каждый раз.
    const HEALTH_QUIET = 6 * HOUR_IN_SECONDS;
    // Право «Сообщения сообщества» в маске прав ключа.
    const VK_MESSAGES = 4096;
    // Коды VK, когда писать не даёт сам человек: не разрешил сообщения, закрыл личку, заблокировал сообщество.
    const VK_REFUSED = array( 900, 901, 902 );

    public static function schema() {
        return array(
            'notifications' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                kind varchar(80) NOT NULL,
                level varchar(10) NOT NULL DEFAULT 'info',
                title varchar(255) NOT NULL,
                body text NOT NULL,
                view varchar(30) NOT NULL DEFAULT '',
                push tinyint unsigned NOT NULL DEFAULT 0,
                attempts tinyint unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                read_at datetime DEFAULT NULL,
                sent_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY inbox (user_id,id),
                KEY outbox (push,id)",
        );
    }

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_notify', $message, array( 'status' => $status ) );
    }

    // ——— Запись оповещения ———

    /**
     * Новое оповещение. $cooldown — сколько секунд такое же (по $kind) не
     * повторяется: событие вроде «лимит исчерпан» случается на каждом запросе.
     * $push = false — только колокольчик, без сообщения.
     */
    public static function add( $user_id, $kind, $level, $title, $text, $view = '', $push = true, $cooldown = DAY_IN_SECONDS ) {
        global $wpdb;
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }
        $table = VKT_Store::table( 'notifications' );
        $kind = substr( sanitize_key( $kind ), 0, 80 );
        if ( $cooldown > 0 && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id=%d AND kind=%s AND created_at>=%s LIMIT 1", $user_id, $kind, gmdate( 'Y-m-d H:i:s', time() - $cooldown ) ) ) ) {
            return false;
        }
        return (bool) $wpdb->insert( $table, array(
            'user_id' => $user_id,
            'kind' => $kind,
            'level' => in_array( $level, self::LEVELS, true ) ? $level : 'info',
            'title' => mb_substr( sanitize_text_field( (string) $title ), 0, 255 ),
            'body' => mb_substr( sanitize_textarea_field( (string) $text ), 0, 1000 ),
            'view' => sanitize_key( (string) $view ),
            'push' => $push ? 1 : 0,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ) );
    }

    /** То же всем администраторам: ключи сайта и счета сервисов чинят они. */
    public static function admins( $kind, $level, $title, $text, $view = '', $cooldown = DAY_IN_SECONDS ) {
        foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 20 ) ) as $user_id ) {
            self::add( $user_id, $kind, $level, $title, $text, $view, true, $cooldown );
        }
    }

    /** Суточный лимит генерации: предупреждаем, когда осталась пятая часть, и когда он кончился. */
    public static function limit( $user_id, $kind, $used, $limit ) {
        $used = (int) $used;
        $limit = (int) $limit;
        $what = 'text' === $kind ? 'текстов' : 'картинок и видео';
        if ( $limit > 0 && $used >= $limit ) {
            self::add( $user_id, 'limit_' . $kind . '_out', 'error', 'Лимит ' . $what . ' на сегодня исчерпан', 'Израсходовано ' . $limit . ' из ' . $limit . '. Генерация' . ( 'text' === $kind ? ' и автосбор новостей' : '' ) . ' остановлены до завтра. Поднять лимит может администратор.', '', true, 20 * HOUR_IN_SECONDS );
        } elseif ( $limit >= 5 && $limit - $used <= max( 1, (int) floor( $limit / 5 ) ) ) {
            self::add( $user_id, 'limit_' . $kind . '_low', 'warning', 'Лимит ' . $what . ' заканчивается: осталось ' . ( $limit - $used ) . ' из ' . $limit, 'Когда он кончится, генерация' . ( 'text' === $kind ? ' и автосбор новостей' : '' ) . ' остановятся до завтра.', '', true, 20 * HOUR_IN_SECONDS );
        }
    }

    /**
     * Отказ платного сервиса из журнала: кончились деньги или отвергнут ключ.
     * Вызывается из VKT_Store::log — так новый поставщик попадает сюда сам,
     * как только начинает писать свои ошибки в журнал.
     */
    public static function funds( $method, $http, $message ) {
        $http = (int) $http;
        $money = 402 === $http || preg_match( '/insufficient|balance|quota|credit|billing|payment|недостаточно|баланс/iu', (string) $message );
        if ( ! $money && 401 !== $http ) {
            return;
        }
        $bfl = str_starts_with( (string) $method, 'bfl.' );
        $service = $bfl ? 'BFL (FLUX)' : trim( (string) ( strstr( (string) $message, ':', true ) ?: $method ) );
        self::admins(
            'funds_' . substr( md5( $service ), 0, 10 ),
            'error',
            $service . ( $money ? ': похоже, закончились средства' : ': ключ отвергнут' ),
            'Ответ сервиса (HTTP ' . $http . '): ' . mb_substr( (string) $message, 0, 200 ) . ' Пока это не исправлено, генерация через него не работает.',
            $bfl ? 'flux' : 'settings',
            12 * HOUR_IN_SECONDS
        );
    }

    // ——— Колокольчик ———

    public static function inbox( $user_id = null ) {
        global $wpdb;
        $user_id = null === $user_id ? VKT_Account::id() : absint( $user_id );
        $table = VKT_Store::table( 'notifications' );
        $items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id,level,title,body,view,created_at,read_at IS NOT NULL AS seen FROM $table WHERE user_id=%d ORDER BY id DESC LIMIT %d", $user_id, self::INBOX ), ARRAY_A );
        return array(
            'unread' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE user_id=%d AND read_at IS NULL", $user_id ) ),
            'items' => $items,
        );
    }

    public static function read_all() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . VKT_Store::table( 'notifications' ) . ' SET read_at=%s WHERE user_id=%d AND read_at IS NULL', gmdate( 'Y-m-d H:i:s' ), VKT_Account::id() ) );
        return self::inbox();
    }

    public static function purge_user( $user_id ) {
        global $wpdb;
        $wpdb->delete( VKT_Store::table( 'notifications' ), array( 'user_id' => absint( $user_id ) ) );
    }

    // ——— Каналы: общее для сайта и личное ———

    /** Бот сайта: ключ сообщества VK и токен бота Telegram. Одни на всех, ставит администратор. */
    private static function site() {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        return array(
            'vk_token' => '' === (string) ( $saved['vk_token'] ?? '' ) ? '' : VKT_Tokens::unseal( $saved['vk_token'] ),
            'vk_group' => absint( $saved['vk_group'] ?? 0 ),
            'vk_name' => (string) ( $saved['vk_name'] ?? '' ),
            'tg_token' => '' === (string) ( $saved['tg_token'] ?? '' ) ? '' : VKT_Tokens::unseal( $saved['tg_token'] ),
            'tg_bot' => (string) ( $saved['tg_bot'] ?? '' ),
            // Ниже скольких кредитов BFL пора предупреждать; 0 — не следить.
            'bfl_min' => isset( $saved['bfl_min'] ) ? absint( $saved['bfl_min'] ) : 100,
        );
    }

    /** Куда писать человеку: включён ли VK, какой чат Telegram привязан. */
    private static function prefs( $user_id ) {
        $saved = get_user_meta( absint( $user_id ), self::META, true );
        $saved = is_array( $saved ) ? $saved : array();
        return array(
            'vk' => ! empty( $saved['vk'] ),
            // Вошедшему через VK ID адрес известен; администратору с паролем WordPress — вписывается вручную.
            'vk_id' => absint( $saved['vk_id'] ?? 0 ),
            'vk_error' => (string) ( $saved['vk_error'] ?? '' ),
            'tg' => (int) ( $saved['tg'] ?? 0 ),
            'tg_name' => (string) ( $saved['tg_name'] ?? '' ),
        );
    }

    private static function store_prefs( $user_id, $prefs ) {
        update_user_meta( absint( $user_id ), self::META, $prefs );
    }

    private static function vk_id( $user_id, $prefs ) {
        return absint( get_user_meta( absint( $user_id ), 'vkt_vk_id', true ) ) ?: $prefs['vk_id'];
    }

    public static function public_status() {
        $site = self::site();
        $user_id = VKT_Account::id();
        $prefs = self::prefs( $user_id );
        return array(
            'vk' => array(
                'ready' => '' !== $site['vk_token'],
                'group' => $site['vk_group'],
                'name' => $site['vk_name'],
                'on' => $prefs['vk'],
                'vk_id' => self::vk_id( $user_id, $prefs ),
                'manual' => ! absint( get_user_meta( $user_id, 'vkt_vk_id', true ) ),
                'error' => $prefs['vk_error'],
            ),
            'tg' => array( 'ready' => '' !== $site['tg_token'], 'bot' => $site['tg_bot'], 'linked' => 0 !== $prefs['tg'], 'name' => $prefs['tg_name'] ),
            'bfl_min' => VKT_Account::is_admin() ? $site['bfl_min'] : null,
        );
    }

    /** Личный выбор: присылать ли в VK и на какой ID, если вход был не через VK ID. */
    public static function save_prefs( $data ) {
        $user_id = VKT_Account::id();
        $prefs = self::prefs( $user_id );
        if ( isset( $data['vk'] ) ) {
            $prefs['vk'] = ! empty( $data['vk'] );
            $prefs['vk_error'] = '';
        }
        if ( isset( $data['vk_id'] ) ) {
            $prefs['vk_id'] = min( 4294967295, absint( $data['vk_id'] ) );
        }
        if ( $prefs['vk'] && ! self::vk_id( $user_id, $prefs ) ) {
            return self::error( 'Впишите свой ID ВКонтакте — число из адреса страницы vk.com/id…' );
        }
        self::store_prefs( $user_id, $prefs );
        return array( 'ok' => true, 'notify' => self::public_status() );
    }

    /** Ключи бота сайта. Пустое поле оставляет сохранённое, минус удаляет — как у остальных ключей. */
    public static function save_site( $data ) {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        $vk = is_string( $data['vk_token'] ?? null ) ? trim( $data['vk_token'] ) : '';
        if ( '-' === $vk ) {
            unset( $saved['vk_token'], $saved['vk_group'], $saved['vk_name'] );
        } elseif ( '' !== $vk ) {
            if ( ! preg_match( '/^[a-zA-Z0-9._\-]{20,2048}$/', $vk ) ) {
                return self::error( 'Вставьте ключ доступа сообщества целиком.' );
            }
            $rights = self::vk( 'groups.getTokenPermissions', array(), $vk );
            if ( is_wp_error( $rights ) ) {
                return self::error( 'VK не принял ключ сообщества: ' . $rights->get_error_message() );
            }
            if ( ! ( (int) ( $rights['mask'] ?? 0 ) & self::VK_MESSAGES ) ) {
                return self::error( 'У ключа нет права «Сообщения сообщества». Создайте ключ с этим правом: Управление → Работа с API → Ключи доступа.' );
            }
            $group = self::vk( 'groups.getById', array(), $vk );
            // До версии 5.139 VK отдавал список групп, позже — объект с полем groups.
            $group = is_wp_error( $group ) ? array() : (array) ( $group['groups'][0] ?? $group[0] ?? array() );
            if ( ! absint( $group['id'] ?? 0 ) ) {
                return self::error( 'VK не назвал сообщество этого ключа. Проверьте, что это ключ сообщества, а не пользователя.' );
            }
            $sealed = VKT_Tokens::seal( $vk );
            if ( is_wp_error( $sealed ) ) {
                return $sealed;
            }
            $saved = array_merge( $saved, array( 'vk_token' => $sealed, 'vk_group' => absint( $group['id'] ), 'vk_name' => sanitize_text_field( (string) ( $group['name'] ?? '' ) ) ) );
        }
        $tg = is_string( $data['tg_token'] ?? null ) ? trim( $data['tg_token'] ) : '';
        if ( '-' === $tg ) {
            unset( $saved['tg_token'], $saved['tg_bot'] );
        } elseif ( '' !== $tg ) {
            if ( ! preg_match( '/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $tg ) ) {
                return self::error( 'Токен бота Telegram выглядит как 123456:ABC… — его выдаёт @BotFather.' );
            }
            $bot = self::tg( 'getMe', array(), $tg );
            if ( is_wp_error( $bot ) ) {
                return self::error( 'Telegram не принял токен бота: ' . $bot->get_error_message() );
            }
            $sealed = VKT_Tokens::seal( $tg );
            if ( is_wp_error( $sealed ) ) {
                return $sealed;
            }
            $saved = array_merge( $saved, array( 'tg_token' => $sealed, 'tg_bot' => sanitize_text_field( (string) ( $bot['username'] ?? '' ) ) ) );
            // У нового бота своя лента событий: прежняя отметка, докуда она прочитана, к ней не относится.
            delete_option( 'vkt_tg_offset' );
        }
        if ( isset( $data['bfl_min'] ) ) {
            $saved['bfl_min'] = min( 100000, absint( $data['bfl_min'] ) );
        }
        update_option( self::OPTION, $saved, false );
        return array( 'ok' => true, 'notify' => self::public_status() );
    }

    // ——— Отправка ———

    private static function vk( $method, $params, $token ) {
        $response = wp_remote_post( 'https://api.vk.com/method/' . $method, array(
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 65536,
            'body' => array_merge( $params, array( 'access_token' => $token, 'v' => VKT_Plugin::settings()['api_version'] ) ),
        ) );
        $body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return self::error( 'нет связи с VK.', 502 );
        }
        if ( isset( $body['error'] ) ) {
            $code = absint( $body['error']['error_code'] ?? 0 );
            return new WP_Error( 'vkt_notify', 'код ' . $code . ': ' . mb_substr( str_replace( $token, '[hidden]', sanitize_text_field( (string) ( $body['error']['error_msg'] ?? '' ) ) ), 0, 160 ), array( 'status' => 422, 'vk_code' => $code ) );
        }
        return $body['response'] ?? array();
    }

    private static function tg( $method, $params, $token ) {
        $response = wp_remote_post( 'https://api.telegram.org/bot' . $token . '/' . $method, array(
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 262144,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => wp_json_encode( (object) $params ),
        ) );
        if ( is_wp_error( $response ) ) {
            // Сервер в России до api.telegram.org достаёт не всегда — причина должна читаться сразу.
            return self::error( 'сервер не достучался до api.telegram.org (' . mb_substr( str_replace( $token, '[hidden]', $response->get_error_message() ), 0, 120 ) . ').', 502 );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
            $code = absint( $body['error_code'] ?? wp_remote_retrieve_response_code( $response ) );
            return new WP_Error( 'vkt_notify', 'код ' . $code . ': ' . mb_substr( sanitize_text_field( (string) ( $body['description'] ?? 'ответ не от Telegram' ) ), 0, 160 ), array( 'status' => 422, 'tg_code' => $code ) );
        }
        return $body['result'] ?? array();
    }

    /**
     * Текст — во все каналы человека. Возвращает канал => true или ошибку;
     * пустой список — каналов нет. Отказы, в которых виноват адресат,
     * запоминаются у него: их видно в окне оповещений.
     */
    private static function send( $user_id, $text ) {
        $site = self::site();
        $prefs = self::prefs( $user_id );
        $out = array();
        $vk_id = self::vk_id( $user_id, $prefs );
        if ( '' !== $site['vk_token'] && $prefs['vk'] && $vk_id ) {
            $sent = self::vk( 'messages.send', array( 'user_id' => $vk_id, 'random_id' => random_int( 1, 2147483647 ), 'message' => mb_substr( $text, 0, 4000 ), 'dont_parse_links' => 1 ), $site['vk_token'] );
            $refused = is_wp_error( $sent ) && in_array( (int) ( $sent->get_error_data()['vk_code'] ?? 0 ), self::VK_REFUSED, true );
            $note = is_wp_error( $sent ) ? ( $refused ? 'VK не даёт вам написать: откройте диалог с сообществом и отправьте ему любое сообщение — так вы разрешите ответы.' : 'VK отклонил сообщение, ' . $sent->get_error_message() ) : '';
            if ( $note !== $prefs['vk_error'] ) {
                $prefs['vk_error'] = $note;
                self::store_prefs( $user_id, $prefs );
            }
            $out['vk'] = is_wp_error( $sent ) ? self::error( $note ) : true;
        }
        if ( '' !== $site['tg_token'] && $prefs['tg'] ) {
            $sent = self::tg( 'sendMessage', array( 'chat_id' => $prefs['tg'], 'text' => mb_substr( $text, 0, 4000 ), 'disable_web_page_preview' => true ), $site['tg_token'] );
            if ( is_wp_error( $sent ) && 403 === (int) ( $sent->get_error_data()['tg_code'] ?? 0 ) ) {
                // Человек остановил бота: чат отвязываем, чтобы не стучаться в закрытую дверь.
                $prefs['tg'] = 0;
                $prefs['tg_name'] = '';
                self::store_prefs( $user_id, $prefs );
                $sent = self::error( 'бот остановлен в Telegram — подключите его заново.' );
            }
            $out['tg'] = $sent instanceof WP_Error ? $sent : true;
        }
        return $out;
    }

    /** Кнопка «Проверить»: сообщение уходит сразу, и отказ канала виден здесь же. */
    public static function test() {
        $sent = self::send( VKT_Account::id(), "VK Trends · проверка\nОповещения подключены: сюда придут сообщения о лимитах, ключах и автосборе новостей." );
        if ( ! $sent ) {
            return self::error( 'Ни один канал не подключён: включите сообщения VK или привяжите Telegram.' );
        }
        $labels = array( 'vk' => 'VK', 'tg' => 'Telegram' );
        $results = array();
        foreach ( $sent as $channel => $result ) {
            $results[] = array( 'channel' => $labels[ $channel ], 'ok' => true === $result, 'message' => true === $result ? 'сообщение отправлено' : $result->get_error_message() );
        }
        return array( 'results' => $results, 'notify' => self::public_status() );
    }

    /** Очередь сообщений: то, что ждёт отправки, уходит пачкой; три неудачи — и запись остаётся только в колокольчике. */
    private static function flush( $limit = 15 ) {
        global $wpdb;
        $table = VKT_Store::table( 'notifications' );
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id,user_id,title,body,view,attempts FROM $table WHERE push=1 ORDER BY id LIMIT %d", $limit ), ARRAY_A );
        foreach ( $rows as $row ) {
            $link = '' !== $row['view'] ? "\n" . VKT_Plugin::dashboard_url() . '#' . $row['view'] : '';
            $sent = self::send( (int) $row['user_id'], 'VK Trends · ' . $row['title'] . "\n" . $row['body'] . $link );
            $delivered = in_array( true, $sent, true );
            $attempts = (int) $row['attempts'] + 1;
            $wpdb->update( $table, array(
                // Каналов нет вовсе — ждать нечего; иначе повторяем, пока не кончатся попытки.
                'push' => $delivered || ! $sent || $attempts >= self::MAX_ATTEMPTS ? 0 : 1,
                'attempts' => $attempts,
                'sent_at' => $delivered ? gmdate( 'Y-m-d H:i:s' ) : null,
            ), array( 'id' => (int) $row['id'] ) );
        }
    }

    // ——— Telegram: привязка чата ———

    /**
     * Ссылка на бота с одноразовым кодом. Нажав «Запустить», человек шлёт
     * боту «/start код» — по коду плагин узнаёт, чей это чат. Код живёт час.
     */
    public static function tg_link() {
        $site = self::site();
        if ( '' === $site['tg_token'] || '' === $site['tg_bot'] ) {
            return self::error( 'Бот Telegram ещё не подключён администратором.' );
        }
        $code = strtolower( wp_generate_password( 16, false ) );
        set_transient( 'vkt_tg_code_' . $code, VKT_Account::id(), HOUR_IN_SECONDS );
        // Пока есть неиспользованные коды, cron сам читает сообщения боту: привязка случится и без кнопки «Проверить».
        update_option( 'vkt_tg_pending', time() + HOUR_IN_SECONDS, false );
        return array( 'url' => 'https://t.me/' . rawurlencode( $site['tg_bot'] ) . '?start=' . $code );
    }

    /** Читает сообщения боту и привязывает чаты по кодам. Возвращает, сколько привязано. */
    public static function tg_poll() {
        $site = self::site();
        if ( '' === $site['tg_token'] || ! VKT_Store::lock( 'tg_poll', 30 ) ) {
            return 0;
        }
        try {
            $updates = self::tg( 'getUpdates', array( 'offset' => (int) get_option( 'vkt_tg_offset', 0 ), 'timeout' => 0, 'allowed_updates' => array( 'message' ) ), $site['tg_token'] );
            if ( is_wp_error( $updates ) ) {
                return 409 === (int) ( $updates->get_error_data()['tg_code'] ?? 0 )
                    ? self::error( 'У бота включён webhook — сообщения ему забирает другой сервис. Отключите webhook у @BotFather или заведите отдельного бота.' )
                    : $updates;
            }
            $linked = 0;
            $offset = 0;
            foreach ( (array) $updates as $update ) {
                $offset = (int) ( $update['update_id'] ?? 0 ) + 1;
                $message = (array) ( $update['message'] ?? array() );
                $chat = (int) ( $message['chat']['id'] ?? 0 );
                if ( ! $chat || 'private' !== ( $message['chat']['type'] ?? '' ) ) {
                    continue;
                }
                $user_id = preg_match( '~^/start\s+([a-z0-9]{16})$~', trim( (string) ( $message['text'] ?? '' ) ), $code ) ? absint( get_transient( 'vkt_tg_code_' . $code[1] ) ) : 0;
                if ( ! $user_id ) {
                    self::tg( 'sendMessage', array( 'chat_id' => $chat, 'text' => 'Этот бот присылает оповещения VK Trends. Откройте его кнопкой «Подключить Telegram» в колокольчике кабинета — так он узнает, чей это чат.' ), $site['tg_token'] );
                    continue;
                }
                delete_transient( 'vkt_tg_code_' . $code[1] );
                $prefs = self::prefs( $user_id );
                $prefs['tg'] = $chat;
                $prefs['tg_name'] = mb_substr( sanitize_text_field( (string) ( $message['from']['username'] ?? $message['from']['first_name'] ?? '' ) ), 0, 60 );
                self::store_prefs( $user_id, $prefs );
                self::tg( 'sendMessage', array( 'chat_id' => $chat, 'text' => 'Готово: оповещения VK Trends будут приходить сюда.' ), $site['tg_token'] );
                ++$linked;
            }
            if ( $offset ) {
                // Отметка «прочитано до»: без неё Telegram отдавал бы те же сообщения снова.
                update_option( 'vkt_tg_offset', $offset, false );
            }
            return $linked;
        } finally {
            VKT_Store::unlock( 'tg_poll' );
        }
    }

    /** Кнопка «Я запустил бота»: читаем сообщения сейчас, не дожидаясь cron. */
    public static function tg_check() {
        $polled = self::tg_poll();
        if ( is_wp_error( $polled ) ) {
            return $polled;
        }
        $status = self::public_status();
        return $status['tg']['linked'] ? array( 'ok' => true, 'notify' => $status ) : self::error( 'Бот пока не получил вашу команду. Откройте его по ссылке, нажмите «Запустить» и повторите проверку.', 404 );
    }

    public static function tg_unlink() {
        $user_id = VKT_Account::id();
        $prefs = self::prefs( $user_id );
        $prefs['tg'] = 0;
        $prefs['tg_name'] = '';
        self::store_prefs( $user_id, $prefs );
        return array( 'ok' => true, 'notify' => self::public_status() );
    }

    // ——— Фоновые проверки ———

    public static function cron() {
        if ( time() < (int) get_option( 'vkt_tg_pending', 0 ) ) {
            self::tg_poll();
        }
        self::sweep_health();
        self::sweep_funds();
        self::flush();
    }

    /**
     * Неполадки каждого кабинета — те же, что в баннере дашборда, — в
     * оповещения. Ошибка, которая держится, напоминается раз в сутки;
     * предупреждение приходит один раз, пока не исчезнет.
     */
    private static function sweep_health() {
        global $wpdb;
        if ( time() - (int) get_option( 'vkt_notify_health_at', 0 ) < self::HEALTH_EVERY ) {
            return;
        }
        update_option( 'vkt_notify_health_at', time(), false );
        $members = get_users( array( 'role__in' => array( VKT_Account::ROLE ), 'meta_key' => 'vkt_status', 'meta_value' => 'active', 'fields' => 'ID', 'number' => 500 ) );
        $admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 20 ) );
        foreach ( array_unique( array_map( 'absint', array_merge( $admins, $members ) ) ) as $user_id ) {
            self::health( $user_id, VKT_Account::act_as( $user_id, array( 'VKT_Health', 'issues' ) ) );
        }
        // Колокольчик показывает последние записи; старше месяца они только занимают место.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . VKT_Store::table( 'notifications' ) . ' WHERE created_at<%s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
    }

    public static function health( $user_id, $issues ) {
        $seen = get_user_meta( $user_id, 'vkt_notify_health', true );
        $seen = is_array( $seen ) ? $seen : array();
        $now = time();
        $next = array();
        foreach ( (array) $issues as $issue ) {
            // Числа в заголовке — счётчики и время: неполадка та же, сколько бы записей в ней ни было.
            $key = substr( md5( (string) preg_replace( '/\d+/', '#', (string) $issue['title'] ) ), 0, 12 );
            $sent = (int) ( $seen[ $key ] ?? 0 );
            if ( ! $sent || ( 'error' === $issue['level'] && $now - $sent >= DAY_IN_SECONDS ) ) {
                self::add( $user_id, 'health_' . $key, $issue['level'], $issue['title'], $issue['text'], $issue['view'], true, 0 );
                $sent = $now;
            }
            $next[ $key ] = $sent;
        }
        foreach ( $seen as $key => $sent ) {
            if ( ! isset( $next[ $key ] ) && $now - (int) $sent < self::HEALTH_QUIET ) {
                $next[ $key ] = (int) $sent;
            }
        }
        if ( $next !== $seen ) {
            update_user_meta( $user_id, 'vkt_notify_health', $next );
        }
    }

    /** Баланс BFL: предупреждаем заранее, а не когда генерация уже встала. */
    private static function sweep_funds() {
        if ( time() - (int) get_option( 'vkt_notify_funds_at', 0 ) < self::FUNDS_EVERY ) {
            return;
        }
        update_option( 'vkt_notify_funds_at', time(), false );
        $min = self::site()['bfl_min'];
        // Ключ BFL из wp-config.php виден только хозяину сайта — спрашиваем баланс от его имени.
        $credits = $min ? VKT_Account::act_as( VKT_Account::owner(), static fn() => VKT_Flux::configured() ? VKT_Flux::credits() : null ) : null;
        if ( is_array( $credits ) && $credits['credits'] < $min ) {
            self::admins( 'funds_bfl_low', 'warning', 'Кредиты BFL заканчиваются: осталось ' . $credits['credits'], 'Порог оповещения — ' . $min . '. Пополните счёт в кабинете BFL, иначе «Генерация фото» остановится.', 'flux' );
        }
    }
}

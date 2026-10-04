<?php
defined( 'ABSPATH' ) || exit;

/**
 * Личные кабинеты: роль участника, статус заявки и владелец данных.
 *
 * Данные разведены по пользователям WordPress. Запрос из браузера работает от
 * имени вошедшего, а фоновые задачи — очередь публикаций и сборщик —
 * переключаются на владельца конкретной записи через act_as(): иначе cron,
 * у которого нет пользователя, не нашёл бы ни одного личного ключа.
 */
final class VKT_Account {
    const ROLE = 'vkt_member';
    const CAP = 'vkt_access';
    const STATUSES = array( 'pending', 'active', 'blocked' );
    // Администратору лимиты не нужны: ключ xAI и хостинг — его собственные.
    const ADMIN_SOURCES = 500;

    /** Пользователь, от имени которого идёт работа. null — вошедший. */
    private static $acting = null;
    private static $owner = null;

    public static function id() {
        return null === self::$acting ? (int) get_current_user_id() : (int) self::$acting;
    }

    /** Выполняет работу от имени пользователя и возвращает прежний контекст даже при исключении. */
    public static function act_as( $user_id, callable $callback ) {
        $previous = self::$acting;
        self::$acting = absint( $user_id );
        try {
            return $callback();
        } finally {
            self::$acting = $previous;
        }
    }

    public static function ensure_role() {
        if ( ! get_role( self::ROLE ) ) {
            add_role( self::ROLE, 'Пользователь VK Trends', array( 'read' => true, 'upload_files' => true, self::CAP => true ) );
        }
    }

    private static function resolve( $user_id ) {
        return null === $user_id ? self::id() : absint( $user_id );
    }

    public static function is_admin( $user_id = null ) {
        $user_id = self::resolve( $user_id );
        return $user_id > 0 && user_can( $user_id, 'manage_options' );
    }

    /**
     * Хозяин сайта: ему принадлежат данные, накопленные до личных кабинетов, и
     * ключи из wp-config.php. Константы нельзя раздавать всем — иначе чужая
     * запись ушла бы ключом сообщества хозяина.
     */
    public static function owner() {
        if ( null !== self::$owner ) {
            return self::$owner;
        }
        $owner = absint( get_option( 'vkt_owner', 0 ) );
        if ( ! $owner || ! user_can( $owner, 'manage_options' ) ) {
            $admins = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID' ) );
            $owner = absint( $admins[0] ?? 0 );
            if ( $owner ) {
                update_option( 'vkt_owner', $owner, false );
            }
        }
        self::$owner = $owner;
        return $owner;
    }

    public static function is_owner( $user_id = null ) {
        $user_id = self::resolve( $user_id );
        return $user_id > 0 && $user_id === self::owner();
    }

    /** guest — не вошёл, denied — вошёл без роли VK Trends, остальное — статус заявки. */
    public static function status( $user_id = null ) {
        $user_id = self::resolve( $user_id );
        if ( ! $user_id ) {
            return 'guest';
        }
        if ( self::is_admin( $user_id ) ) {
            return 'active';
        }
        if ( ! user_can( $user_id, self::CAP ) ) {
            return 'denied';
        }
        $status = (string) get_user_meta( $user_id, 'vkt_status', true );
        return in_array( $status, self::STATUSES, true ) ? $status : 'pending';
    }

    public static function can_use( $user_id = null ) {
        return 'active' === self::status( $user_id );
    }

    /** Личная настройка пользователя: ID сообщества, ручная проверка публикаций. */
    public static function get( $key, $user_id = null ) {
        $user_id = self::resolve( $user_id );
        return $user_id ? get_user_meta( $user_id, 'vkt_' . $key, true ) : '';
    }

    public static function set( $key, $value, $user_id = null ) {
        $user_id = self::resolve( $user_id );
        return $user_id ? (bool) update_user_meta( $user_id, 'vkt_' . $key, $value ) : false;
    }

    public static function profile( $user_id = null ) {
        $user_id = self::resolve( $user_id );
        $user = $user_id ? get_userdata( $user_id ) : false;
        if ( ! $user ) {
            return array( 'id' => 0, 'name' => '', 'avatar' => '', 'vk_id' => 0, 'is_admin' => false, 'status' => 'guest' );
        }
        return array(
            'id' => (int) $user->ID,
            'name' => (string) $user->display_name,
            'avatar' => esc_url_raw( (string) get_user_meta( $user->ID, 'vkt_avatar', true ), array( 'https' ) ),
            'vk_id' => absint( get_user_meta( $user->ID, 'vkt_vk_id', true ) ),
            'is_admin' => self::is_admin( $user->ID ),
            'status' => self::status( $user->ID ),
        );
    }

    public static function source_limit( $user_id = null ) {
        return self::is_admin( $user_id ) ? self::ADMIN_SOURCES : self::limit( 'sources', $user_id );
    }

    // ——— Лимиты кабинета: общие для сайта и личные, которые ставит администратор ———

    // Ключ лимита => настройка сайта с общим значением, значение по умолчанию и потолок.
    const LIMITS = array(
        'text' => array( 'ai_text_daily', 30, 5000 ),
        'media' => array( 'ai_media_daily', 5, 1000 ),
        'sources' => array( 'member_sources', 100, 500 ),
        // Сколько ответов на комментарии может одновременно ждать в очереди кабинета.
        'replies_queue' => array( 'member_replies_queue', 300, 2000 ),
    );

    /** Личные лимиты кабинета: только те, что администратор задал этому человеку. */
    public static function own_limits( $user_id ) {
        $saved = get_user_meta( absint( $user_id ), 'vkt_limits', true );
        $own = array();
        foreach ( self::LIMITS as $key => $rule ) {
            if ( is_array( $saved ) && isset( $saved[ $key ] ) && is_numeric( $saved[ $key ] ) ) {
                $own[ $key ] = min( $rule[2], absint( $saved[ $key ] ) );
            }
        }
        return $own;
    }

    /** Действующий лимит: личный, если задан, иначе общий для сайта. */
    public static function limit( $key, $user_id = null ) {
        $rule = self::LIMITS[ $key ] ?? null;
        if ( ! $rule ) {
            return 0;
        }
        $user_id = self::resolve( $user_id );
        if ( $user_id && self::is_admin( $user_id ) ) {
            return 'sources' === $key ? self::ADMIN_SOURCES : $rule[2];
        }
        $own = $user_id ? self::own_limits( $user_id ) : array();
        if ( isset( $own[ $key ] ) ) {
            return 'sources' === $key ? max( 1, $own[ $key ] ) : $own[ $key ];
        }
        $settings = VKT_Plugin::settings();
        $common = '' !== $rule[0] && isset( $settings[ $rule[0] ] ) ? absint( $settings[ $rule[0] ] ) : $rule[1];
        return 'sources' === $key ? max( 1, $common ) : $common;
    }

    /** Личные лимиты ставит администратор. Пустое поле возвращает кабинет к общему значению. */
    public static function set_limits( $user_id, $limits ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || self::is_admin( $user_id ) || ! user_can( $user_id, self::CAP ) ) {
            return new WP_Error( 'vkt_account', 'Это не пользователь VK Trends.', array( 'status' => 404 ) );
        }
        $own = array();
        foreach ( self::LIMITS as $key => $rule ) {
            $value = is_array( $limits ) ? ( $limits[ $key ] ?? '' ) : '';
            if ( '' === $value || null === $value ) {
                continue;
            }
            if ( ! is_numeric( $value ) || (int) $value < 0 || (int) $value > $rule[2] ) {
                return new WP_Error( 'vkt_account', 'Лимит — целое число от 0 до ' . $rule[2] . '.', array( 'status' => 400 ) );
            }
            $own[ $key ] = (int) $value;
        }
        if ( $own ) {
            update_user_meta( $user_id, 'vkt_limits', $own );
        } else {
            delete_user_meta( $user_id, 'vkt_limits' );
        }
        return array( 'ok' => true, 'limits' => self::limits_view( $user_id ) );
    }

    /** Лимиты кабинета для «Пользователей»: личное значение, действующее и расход за сегодня. */
    public static function limits_view( $user_id ) {
        $own = self::own_limits( $user_id );
        $usage = self::ai_usage( $user_id );
        $view = array();
        foreach ( array_keys( self::LIMITS ) as $key ) {
            $view[ $key ] = array( 'own' => $own[ $key ] ?? null, 'limit' => self::limit( $key, $user_id ), 'used' => isset( $usage[ $key ] ) ? (int) $usage[ $key ] : null );
        }
        return $view;
    }

    // ——— Суточный лимит генерации xAI ———

    const AI_LIMITS = array( 'text' => 'ai_text_daily', 'media' => 'ai_media_daily' );

    /** Расход за сегодня. Сутки считаются по часовому поясу сайта. */
    private static function ai_usage( $user_id ) {
        $usage = get_user_meta( $user_id, 'vkt_ai_usage', true );
        $today = wp_date( 'Y-m-d' );
        if ( ! is_array( $usage ) || ( $usage['day'] ?? '' ) !== $today ) {
            $usage = array( 'day' => $today, 'text' => 0, 'media' => 0 );
        }
        return $usage;
    }

    /** Остаток на сегодня; null — без ограничений. */
    public static function ai_quota( $user_id = null ) {
        $user_id = self::resolve( $user_id );
        if ( ! $user_id || self::is_admin( $user_id ) ) {
            return null;
        }
        $usage = self::ai_usage( $user_id );
        $quota = array();
        foreach ( self::AI_LIMITS as $kind => $option ) {
            $limit = self::limit( $kind, $user_id );
            $quota[ $kind ] = array( 'limit' => $limit, 'used' => (int) $usage[ $kind ], 'left' => max( 0, $limit - (int) $usage[ $kind ] ) );
        }
        return $quota;
    }

    public static function ai_allow( $kind ) {
        $quota = self::ai_quota();
        if ( null === $quota || ( $quota[ $kind ]['left'] ?? 0 ) > 0 ) {
            return true;
        }
        $what = 'text' === $kind ? 'текстов' : 'картинок и видео';
        return new WP_Error( 'vkt_ai_quota', 'Лимит генерации ' . $what . ' на сегодня исчерпан: ' . (int) $quota[ $kind ]['limit'] . ' в сутки. Он обновится завтра; поднять его может администратор в разделе «Пользователи».', array( 'status' => 429 ) );
    }

    /** Списывается только удачная генерация: ошибка xAI не должна съедать лимит. */
    public static function ai_spend( $kind ) {
        $user_id = self::id();
        if ( ! $user_id || self::is_admin( $user_id ) || ! isset( self::AI_LIMITS[ $kind ] ) ) {
            return;
        }
        $usage = self::ai_usage( $user_id );
        ++$usage[ $kind ];
        update_user_meta( $user_id, 'vkt_ai_usage', $usage );
    }

    // ——— Раздел «Пользователи» ———

    public static function members() {
        global $wpdb;
        $users = get_users( array( 'role__in' => array( self::ROLE ), 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500 ) );
        if ( ! $users ) {
            return array();
        }
        $ids = implode( ',', array_map( static fn( $user ) => (int) $user->ID, $users ) );
        $count = static function ( $table ) use ( $wpdb, $ids ) {
            $rows = (array) $wpdb->get_results( 'SELECT user_id,COUNT(*) AS amount FROM ' . VKT_Store::table( $table ) . " WHERE user_id IN ($ids) GROUP BY user_id", OBJECT_K );
            return array_map( static fn( $row ) => (int) $row->amount, $rows );
        };
        $sources = $count( 'subscriptions' );
        $groups = $count( 'publishing_groups' );
        $posts = $count( 'outbound_posts' );
        $out = array();
        foreach ( $users as $user ) {
            $id = (int) $user->ID;
            $out[] = array_merge( self::profile( $id ), array(
                'registered' => (string) $user->user_registered,
                'last_login' => (string) get_user_meta( $id, 'vkt_last_login', true ),
                'sources' => $sources[ $id ] ?? 0,
                'groups' => $groups[ $id ] ?? 0,
                'posts' => $posts[ $id ] ?? 0,
                'limits' => self::limits_view( $id ),
            ) );
        }
        return $out;
    }

    public static function pending_count() {
        return count( get_users( array( 'role__in' => array( self::ROLE ), 'meta_key' => 'vkt_status', 'meta_value' => 'pending', 'fields' => 'ID', 'number' => 1000 ) ) );
    }

    public static function set_status( $user_id, $status ) {
        $user_id = absint( $user_id );
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            return new WP_Error( 'vkt_account', 'Неизвестный статус.', array( 'status' => 400 ) );
        }
        if ( ! $user_id || self::is_admin( $user_id ) || ! user_can( $user_id, self::CAP ) ) {
            return new WP_Error( 'vkt_account', 'Это не пользователь VK Trends.', array( 'status' => 404 ) );
        }
        update_user_meta( $user_id, 'vkt_status', $status );
        // Заблокированного выкидываем из всех открытых сессий сразу, а не при следующем входе.
        if ( 'blocked' === $status && class_exists( 'WP_Session_Tokens' ) ) {
            WP_Session_Tokens::get_instance( $user_id )->destroy_all();
        }
        return array( 'ok' => true, 'status' => $status );
    }

    /** Удаление пользователя WordPress уносит его кабинет: подписки, группы, очередь и товары. */
    public static function purge( $user_id ) {
        global $wpdb;
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return;
        }
        VKT_Subscriptions::purge_user( $user_id );
        $products = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'products' ) . ' WHERE user_id=%d', $user_id ) ) );
        foreach ( array_chunk( $products, 500 ) as $chunk ) {
            $wpdb->query( 'DELETE FROM ' . VKT_Store::table( 'links' ) . ' WHERE product_id IN (' . implode( ',', $chunk ) . ')' );
        }
        $wpdb->delete( VKT_Store::table( 'products' ), array( 'user_id' => $user_id ) );
        $posts = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'outbound_posts' ) . ' WHERE user_id=%d', $user_id ) ) );
        foreach ( array_chunk( $posts, 500 ) as $chunk ) {
            $wpdb->query( 'DELETE FROM ' . VKT_Store::table( 'outbound_deliveries' ) . ' WHERE outbound_post_id IN (' . implode( ',', $chunk ) . ')' );
        }
        $wpdb->delete( VKT_Store::table( 'outbound_posts' ), array( 'user_id' => $user_id ) );
        $wpdb->delete( VKT_Store::table( 'publishing_groups' ), array( 'user_id' => $user_id ) );
        VKT_Replies::purge_user( $user_id );
    }
}

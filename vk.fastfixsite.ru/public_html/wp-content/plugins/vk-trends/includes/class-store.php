<?php
defined( 'ABSPATH' ) || exit;

final class VKT_Store {
    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'vkt_' . $name;
    }

    public static function install() {
        global $wpdb;
        $installed_version = (string) get_option( 'vkt_db_version', '' );
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $schemas = array(
            'videos' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                owner_id bigint NOT NULL,
                video_id bigint unsigned NOT NULL,
                title text NOT NULL,
                thumbnail text NOT NULL,
                published_at datetime DEFAULT NULL,
                duration int unsigned NOT NULL DEFAULT 0,
                kind varchar(20) NOT NULL DEFAULT '',
                source_id bigint unsigned DEFAULT NULL,
                views bigint unsigned DEFAULT NULL,
                likes bigint unsigned DEFAULT NULL,
                comments bigint unsigned DEFAULT NULL,
                reposts bigint unsigned DEFAULT NULL,
                growth bigint DEFAULT NULL,
                velocity double DEFAULT NULL,
                measured_at datetime NOT NULL,
                next_run datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY vk_video (owner_id,video_id),
                KEY due (next_run),
                KEY origin (source_id),
                KEY velocity (velocity)",
            'snapshots' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                video_id bigint unsigned NOT NULL,
                views bigint unsigned DEFAULT NULL,
                likes bigint unsigned DEFAULT NULL,
                comments bigint unsigned DEFAULT NULL,
                reposts bigint unsigned DEFAULT NULL,
                measured_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY video_time (video_id,measured_at)",
            'products' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL DEFAULT 0,
                title varchar(255) NOT NULL,
                url text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY owner (user_id)",
            'links' => "video_id bigint unsigned NOT NULL,
                product_id bigint unsigned NOT NULL,
                PRIMARY KEY  (video_id,product_id),
                KEY product (product_id)",
            'sources' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                kind varchar(20) NOT NULL,
                value varchar(255) NOT NULL,
                title varchar(255) NOT NULL DEFAULT '',
                photo text NOT NULL,
                members bigint unsigned DEFAULT NULL,
                enabled tinyint NOT NULL DEFAULT 1,
                next_run datetime NOT NULL,
                synced_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source (kind,value),
                KEY due (enabled,next_run)",
            'jobs' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                job_key varchar(80) NOT NULL,
                kind varchar(20) NOT NULL,
                entity_id bigint unsigned NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                attempts int unsigned NOT NULL DEFAULT 0,
                available_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                message varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                UNIQUE KEY job_key (job_key),
                KEY ready (status,available_at)",
            'logs' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                method varchar(80) NOT NULL,
                context varchar(20) NOT NULL,
                status varchar(20) NOT NULL,
                code int NOT NULL DEFAULT 0,
                message varchar(255) NOT NULL,
                duration_ms int unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY created (created_at)",
        );
        // Таблицы постов описаны в VKT_Posts: dbDelta обновит их вместе с остальными.
        $schemas = array_merge( $schemas, VKT_Posts::schema(), VKT_Links::schema(), VKT_Publisher::schema(), VKT_Subscriptions::schema() );
        foreach ( $schemas as $name => $columns ) {
            dbDelta( 'CREATE TABLE ' . self::table( $name ) . " ($columns) ENGINE=InnoDB $collate;" );
        }
        // Посты, собранные прошлой версией, знают адрес ссылки, но не запись в справочнике.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '" . self::table( 'posts' ) . "'" ) ) {
            VKT_Links::backfill();
        }
        // В 0.10.0 задания сразу попадали в очередь. При первом переходе на
        // ручной шлюз возвращаем только ещё не отправленные задания в черновики.
        if ( '' !== $installed_version && version_compare( $installed_version, '0.10.1', '<' ) ) {
            $outbound_posts = self::table( 'outbound_posts' );
            $deliveries = self::table( 'outbound_deliveries' );
            $now = gmdate( 'Y-m-d H:i:s' );
            $wpdb->query( $wpdb->prepare(
                "UPDATE $deliveries d INNER JOIN $outbound_posts p ON p.id=d.outbound_post_id
                 SET d.status='waiting_approval',d.updated_at=%s
                 WHERE d.status='pending' AND p.status IN ('scheduled','queued')",
                $now
            ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE $outbound_posts p SET p.status='draft',p.editor_status='awaiting',p.updated_at=%s
                 WHERE p.status IN ('scheduled','queued')
                 AND EXISTS (SELECT 1 FROM $deliveries d WHERE d.outbound_post_id=p.id AND d.status='waiting_approval')",
                $now
            ) );
        }
        // С 0.11.2 публикация снова выполняется сразу по нажатию. На первом
        // обновлении выключаем прежний обязательный шлюз и выпускаем только
        // его ещё не отправленные черновики в очередь. Позже режим проверки
        // можно снова включить явно в настройках.
        if ( '' !== $installed_version && version_compare( $installed_version, '0.11.2', '<' ) ) {
            $outbound_posts = self::table( 'outbound_posts' );
            $deliveries = self::table( 'outbound_deliveries' );
            $now = gmdate( 'Y-m-d H:i:s' );
            $settings = VKT_Plugin::settings();
            $settings['publishing_review'] = false;
            update_option( 'vkt_settings', $settings, false );
            $wpdb->query( $wpdb->prepare(
                "UPDATE $deliveries d INNER JOIN $outbound_posts p ON p.id=d.outbound_post_id
                 SET d.status='pending',d.updated_at=%s
                 WHERE d.status='waiting_approval' AND p.status='draft'",
                $now
            ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE $outbound_posts p SET
                    status=IF(scheduled_at>DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 SECOND),'scheduled','queued'),
                    editor_status='approved',updated_at=%s
                 WHERE p.status='draft'
                 AND EXISTS (SELECT 1 FROM $deliveries d WHERE d.outbound_post_id=p.id AND d.status='pending')",
                $now
            ) );
        }
        VKT_Account::ensure_role();
        // 0.22.0: личные кабинеты. Всё накопленное раньше принадлежало одному
        // хозяину — переносим это в его кабинет. Шаги повторяемы: WHERE
        // user_id=0 и INSERT IGNORE не тронут то, что уже перенесено.
        if ( '' === $installed_version || version_compare( $installed_version, '0.22.0', '<' ) ) {
            self::migrate_accounts();
        }
        update_option( 'vkt_db_version', VKT_VERSION, false );
    }

    private static function migrate_accounts() {
        global $wpdb;
        $groups = self::table( 'publishing_groups' );
        // Прежний уникальный ключ не давал двум кабинетам вести одну и ту же
        // группу VK. dbDelta старые индексы не удаляет — снимаем сами.
        if ( $wpdb->get_var( "SHOW INDEX FROM $groups WHERE Key_name='vk_group'" ) ) {
            $wpdb->query( "ALTER TABLE $groups DROP INDEX vk_group" );
        }
        $owner = VKT_Account::owner();
        if ( ! $owner ) {
            return;
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        foreach ( array( 'publishing_groups', 'outbound_posts', 'products' ) as $name ) {
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( $name ) . ' SET user_id=%d WHERE user_id=0', $owner ) );
        }
        $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table( 'subscriptions' ) . ' (user_id,source_id,enabled,created_at) SELECT %d,id,enabled,%s FROM ' . self::table( 'sources' ), $owner, $now ) );
        $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table( 'user_videos' ) . ' (user_id,video_id,created_at) SELECT %d,id,%s FROM ' . self::table( 'videos' ), $owner, $now ) );
        VKT_Tokens::migrate_to_owner( $owner );
        // ID своего сообщества и ручная проверка стали личными настройками.
        $settings = (array) get_option( 'vkt_settings', array() );
        if ( ! empty( $settings['community_id'] ) && ! get_user_meta( $owner, 'vkt_community_id', true ) ) {
            update_user_meta( $owner, 'vkt_community_id', absint( $settings['community_id'] ) );
        }
        if ( ! empty( $settings['publishing_review'] ) && '' === get_user_meta( $owner, 'vkt_publishing_review', true ) ) {
            update_user_meta( $owner, 'vkt_publishing_review', 1 );
        }
    }

    // Atomic, expiring locks work across PHP workers and persistent object caches.
    public static function lock( $name, $ttl ) {
        global $wpdb;
        $key = 'vkt_lock_' . $name;
        $now = time();
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND CAST(option_value AS UNSIGNED)<%d", $key, $now ) );
        wp_cache_delete( $key, 'options' );
        return add_option( $key, $now + $ttl, '', false );
    }

    public static function unlock( $name ) {
        delete_option( 'vkt_lock_' . $name );
    }

    public static function log( $method, $context, $status, $code, $message, $ms = 0 ) {
        global $wpdb;
        // No parameters, request headers, tokens or raw VK errors are persisted.
        $wpdb->insert( self::table( 'logs' ), array(
            'method' => substr( $method, 0, 80 ), 'context' => $context,
            'status' => $status, 'code' => (int) $code,
            'message' => mb_substr( $message, 0, 255 ),
            'duration_ms' => max( 0, (int) $ms ), 'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ) );
    }

    public static function parse_video( $input ) {
        $input = trim( (string) $input );
        if ( preg_match( '~^https?://~i', $input ) ) {
            $url = wp_parse_url( $input );
            if ( ! in_array( strtolower( $url['host'] ?? '' ), array( 'vk.com', 'www.vk.com', 'm.vk.com', 'vk.ru', 'www.vk.ru', 'm.vk.ru', 'vkvideo.ru', 'www.vkvideo.ru', 'm.vkvideo.ru' ), true ) ) {
                return new WP_Error( 'video_url', 'Нужна ссылка на ролик VK или ID owner_id_video_id.', array( 'status' => 400 ) );
            }
            $input = ltrim( $url['path'] ?? '', '/' );
            parse_str( $url['query'] ?? '', $query );
            if ( isset( $query['z'] ) && is_string( $query['z'] ) ) {
                $input = explode( '/', $query['z'] )[0];
            }
        }
        if ( ! preg_match( '~^(?:video|clip)?(-?[1-9]\d{0,18})_([1-9]\d{0,18})/?$~', $input, $match ) ) {
            return new WP_Error( 'video_id', 'Укажите прямую ссылку video/clip или ID вида -123_456. Закрытые ссылки с access_key пока не поддерживаются.', array( 'status' => 400 ) );
        }
        return $match[1] . '_' . $match[2];
    }

    // wall.get отдаёт ролики вложениями поста: разбираем сам пост и репост из copy_history.
    public static function videos_from_posts( $items ) {
        $videos = array();
        foreach ( (array) $items as $post ) {
            $entries = array_merge( array( $post ), (array) ( $post['copy_history'] ?? array() ) );
            foreach ( $entries as $entry ) {
                foreach ( (array) ( $entry['attachments'] ?? array() ) as $attachment ) {
                    if ( 'video' !== ( $attachment['type'] ?? '' ) ) {
                        continue;
                    }
                    $video = $attachment['video'];
                    if ( empty( $video['owner_id'] ) || empty( $video['id'] ) ) {
                        continue;
                    }
                    // Один ролик может встретиться в нескольких постах: оставляем первое вхождение.
                    $videos[ $video['owner_id'] . '_' . $video['id'] ] = $video;
                }
            }
        }
        return $videos;
    }

    private static function counter( $item, $key ) {
        $value = $item[ $key ] ?? null;
        if ( is_array( $value ) ) {
            $value = $value['count'] ?? null;
        }
        return is_numeric( $value ) ? max( 0, (int) $value ) : null;
    }

    public static function save_video( $item, $source_id = 0 ) {
        global $wpdb;
        if ( empty( $item['owner_id'] ) || empty( $item['id'] ) ) {
            return new WP_Error( 'empty_video', 'VK не вернул доступный ролик.', array( 'status' => 422 ) );
        }
        $videos = self::table( 'videos' );
        $snapshots = self::table( 'snapshots' );
        $now = gmdate( 'Y-m-d H:i:s' );
        $images = array_merge( (array) ( $item['image'] ?? array() ), (array) ( $item['first_frame'] ?? array() ) );
        usort( $images, static function ( $a, $b ) { return ( $b['width'] ?? 0 ) <=> ( $a['width'] ?? 0 ); } );
        $data = array(
            'owner_id' => (int) $item['owner_id'], 'video_id' => (int) $item['id'],
            'title' => sanitize_text_field( $item['title'] ?? 'Без названия' ),
            'thumbnail' => esc_url_raw( $images[0]['url'] ?? '', array( 'https' ) ),
            'duration' => absint( $item['duration'] ?? 0 ),
            'published_at' => empty( $item['date'] ) ? null : gmdate( 'Y-m-d H:i:s', (int) $item['date'] ),
            'measured_at' => $now, 'next_run' => gmdate( 'Y-m-d H:i:s', time() + VKT_Plugin::settings()['video_hours'] * HOUR_IN_SECONDS ),
            // Тип приходит от VK: short_video у клипа, video у обычного ролика.
            'kind' => sanitize_key( $item['type'] ?? '' ),
        );
        if ( $source_id ) {
            $data['source_id'] = (int) $source_id;
        }
        foreach ( array( 'views', 'likes', 'comments', 'reposts' ) as $key ) {
            $data[ $key ] = self::counter( $item, $key );
        }
        $wpdb->query( 'START TRANSACTION' );
        // The unique row lock serializes a manual save and a background refresh.
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO $videos (owner_id,video_id,title,thumbnail,measured_at,next_run) VALUES (%d,%d,'','',%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            $data['owner_id'], $data['video_id'], $now, $data['next_run']
        ) );
        $id = (int) $wpdb->insert_id;
        if ( false === $result || ! $id ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_write', 'Не удалось сохранить ролик.', array( 'status' => 500 ) );
        }
        // 1 — новая строка, 0 — ролик уже был в базе.
        $created = 1 === $result;
        // Compare samples at least one minute apart; a missing counter stays unknown.
        $previous = $wpdb->get_row( $wpdb->prepare( "SELECT views,measured_at FROM $snapshots WHERE video_id=%d AND measured_at<=%s ORDER BY measured_at DESC LIMIT 1", $id, gmdate( 'Y-m-d H:i:s', time() - 60 ) ), ARRAY_A );
        $data['growth'] = null;
        $data['velocity'] = null;
        if ( $previous && null !== $previous['views'] && null !== $data['views'] ) {
            $data['growth'] = $data['views'] - (int) $previous['views'];
            $hours = ( time() - strtotime( $previous['measured_at'] . ' UTC' ) ) / 3600;
            $data['velocity'] = $data['growth'] >= 0 && $hours > 0 ? round( $data['growth'] / $hours, 2 ) : null;
        }
        $sample = array( 'video_id' => $id, 'measured_at' => $now );
        foreach ( array( 'views', 'likes', 'comments', 'reposts' ) as $key ) {
            $sample[ $key ] = $data[ $key ];
        }
        if ( false === $wpdb->update( $videos, $data, array( 'id' => $id ) ) || false === $wpdb->replace( $snapshots, $sample ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_write', 'Не удалось сохранить замер.', array( 'status' => 500 ) );
        }
        $wpdb->query( 'COMMIT' );
        // Новый ролик источника сразу попадает в подборки всех его подписчиков.
        // Только новый: удалённый из подборки не должен возвращаться с каждым замером.
        if ( $created && $source_id ) {
            VKT_Subscriptions::share_video( $id, (int) $source_id );
        }
        return $id;
    }

    /**
     * Данные кабинета: ролики из своей подборки, свои товары и источники.
     * Очередь сборщика, журнал и cron общие для сайта — их видит только
     * администратор.
     */
    public static function state( $page = 1, $search = '', $sort = 'velocity' ) {
        global $wpdb;
        $user_id = VKT_Account::id();
        $admin = VKT_Account::is_admin();
        $v = self::table( 'videos' );
        $p = self::table( 'products' );
        $l = self::table( 'links' );
        $mine = 'id IN (' . VKT_Subscriptions::videos_sql( $user_id ) . ')';
        $own_posts = 'source_id IN (' . VKT_Subscriptions::sources_sql( $user_id ) . ')';
        $where = 'WHERE ' . $mine . ( $search ? $wpdb->prepare( ' AND title LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' ) : '' );
        $order = in_array( $sort, array( 'velocity', 'views', 'measured_at' ), true ) ? $sort : 'velocity';
        $offset = ( max( 1, (int) $page ) - 1 ) * 24;
        // $where уже прошёл prepare — повторно его через prepare пропускать нельзя.
        $videos = (array) $wpdb->get_results( "SELECT * FROM $v $where ORDER BY $order DESC,id DESC LIMIT 24 OFFSET " . (int) $offset, ARRAY_A );
        foreach ( $videos as &$video ) {
            // Связи с товарами личные: показываем только свои товары.
            $video['products'] = $wpdb->get_results( $wpdb->prepare( "SELECT p.id,p.title FROM $p p JOIN $l l ON l.product_id=p.id WHERE l.video_id=%d AND p.user_id=%d", $video['id'], $user_id ), ARRAY_A );
        }
        unset( $video );
        return array(
            'settings' => VKT_Plugin::public_settings(),
            'stats' => array_merge(
                (array) $wpdb->get_row( "SELECT COUNT(*) AS videos,COALESCE(SUM(views),0) AS views,COUNT(velocity) AS measured,MAX(measured_at) AS last_measurement FROM $v WHERE $mine", ARRAY_A ),
                (array) $wpdb->get_row( 'SELECT COUNT(*) AS posts,COALESCE(SUM(views),0) AS post_views,COALESCE(SUM(g1),0) AS post_day_growth,AVG(err) AS post_err FROM ' . self::table( 'posts' ) . " WHERE $own_posts", ARRAY_A )
            ),
            'videos' => $videos,
            'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $v $where" ),
            'page' => max( 1, (int) $page ),
            'products' => $wpdb->get_results( $wpdb->prepare( "SELECT p.*,COUNT(l.video_id) AS videos,COALESCE(SUM(v.views),0) AS views,SUM(v.velocity) AS velocity FROM $p p LEFT JOIN $l l ON l.product_id=p.id LEFT JOIN $v v ON v.id=l.video_id WHERE p.user_id=%d GROUP BY p.id ORDER BY p.id DESC LIMIT 500", $user_id ), ARRAY_A ),
            // Пауза — своя, у подписки: общий источник обходится, пока он нужен хоть кому-то.
            'sources' => $wpdb->get_results( $wpdb->prepare(
                'SELECT s.id,s.kind,s.value,s.title,s.photo,s.members,s.next_run,s.synced_at,sub.enabled FROM ' . self::table( 'subscriptions' ) . ' sub JOIN ' . self::table( 'sources' ) . ' s ON s.id=sub.source_id WHERE sub.user_id=%d ORDER BY s.id DESC LIMIT 500',
                $user_id
            ), ARRAY_A ),
            // Название источника или ролика рядом с заданием: «Источник #10» ни о чём не говорит.
            'jobs' => $admin ? $wpdb->get_results(
                'SELECT j.*,COALESCE(NULLIF(s.title,\'\'),s.value) AS source_title,v.title AS video_title FROM ' . self::table( 'jobs' ) . ' j'
                . ' LEFT JOIN ' . self::table( 'sources' ) . ' s ON j.kind=\'source\' AND s.id=j.entity_id'
                . ' LEFT JOIN ' . self::table( 'videos' ) . ' v ON j.kind=\'video\' AND v.id=j.entity_id'
                . ' ORDER BY j.updated_at DESC,j.id DESC LIMIT 50',
                ARRAY_A
            ) : array(),
            'queue' => $admin ? $wpdb->get_results( 'SELECT status,COUNT(*) AS count FROM ' . self::table( 'jobs' ) . ' GROUP BY status', ARRAY_A ) : array(),
            'logs' => $admin ? $wpdb->get_results( 'SELECT * FROM ' . self::table( 'logs' ) . ' ORDER BY id DESC LIMIT 100', ARRAY_A ) : array(),
            'cron' => $admin ? array( 'next' => wp_next_scheduled( 'vkt_collect' ), 'last' => get_option( 'vkt_last_run', null ) ) : array( 'next' => null, 'last' => null ),
        );
    }
}

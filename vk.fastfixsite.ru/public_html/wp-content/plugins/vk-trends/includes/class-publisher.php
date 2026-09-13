<?php
defined( 'ABSPATH' ) || exit;

/**
 * Публикация записей в собственные сообщества VK.
 *
 * Наблюдаемые источники и собственные сообщества намеренно хранятся отдельно:
 * наличие стены в мониторинге не означает права записи в неё.
 */
final class VKT_Publisher {
    const MAX_GROUPS_PER_POST = 50;
    const MAX_ATTEMPTS = 4;

    public static function schema() {
        return array(
            'publishing_groups' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                group_id bigint unsigned NOT NULL,
                screen_name varchar(100) NOT NULL DEFAULT '',
                name varchar(255) NOT NULL DEFAULT '',
                photo text NOT NULL,
                admin_level tinyint unsigned NOT NULL DEFAULT 0,
                can_post tinyint unsigned NOT NULL DEFAULT 0,
                enabled tinyint unsigned NOT NULL DEFAULT 1,
                synced_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY vk_group (group_id),
                KEY available (enabled,can_post)",
            'outbound_posts' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                message longtext NOT NULL,
                attachments text NOT NULL,
                signed tinyint unsigned NOT NULL DEFAULT 0,
                close_comments tinyint unsigned NOT NULL DEFAULT 0,
                origin varchar(20) NOT NULL DEFAULT 'manual',
                editor_status varchar(20) NOT NULL DEFAULT 'awaiting',
                status varchar(20) NOT NULL DEFAULT 'scheduled',
                scheduled_at datetime NOT NULL,
                published_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY schedule (status,scheduled_at)",
            'outbound_deliveries' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                outbound_post_id bigint unsigned NOT NULL,
                group_id bigint unsigned NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                attempts int unsigned NOT NULL DEFAULT 0,
                available_at datetime NOT NULL,
                vk_post_id bigint unsigned DEFAULT NULL,
                guid varchar(64) NOT NULL,
                error varchar(255) NOT NULL DEFAULT '',
                published_at datetime DEFAULT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY post_group (outbound_post_id,group_id),
                KEY ready (status,available_at),
                KEY campaign (outbound_post_id)",
        );
    }

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_publisher', $message, array( 'status' => $status ) );
    }

    /** Загружает все сообщества, где пользователь — администратор или редактор. */
    public static function sync_groups() {
        global $wpdb;
        if ( 'user' !== VKT_API::mode() ) {
            return self::error( 'Сначала сохраните пользовательский токен VK ID с правами wall и groups.' );
        }
        $result = VKT_API::publishing_request( 'groups.get', array(
            'filter' => 'editor',
            'extended' => 1,
            'fields' => 'members_count,photo_200,screen_name',
            'count' => 1000,
        ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $response = (array) ( $result['response'] ?? array() );
        $items = (array) ( $response['items'] ?? array() );
        $table = VKT_Store::table( 'publishing_groups' );
        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->query( 'START TRANSACTION' );
        try {
            // После успешного полного ответа права считаем отозванными у отсутствующих групп.
            $wpdb->query( "UPDATE $table SET can_post=0" );
            $synced = 0;
            foreach ( array_slice( $items, 0, 1000 ) as $group ) {
                $group_id = absint( $group['id'] ?? 0 );
                if ( ! $group_id ) {
                    continue;
                }
                $admin_level = absint( $group['admin_level'] ?? 0 );
                // filter=editor уже ограничил список администраторами и редакторами.
                $can_post = isset( $group['can_post'] ) ? (int) ! empty( $group['can_post'] ) : (int) ( $admin_level >= 2 || ! empty( $group['is_admin'] ) );
                if ( ! $admin_level && ! isset( $group['can_post'] ) ) {
                    $can_post = 1;
                }
                $ok = $wpdb->query( $wpdb->prepare(
                    "INSERT INTO $table (group_id,screen_name,name,photo,admin_level,can_post,enabled,synced_at,created_at)
                     VALUES (%d,%s,%s,%s,%d,%d,1,%s,%s)
                     ON DUPLICATE KEY UPDATE screen_name=VALUES(screen_name),name=VALUES(name),photo=VALUES(photo),admin_level=VALUES(admin_level),can_post=VALUES(can_post),synced_at=VALUES(synced_at)",
                    $group_id,
                    sanitize_key( (string) ( $group['screen_name'] ?? '' ) ),
                    sanitize_text_field( (string) ( $group['name'] ?? '' ) ),
                    esc_url_raw( (string) ( $group['photo_200'] ?? $group['photo_100'] ?? '' ), array( 'https' ) ),
                    $admin_level,
                    $can_post,
                    $now,
                    $now
                ) );
                if ( false === $ok ) {
                    throw new RuntimeException( 'Не удалось сохранить список сообществ.' );
                }
                ++$synced;
            }
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return self::error( $error->getMessage(), 500 );
        }
        return array( 'synced' => $synced, 'total' => absint( $response['count'] ?? $synced ) );
    }

    public static function toggle_group( $id, $enabled ) {
        global $wpdb;
        $result = $wpdb->update(
            VKT_Store::table( 'publishing_groups' ),
            array( 'enabled' => $enabled ? 1 : 0 ),
            array( 'id' => absint( $id ) )
        );
        return false === $result ? self::error( 'Не удалось изменить сообщество.', 500 ) : array( 'ok' => true );
    }

    private static function sanitize_attachments( $raw ) {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( '' === $raw ) {
            return '';
        }
        $result = array();
        $links = 0;
        $documents = 0;
        $polls = 0;
        foreach ( preg_split( '/[\r\n,]+/', $raw ) as $item ) {
            $item = trim( $item );
            if ( '' === $item ) {
                continue;
            }
            if ( in_array( $item, $result, true ) ) {
                continue;
            }
            if ( preg_match( '~^https?://~i', $item ) ) {
                $url = esc_url_raw( $item, array( 'https', 'http' ) );
                if ( ! $url || ! wp_http_validate_url( $url ) || ++$links > 1 ) {
                    return self::error( 'Во вложениях допустима одна публичная ссылка http/https.' );
                }
                $result[] = $url;
            } elseif ( preg_match( '/^(photo|video|audio|doc|page|note|poll|album|market|market_album)-?[1-9]\d{0,18}_[1-9]\d{0,18}(?:_[a-zA-Z0-9_-]{1,255})?$/', $item, $match ) ) {
                if ( 'audio' === $match[1] ) {
                    return self::error( 'Аудио пока не поддерживается: VK разрешает его только вместе с фото или видео в режиме карусели.' );
                }
                if ( 'doc' === $match[1] && ++$documents > 1 ) {
                    return self::error( 'В одной записи VK допустим только один файл.' );
                }
                if ( 'poll' === $match[1] && ++$polls > 1 ) {
                    return self::error( 'В одной записи VK допустим только один опрос.' );
                }
                $result[] = $item;
            } else {
                return self::error( 'Вложение не распознано. Нужен ID вида photo-123_456, video-123_456 или одна ссылка.' );
            }
        }
        $result = array_values( array_unique( $result ) );
        if ( count( $result ) > 10 ) {
            return self::error( 'В одной записи может быть не больше 10 вложений.' );
        }
        if ( $polls && 1 === count( $result ) ) {
            return self::error( 'Опрос не может быть единственным вложением записи.' );
        }
        return implode( ',', $result );
    }

    public static function create( $data ) {
        global $wpdb;
        if ( 'user' !== VKT_API::mode() ) {
            return self::error( 'Для автопостинга нужен пользовательский токен VK ID с правами wall и groups.' );
        }
        // Будущий адаптер агентов может подготовить текст/вложения, но проходит
        // через те же проверки и не может обойти ручное подтверждение.
        $data = apply_filters( 'vkt_publisher_prepare_draft', $data );
        if ( ! is_array( $data ) ) {
            return self::error( 'Модуль подготовки вернул неверный формат записи.', 500 );
        }
        $message = is_string( $data['message'] ?? null ) ? trim( $data['message'] ) : '';
        if ( mb_strlen( $message ) > 16000 ) {
            return self::error( 'Текст записи должен быть не длиннее 16 000 символов.' );
        }
        $attachments = self::sanitize_attachments( $data['attachments'] ?? '' );
        if ( is_wp_error( $attachments ) ) {
            return $attachments;
        }
        if ( '' === $message && '' === $attachments ) {
            return self::error( 'Добавьте текст или вложение.' );
        }
        if ( '' === $message && ! preg_match( '~(?:^|,)(?:photo|video)-?[1-9]\d{0,18}_[1-9]\d{0,18}(?:_[a-zA-Z0-9_-]{1,255})?(?:,|$)|https?://~i', $attachments ) ) {
            return self::error( 'Запись без текста должна содержать фото, видео или внешнюю ссылку.' );
        }
        $group_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $data['groups'] ?? array() ) ) ) ) );
        if ( ! $group_ids || count( $group_ids ) > self::MAX_GROUPS_PER_POST ) {
            return self::error( 'Выберите от 1 до ' . self::MAX_GROUPS_PER_POST . ' сообществ.' );
        }
        $placeholders = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );
        $query = $wpdb->prepare(
            'SELECT id,group_id FROM ' . VKT_Store::table( 'publishing_groups' ) . " WHERE id IN ($placeholders) AND enabled=1 AND can_post=1",
            ...$group_ids
        );
        $groups = (array) $wpdb->get_results( $query, ARRAY_A );
        if ( count( $groups ) !== count( $group_ids ) ) {
            return self::error( 'Часть сообществ выключена или право публикации не подтверждено. Обновите список групп.' );
        }
        $scheduled_at = gmdate( 'Y-m-d H:i:s' );
        if ( ! empty( $data['scheduled_at'] ) && is_string( $data['scheduled_at'] ) ) {
            $timestamp = strtotime( $data['scheduled_at'] );
            if ( false === $timestamp || $timestamp > time() + YEAR_IN_SECONDS ) {
                return self::error( 'Укажите корректную дату публикации не дальше одного года.' );
            }
            $scheduled_at = gmdate( 'Y-m-d H:i:s', max( time(), $timestamp ) );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        // В ручном режиме все источники, включая будущих агентов, создают только
        // черновик. Автоматический режим потребует отдельной серверной настройки.
        $status = 'draft';
        $delivery_status = 'waiting_approval';
        $origin = in_array( $data['origin'] ?? 'manual', array( 'manual', 'agents' ), true ) ? $data['origin'] : 'manual';
        $wpdb->query( 'START TRANSACTION' );
        try {
            $ok = $wpdb->insert( VKT_Store::table( 'outbound_posts' ), array(
                'message' => $message,
                'attachments' => $attachments,
                'signed' => empty( $data['signed'] ) ? 0 : 1,
                'close_comments' => empty( $data['close_comments'] ) ? 0 : 1,
                'origin' => $origin,
                'editor_status' => 'awaiting',
                'status' => $status,
                'scheduled_at' => $scheduled_at,
                'created_at' => $now,
                'updated_at' => $now,
            ) );
            $post_id = (int) $wpdb->insert_id;
            if ( false === $ok || ! $post_id ) {
                throw new RuntimeException( 'Не удалось сохранить публикацию.' );
            }
            foreach ( $groups as $group ) {
                $ok = $wpdb->insert( VKT_Store::table( 'outbound_deliveries' ), array(
                    'outbound_post_id' => $post_id,
                    'group_id' => (int) $group['group_id'],
                    'status' => $delivery_status,
                    'available_at' => $scheduled_at,
                    'guid' => wp_generate_uuid4(),
                    'updated_at' => $now,
                ) );
                if ( false === $ok ) {
                    throw new RuntimeException( 'Не удалось создать задания для сообществ.' );
                }
            }
            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return self::error( $error->getMessage(), 500 );
        }
        do_action( 'vkt_publisher_draft_created', $post_id, $origin );
        return array( 'id' => $post_id, 'status' => $status, 'processed' => 0 );
    }

    public static function state() {
        global $wpdb;
        $groups = (array) $wpdb->get_results( 'SELECT * FROM ' . VKT_Store::table( 'publishing_groups' ) . ' ORDER BY enabled DESC,can_post DESC,name,id LIMIT 1000', ARRAY_A );
        $posts = (array) $wpdb->get_results( 'SELECT * FROM ' . VKT_Store::table( 'outbound_posts' ) . ' ORDER BY id DESC LIMIT 100', ARRAY_A );
        $deliveries_table = VKT_Store::table( 'outbound_deliveries' );
        $groups_table = VKT_Store::table( 'publishing_groups' );
        foreach ( $posts as &$post ) {
            $post['deliveries'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT d.id,d.outbound_post_id,d.group_id,d.status,d.attempts,d.available_at,d.vk_post_id,d.error,d.published_at,d.updated_at,g.name,g.screen_name,g.photo
                 FROM $deliveries_table d LEFT JOIN $groups_table g ON g.group_id=d.group_id WHERE d.outbound_post_id=%d ORDER BY d.id",
                $post['id']
            ), ARRAY_A );
        }
        unset( $post );
        return array(
            'groups' => $groups,
            'posts' => $posts,
            'status' => array(
                'token_ready' => 'user' === VKT_API::mode(),
                'next' => wp_next_scheduled( 'vkt_publish' ),
                'last' => get_option( 'vkt_last_publish_run', null ),
            ),
        );
    }

    private static function refresh_post_status( $post_id ) {
        global $wpdb;
        $deliveries = VKT_Store::table( 'outbound_deliveries' );
        $posts = VKT_Store::table( 'outbound_posts' );
        $counts = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS amount FROM $deliveries WHERE outbound_post_id=%d GROUP BY status", $post_id ), OBJECT_K );
        $published = isset( $counts['published'] ) ? (int) $counts['published']->amount : 0;
        $pending = ( isset( $counts['pending'] ) ? (int) $counts['pending']->amount : 0 ) + ( isset( $counts['publishing'] ) ? (int) $counts['publishing']->amount : 0 );
        $failed = isset( $counts['failed'] ) ? (int) $counts['failed']->amount : 0;
        $cancelled = isset( $counts['cancelled'] ) ? (int) $counts['cancelled']->amount : 0;
        if ( $pending ) {
            $status = 'queued';
        } elseif ( ( $failed || $cancelled ) && $published ) {
            $status = 'partial';
        } elseif ( $failed ) {
            $status = 'failed';
        } elseif ( $published ) {
            $status = 'published';
        } else {
            $status = 'cancelled';
        }
        $data = array( 'status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
        if ( 'published' === $status ) {
            $data['published_at'] = gmdate( 'Y-m-d H:i:s' );
        }
        $wpdb->update( $posts, $data, array( 'id' => $post_id ) );
    }

    public static function cancel( $post_id ) {
        global $wpdb;
        $post_id = absint( $post_id );
        $deliveries = VKT_Store::table( 'outbound_deliveries' );
        $posts = VKT_Store::table( 'outbound_posts' );
        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->query( $wpdb->prepare( "UPDATE $deliveries SET status='cancelled',updated_at=%s WHERE outbound_post_id=%d AND status IN ('pending','waiting_approval')", $now, $post_id ) );
        $wpdb->query( $wpdb->prepare( "UPDATE $posts SET editor_status='rejected',updated_at=%s WHERE id=%d AND status='draft'", $now, $post_id ) );
        self::refresh_post_status( $post_id );
        return array( 'ok' => true );
    }

    /** Последний обязательный шлюз перед тем, как задания увидит cron. */
    public static function approve( $post_id ) {
        global $wpdb;
        $post_id = absint( $post_id );
        $posts = VKT_Store::table( 'outbound_posts' );
        $post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $posts WHERE id=%d AND status='draft'", $post_id ), ARRAY_A );
        if ( ! $post ) {
            return self::error( 'Черновик не найден или уже был подтверждён.', 404 );
        }
        $approval = apply_filters( 'vkt_publisher_editor_approval', true, $post );
        if ( is_wp_error( $approval ) ) {
            return $approval;
        }
        if ( true !== $approval ) {
            return self::error( 'Редактор не подтвердил публикацию.', 409 );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $status = strtotime( $post['scheduled_at'] . ' UTC' ) > time() + 30 ? 'scheduled' : 'queued';
        $wpdb->query( 'START TRANSACTION' );
        $deliveries = VKT_Store::table( 'outbound_deliveries' );
        $changed = $wpdb->query( $wpdb->prepare( "UPDATE $deliveries SET status='pending',updated_at=%s WHERE outbound_post_id=%d AND status='waiting_approval'", $now, $post_id ) );
        $updated = $wpdb->update( $posts, array( 'status' => $status, 'editor_status' => 'approved', 'updated_at' => $now ), array( 'id' => $post_id, 'status' => 'draft' ) );
        if ( false === $changed || $changed < 1 || 1 !== $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return self::error( 'Не удалось подтвердить публикацию.', 500 );
        }
        $wpdb->query( 'COMMIT' );
        $run = 'queued' === $status ? self::run_due( 3 ) : array( 'processed' => 0 );
        if ( is_wp_error( $run ) ) {
            return array( 'id' => $post_id, 'status' => $status, 'warning' => $run->get_error_message() );
        }
        $current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $posts WHERE id=%d", $post_id ) );
        return array( 'id' => $post_id, 'status' => $current ?: $status, 'processed' => $run['processed'] );
    }

    public static function retry( $post_id ) {
        global $wpdb;
        $post_id = absint( $post_id );
        $now = gmdate( 'Y-m-d H:i:s' );
        $result = $wpdb->query( $wpdb->prepare( "UPDATE " . VKT_Store::table( 'outbound_deliveries' ) . " SET status='pending',attempts=0,available_at=%s,error='',updated_at=%s WHERE outbound_post_id=%d AND status='failed'", $now, $now, $post_id ) );
        if ( false === $result ) {
            return self::error( 'Не удалось вернуть публикацию в очередь.', 500 );
        }
        self::refresh_post_status( $post_id );
        return array( 'ok' => true, 'deliveries' => (int) $result );
    }

    public static function run_due( $limit = 2 ) {
        global $wpdb;
        if ( 'user' !== VKT_API::mode() ) {
            return self::error( 'Автопостинг ожидает пользовательский токен VK ID с правами wall и groups.' );
        }
        if ( ! VKT_Store::lock( 'publisher', 120 ) ) {
            return self::error( 'Публикация уже выполняется.', 409 );
        }
        $processed = 0;
        $deliveries = VKT_Store::table( 'outbound_deliveries' );
        $posts = VKT_Store::table( 'outbound_posts' );
        $groups = VKT_Store::table( 'publishing_groups' );
        try {
            update_option( 'vkt_last_publish_run', gmdate( 'Y-m-d H:i:s' ), false );
            $wpdb->query( $wpdb->prepare( "UPDATE $deliveries SET status='pending' WHERE status='publishing' AND updated_at<%s", gmdate( 'Y-m-d H:i:s', time() - 180 ) ) );
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT d.*,p.message,p.attachments,p.signed,p.close_comments,g.id AS local_group_id,g.name,g.enabled,g.can_post
                 FROM $deliveries d JOIN $posts p ON p.id=d.outbound_post_id LEFT JOIN $groups g ON g.group_id=d.group_id
                 WHERE d.status='pending' AND d.available_at<=%s ORDER BY d.available_at,d.id LIMIT %d",
                gmdate( 'Y-m-d H:i:s' ), max( 1, min( 10, absint( $limit ) ) )
            ), ARRAY_A );
            foreach ( $rows as $index => $delivery ) {
                $claimed = $wpdb->update( $deliveries, array( 'status' => 'publishing', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $delivery['id'], 'status' => 'pending' ) );
                if ( 1 !== $claimed ) {
                    continue;
                }
                $attempts = (int) $delivery['attempts'] + 1;
                if ( ! $delivery['local_group_id'] || ! $delivery['enabled'] || ! $delivery['can_post'] ) {
                    $result = self::error( 'Сообщество выключено или право публикации отозвано.' );
                } else {
                    $params = array(
                        'owner_id' => -absint( $delivery['group_id'] ),
                        'from_group' => 1,
                        'signed' => (int) $delivery['signed'],
                        'close_comments' => (int) $delivery['close_comments'],
                        'guid' => $delivery['guid'],
                    );
                    if ( '' !== $delivery['message'] ) {
                        $params['message'] = $delivery['message'];
                    }
                    if ( '' !== $delivery['attachments'] ) {
                        $params['attachments'] = $delivery['attachments'];
                    }
                    $result = VKT_API::publishing_request( 'wall.post', $params );
                }
                $changes = array( 'attempts' => $attempts, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
                if ( is_wp_error( $result ) ) {
                    $error_data = (array) $result->get_error_data();
                    $retry = ! empty( $error_data['retryable'] ) && $attempts < self::MAX_ATTEMPTS;
                    $changes['status'] = $retry ? 'pending' : 'failed';
                    $changes['available_at'] = gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** $attempts ) ) );
                    $changes['error'] = mb_substr( $result->get_error_message(), 0, 255 );
                } else {
                    $changes['status'] = 'published';
                    $changes['vk_post_id'] = absint( $result['response']['post_id'] ?? 0 );
                    $changes['published_at'] = gmdate( 'Y-m-d H:i:s' );
                    $changes['error'] = '';
                }
                $wpdb->update( $deliveries, $changes, array( 'id' => $delivery['id'] ) );
                self::refresh_post_status( (int) $delivery['outbound_post_id'] );
                ++$processed;
                if ( $index + 1 < count( $rows ) ) {
                    sleep( 2 );
                }
            }
            return array( 'processed' => $processed, 'message' => $processed ? 'Обработано публикаций: ' . $processed . '.' : 'Нет записей, готовых к публикации.' );
        } finally {
            VKT_Store::unlock( 'publisher' );
        }
    }
}

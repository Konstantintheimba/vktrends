<?php
defined( 'ABSPATH' ) || exit;

final class VKT_Collector {
    public static function enqueue( $kind, $id ) {
        global $wpdb;
        $table = VKT_Store::table( 'jobs' );
        $now = gmdate( 'Y-m-d H:i:s' );
        return $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (job_key,kind,entity_id,status,available_at,updated_at) VALUES (%s,%s,%d,'pending',%s,%s)
            ON DUPLICATE KEY UPDATE attempts=IF(status IN ('done','failed'),0,attempts), available_at=IF(status IN ('done','failed'),VALUES(available_at),available_at), message=IF(status IN ('done','failed'),'',message), status=IF(status IN ('done','failed'),'pending',status),updated_at=VALUES(updated_at)",
            $kind . ':' . $id, $kind, $id, $now, $now
        ) );
    }

    private static function seed() {
        global $wpdb;
        $settings = VKT_Plugin::settings();
        $now = gmdate( 'Y-m-d H:i:s' );
        // У обхода сообществ и точечных замеров роликов свои интервалы.
        $hours = array( 'source' => (int) $settings['source_hours'], 'video' => (int) $settings['video_hours'] );
        foreach ( array( 'source' => 'sources', 'video' => 'videos' ) as $kind => $name ) {
            $next = gmdate( 'Y-m-d H:i:s', time() + $hours[ $kind ] * HOUR_IN_SECONDS );
            $table = VKT_Store::table( $name );
            $extra = 'source' === $kind ? ' AND enabled=1' : '';
            $jobs = VKT_Store::table( 'jobs' );
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE next_run<=%s $extra AND id NOT IN (SELECT entity_id FROM $jobs WHERE kind=%s AND status='failed') ORDER BY next_run LIMIT 30", $now, $kind ) );
            foreach ( $ids as $id ) {
                if ( false !== self::enqueue( $kind, (int) $id ) ) {
                    $wpdb->update( $table, array( 'next_run' => $next ), array( 'id' => $id ) );
                }
            }
        }
    }

    public static function run( $manual = false ) {
        global $wpdb;
        // Retention also runs while collection is paused or no token is configured.
        if ( ! get_transient( 'vkt_logs_pruned' ) ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . VKT_Store::table( 'logs' ) . ' WHERE created_at<%s LIMIT 10000', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
            // История постов прореживается по возрасту, иначе таблица замеров растёт линейно.
            VKT_Posts::prune();
            set_transient( 'vkt_logs_pruned', 1, HOUR_IN_SECONDS );
        }
        if ( ! $manual && VKT_Plugin::settings()['paused'] ) {
            return array( 'processed' => 0, 'message' => 'Автосбор на паузе.' );
        }
        if ( ! VKT_API::token() ) {
            return new WP_Error( 'no_token', 'Сначала сохраните Access token.', array( 'status' => 400 ) );
        }
        if ( ! VKT_Store::lock( 'collector', 120 ) ) {
            return new WP_Error( 'busy', 'Сборщик уже работает.', array( 'status' => 409 ) );
        }
        $processed = 0;
        try {
            update_option( 'vkt_last_run', gmdate( 'Y-m-d H:i:s' ), false );
            $jobs = VKT_Store::table( 'jobs' );
            $wpdb->query( $wpdb->prepare( "UPDATE $jobs SET status='pending' WHERE status='running' AND updated_at<%s", gmdate( 'Y-m-d H:i:s', time() - 180 ) ) );
            // A failed job needs an explicit retry, not an endless automatic loop.
            self::seed();
            // Источники идут первыми: свежие посты сообщества важнее переизмерения старого ролика.
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $jobs WHERE status='pending' AND available_at<=%s ORDER BY kind<>'source',available_at,id LIMIT 2", gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
            foreach ( $rows as $job ) {
                $wpdb->update( $jobs, array( 'status' => 'running', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $job['id'] ) );
                $result = self::process( $job );
                $attempts = (int) $job['attempts'] + 1;
                $changes = array( 'attempts' => $attempts, 'status' => 'done', 'message' => 'Готово', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
                if ( is_wp_error( $result ) ) {
                    $data = $result->get_error_data();
                    $retry = ! empty( $data['retryable'] ) && $attempts < 4;
                    $changes['status'] = $retry ? 'pending' : 'failed';
                    $changes['message'] = $result->get_error_message();
                    $changes['available_at'] = gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** $attempts ) ) );
                }
                $wpdb->update( $jobs, $changes, array( 'id' => $job['id'] ) );
                ++$processed;
                if ( $processed < count( $rows ) ) {
                    sleep( 2 );
                }
            }
            VKT_Posts::reextract();
            $links = self::resolve_links();
            $message = $processed ? 'Обработано заданий: ' . $processed . '. Результаты — в очереди и журнале.' : 'Нет заданий, готовых к запуску.';
            if ( $links ) {
                $message .= ' Прочитано ссылок на товары: ' . $links . '.';
            }
            return array( 'processed' => $processed, 'links' => $links, 'message' => $message );
        } finally {
            VKT_Store::unlock( 'collector' );
        }
    }

    // Параметры обхода стены. Поисковые источники требуют video.search, а он недоступен сервисному токену.
    private static function wall_params( $source ) {
        // extended=1 добавляет в ответ карточку сообщества: подписчики нужны для ERR и виральности.
        $extended = array( 'count' => 100, 'extended' => 1, 'fields' => 'members_count,photo_200,screen_name' );
        if ( 'domain' === $source['kind'] ) {
            return array_merge( array( 'domain' => $source['value'] ), $extended );
        }
        if ( 'owner' === $source['kind'] ) {
            return array_merge( array( 'owner_id' => (int) $source['value'] ), $extended );
        }
        return new WP_Error( 'unsupported_source', 'Поиск по запросу требует пользовательский токен. Замените источник на сообщество: короткое имя или числовой ID.', array( 'status' => 400 ) );
    }

    // Точечный замер ролика через video.get: доступно только пользовательскому токену,
    // точнее и дешевле полного обхода стены источника.
    private static function measure_video_point( $video ) {
        $result = VKT_API::request( 'video.get', array( 'videos' => $video['owner_id'] . '_' . $video['video_id'] ), 'collector' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $items = $result['response']['items'] ?? array();
        if ( ! $items ) {
            return new WP_Error( 'unavailable', 'Ролик удалён или недоступен. Предыдущие замеры сохранены.' );
        }
        foreach ( $items as $item ) {
            if ( (int) ( $item['id'] ?? 0 ) !== (int) $video['video_id'] || (int) ( $item['owner_id'] ?? 0 ) !== (int) $video['owner_id'] ) {
                continue;
            }
            $saved = VKT_Store::save_video( $item, (int) $video['source_id'] );
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }
        }
        return true;
    }

    /**
     * Читает до двух ссылок на товары за прогон. Это запросы к магазинам, а не к VK,
     * поэтому они не занимают слоты очереди и не расходуют лимит API.
     */
    private static function resolve_links() {
        if ( ! VKT_Plugin::settings()['links'] ) {
            return 0;
        }
        $done = 0;
        foreach ( VKT_Links::due( 2 ) as $index => $id ) {
            if ( $index ) {
                sleep( 2 );
            }
            VKT_Links::resolve( $id );
            ++$done;
        }
        return $done;
    }

    // Пишем посты обхода. Ошибка одного поста не должна ронять весь обход источника.
    private static function save_posts( $items, $source_id, $members ) {
        $saved = 0;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || empty( $item['id'] ) ) {
                continue;
            }
            $result = VKT_Posts::save( $item, $source_id, $members );
            if ( ! is_wp_error( $result ) ) {
                ++$saved;
            }
        }
        return $saved;
    }

    /**
     * Обновляет карточку источника из extended-ответа wall.get и возвращает число подписчиков.
     * Название сообщества, аватар и members_count лежат в groups/profiles рядом с постами.
     */
    private static function sync_community( $source_id, $source, $response ) {
        global $wpdb;
        $owner = 'owner' === $source['kind'] ? (int) $source['value'] : 0;
        $domain = 'domain' === $source['kind'] ? strtolower( (string) $source['value'] ) : '';
        $card = null;
        foreach ( (array) ( $response['groups'] ?? array() ) as $group ) {
            $screen = strtolower( (string) ( $group['screen_name'] ?? '' ) );
            $matches = $owner ? -abs( (int) ( $group['id'] ?? 0 ) ) === $owner : ( '' === $domain || $screen === $domain );
            if ( $matches ) {
                $card = $group;
                break;
            }
        }
        if ( ! $card ) {
            foreach ( (array) ( $response['profiles'] ?? array() ) as $profile ) {
                $screen = strtolower( (string) ( $profile['screen_name'] ?? '' ) );
                if ( $owner ? (int) ( $profile['id'] ?? 0 ) === $owner : ( '' === $domain || $screen === $domain ) ) {
                    $card = $profile;
                    $card['name'] = trim( ( $profile['first_name'] ?? '' ) . ' ' . ( $profile['last_name'] ?? '' ) );
                    break;
                }
            }
        }
        if ( ! $card ) {
            return null;
        }
        $members = isset( $card['members_count'] ) && is_numeric( $card['members_count'] ) ? (int) $card['members_count'] : null;
        $data = array( 'synced_at' => gmdate( 'Y-m-d H:i:s' ) );
        if ( ! empty( $card['name'] ) ) {
            $data['title'] = sanitize_text_field( (string) $card['name'] );
        }
        $photo = esc_url_raw( (string) ( $card['photo_200'] ?? $card['photo_100'] ?? '' ), array( 'https' ) );
        if ( $photo ) {
            $data['photo'] = $photo;
        }
        if ( null !== $members ) {
            $data['members'] = $members;
        }
        $wpdb->update( VKT_Store::table( 'sources' ), $data, array( 'id' => (int) $source_id ) );
        return $members;
    }

    private static function process( $job ) {
        global $wpdb;
        $only = '';
        $source_id = 0;
        if ( 'video' === $job['kind'] ) {
            $video = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'videos' ) . ' WHERE id=%d', $job['entity_id'] ), ARRAY_A );
            if ( ! $video ) {
                return true;
            }
            if ( 'user' === VKT_API::mode() ) {
                return self::measure_video_point( $video );
            }
            $only = $video['owner_id'] . '_' . $video['video_id'];
            $source_id = (int) $video['source_id'];
            // Точечного замера у сервисного токена нет: перечитываем стену источника,
            // а для добавленных вручную роликов — стену их владельца.
            $wall = $source_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'sources' ) . ' WHERE id=%d', $source_id ), ARRAY_A ) : null;
            if ( ! $wall ) {
                $wall = array( 'kind' => 'owner', 'value' => $video['owner_id'] );
                $source_id = 0;
            }
        } else {
            $wall = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'sources' ) . ' WHERE id=%d AND enabled=1', $job['entity_id'] ), ARRAY_A );
            if ( ! $wall ) {
                return true;
            }
            $source_id = (int) $wall['id'];
        }
        $params = self::wall_params( $wall );
        if ( is_wp_error( $params ) ) {
            return $params;
        }
        $section_videos = array();
        // Видеораздел владельца доступен только пользовательскому токену и только по числовому ID,
        // короткое имя (domain) video.get не принимает.
        if ( 'user' === VKT_API::mode() && 'owner' === $wall['kind'] ) {
            $section = VKT_API::request( 'video.get', array( 'owner_id' => (int) $wall['value'], 'count' => 100 ), 'collector' );
            if ( ! is_wp_error( $section ) ) {
                foreach ( (array) ( $section['response']['items'] ?? array() ) as $item ) {
                    if ( empty( $item['owner_id'] ) || empty( $item['id'] ) ) {
                        continue;
                    }
                    $section_videos[ $item['owner_id'] . '_' . $item['id'] ] = $item;
                }
            }
        }
        $result = VKT_API::request( 'wall.get', $params, 'collector' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $items = (array) ( $result['response']['items'] ?? array() );
        // Карточка сообщества из extended-ответа: подписчики, название и аватар источника.
        $members = $source_id ? self::sync_community( $source_id, $wall, $result['response'] ) : null;
        if ( VKT_Plugin::settings()['posts'] && ! $only ) {
            self::save_posts( $items, $source_id, $members );
        }
        // Видеораздел точнее клипов из постов: при совпадении ID он не перезаписывается стеной.
        $videos = $section_videos + VKT_Store::videos_from_posts( $items );
        if ( $only ) {
            if ( ! isset( $videos[ $only ] ) ) {
                return new WP_Error( 'unavailable', 'Ролика нет среди свежих постов стены. Предыдущие замеры сохранены.' );
            }
            $videos = array( $only => $videos[ $only ] );
        }
        foreach ( $videos as $item ) {
            $saved = VKT_Store::save_video( $item, $source_id );
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }
        }
        return true;
    }
}

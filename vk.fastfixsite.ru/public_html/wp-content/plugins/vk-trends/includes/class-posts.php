<?php
defined( 'ABSPATH' ) || exit;

// Load the parser even when an older plugin entry point is still in use.
require_once __DIR__ . '/class-commerce.php';

/**
 * Посты сообществ: хранение, замеры и агрегаты динамики.
 * Данные приходят тем же wall.get, что и ролики, — отдельных запросов к VK не нужно.
 */
final class VKT_Posts {
    // Окна динамики: колонка => сколько дней назад ищем опорный замер.
    const WINDOWS = array( 'g1' => 1, 'g2' => 2, 'g3' => 3, 'g4' => 4, 'g5' => 5, 'g6' => 6, 'g7' => 7, 'g30' => 30 );

    // Схемы таблиц отдаются в VKT_Store::install(): dbDelta создаёт и обновляет их вместе с остальными.
    public static function schema() {
        return array(
            'posts' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                owner_id bigint NOT NULL,
                post_id bigint unsigned NOT NULL,
                source_id bigint unsigned DEFAULT NULL,
                from_id bigint NOT NULL DEFAULT 0,
                post_type varchar(20) NOT NULL DEFAULT '',
                is_ad tinyint NOT NULL DEFAULT 0,
                is_pinned tinyint NOT NULL DEFAULT 0,
                is_repost tinyint NOT NULL DEFAULT 0,
                text mediumtext NOT NULL,
                thumbnail text NOT NULL,
                media varchar(120) NOT NULL DEFAULT '',
                media_count int unsigned NOT NULL DEFAULT 0,
                link_url text NOT NULL,
                link_id bigint unsigned DEFAULT NULL,
                link_title varchar(255) NOT NULL DEFAULT '',
                link_domain varchar(120) NOT NULL DEFAULT '',
                cards mediumtext NOT NULL,
                text_source varchar(20) NOT NULL DEFAULT 'post',
                text_product varchar(255) NOT NULL DEFAULT '',
                text_price double DEFAULT NULL,
                product_source varchar(20) NOT NULL DEFAULT '',
                commerce_version int unsigned NOT NULL DEFAULT 0,
                product_title varchar(255) NOT NULL DEFAULT '',
                product_price double DEFAULT NULL,
                product_old_price double DEFAULT NULL,
                product_sku varchar(64) NOT NULL DEFAULT '',
                market_id varchar(48) NOT NULL DEFAULT '',
                published_at datetime DEFAULT NULL,
                views bigint unsigned DEFAULT NULL,
                likes bigint unsigned DEFAULT NULL,
                comments bigint unsigned DEFAULT NULL,
                reposts bigint unsigned DEFAULT NULL,
                growth bigint DEFAULT NULL,
                velocity double DEFAULT NULL,
                g1 bigint DEFAULT NULL,
                g2 bigint DEFAULT NULL,
                g3 bigint DEFAULT NULL,
                g4 bigint DEFAULT NULL,
                g5 bigint DEFAULT NULL,
                g6 bigint DEFAULT NULL,
                g7 bigint DEFAULT NULL,
                g30 bigint DEFAULT NULL,
                err double DEFAULT NULL,
                viral double DEFAULT NULL,
                measured_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY vk_post (owner_id,post_id),
                KEY commerce (commerce_version,id),
                KEY origin (source_id),
                KEY link (link_id),
                KEY velocity (velocity),
                KEY day_growth (g1),
                KEY views (views),
                KEY published (published_at)",
            'post_products' => "post_id bigint unsigned NOT NULL,
                item_index int unsigned NOT NULL,
                link_id bigint unsigned DEFAULT NULL,
                title varchar(255) NOT NULL DEFAULT '',
                price double DEFAULT NULL,
                recognized tinyint NOT NULL DEFAULT 0,
                shop varchar(60) NOT NULL DEFAULT '',
                PRIMARY KEY  (post_id,item_index),
                KEY link (link_id)",
            'post_snapshots' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint unsigned NOT NULL,
                views bigint unsigned DEFAULT NULL,
                likes bigint unsigned DEFAULT NULL,
                comments bigint unsigned DEFAULT NULL,
                reposts bigint unsigned DEFAULT NULL,
                measured_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY post_time (post_id,measured_at),
                KEY measured (measured_at)",
        );
    }

    private static function counter( $item, $key ) {
        $value = $item[ $key ] ?? null;
        if ( is_array( $value ) ) {
            $value = $value['count'] ?? null;
        }
        return is_numeric( $value ) ? max( 0, (int) $value ) : null;
    }

    // Самое широкое превью среди фото, кадров видео, обложек ссылок и товаров.
    private static function preview( $attachments ) {
        $best = array( 'width' => -1, 'url' => '' );
        foreach ( $attachments as $attachment ) {
            $type = $attachment['type'] ?? '';
            $sizes = array();
            if ( 'photo' === $type ) {
                $sizes = (array) ( $attachment['photo']['sizes'] ?? array() );
            } elseif ( 'video' === $type ) {
                $sizes = array_merge( (array) ( $attachment['video']['image'] ?? array() ), (array) ( $attachment['video']['first_frame'] ?? array() ) );
            } elseif ( 'link' === $type ) {
                $sizes = (array) ( $attachment['link']['photo']['sizes'] ?? array() );
            } elseif ( 'market' === $type ) {
                $thumb = (string) ( $attachment['market']['thumb_photo'] ?? '' );
                $sizes = '' === $thumb ? array() : array( array( 'width' => 200, 'url' => $thumb ) );
            } elseif ( 'doc' === $type ) {
                $sizes = (array) ( $attachment['doc']['preview']['photo']['sizes'] ?? array() );
            }
            foreach ( $sizes as $size ) {
                $width = (int) ( $size['width'] ?? 0 );
                if ( ! empty( $size['url'] ) && $width > $best['width'] ) {
                    $best = array( 'width' => $width, 'url' => (string) $size['url'] );
                }
            }
        }
        return $best['url'];
    }

    public static function from_text( $text ) {
        return VKT_Commerce::from_text( $text );
    }

    private static function register_products( $data ) {
        $cards = json_decode( $data['cards'], true ) ?: array();
        $data['link_id'] = null;
        foreach ( $cards as &$card ) {
            $card['link_id'] = VKT_Links::register( $card['url'] ) ?: null;
            if ( $card['url'] === $data['link_url'] ) { $data['link_id'] = $card['link_id']; }
        }
        unset( $card );
        $data['cards'] = $cards ? wp_json_encode( $cards ) : '';
        return $data;
    }

    private static function index_products( $id, $data ) {
        global $wpdb;
        $table = VKT_Store::table( 'post_products' );
        if ( false === $wpdb->delete( $table, array( 'post_id' => $id ) ) ) { return false; }
        foreach ( json_decode( $data['cards'], true ) ?: array() as $position => $card ) {
            if ( false === $wpdb->insert( $table, array(
                'post_id' => $id, 'item_index' => $position, 'link_id' => $card['link_id'],
                'title' => $card['title'], 'price' => $card['price'], 'recognized' => $card['recognized'] ? 1 : 0,
                'shop' => $card['shop'] ?: ( $card['market_id'] ? 'VK Маркет' : '' ),
            ) ) ) { return false; }
        }
        return true;
    }

    /** Reparse saved fields in bounded batches, without changing counters or measurements. */
    public static function reextract( $limit = 100 ) {
        global $wpdb;
        $table = VKT_Store::table( 'posts' );
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE commerce_version<%d ORDER BY id LIMIT %d", VKT_Commerce::VERSION, max( 1, min( 500, (int) $limit ) ) ), ARRAY_A );
        foreach ( $rows as $row ) {
            $item = array( 'text' => $row['text'], 'attachments' => array() );
            if ( $row['market_id'] && preg_match( '/^(-?\d+)_(\d+)$/', $row['market_id'], $m ) ) {
                $item['attachments'][] = array( 'type' => 'market', 'market' => array(
                    'owner_id' => $m[1], 'id' => $m[2], 'title' => $row['product_title'], 'sku' => $row['product_sku'],
                    'price' => array( 'amount' => null === $row['product_price'] ? null : $row['product_price'] * 100, 'old_amount' => null === $row['product_old_price'] ? null : $row['product_old_price'] * 100 ),
                ) );
            }
            if ( $row['link_url'] ) {
                $link = array( 'url' => $row['link_url'], 'title' => $row['link_title'] );
                if ( ! $row['market_id'] && '' === $row['cards'] && null !== $row['product_price'] ) {
                    $link['product'] = array( 'price' => array( 'amount' => $row['product_price'] * 100 ) );
                }
                $item['attachments'][] = array( 'type' => 'link', 'link' => $link );
                // Legacy rows may have a URL after the 2,000 character stored text limit.
                if ( ! str_contains( $item['text'], $row['link_url'] ) ) { $item['text'] .= "\n" . $row['link_url']; }
            }
            $cards = json_decode( $row['cards'], true );
            if ( is_array( $cards ) ) { $item['pretty_cards'] = $cards; }
            $data = self::register_products( VKT_Commerce::extract( $item ) );
            // An ongoing collector may have refreshed this row since SELECT; do not overwrite it.
            $wpdb->query( 'START TRANSACTION' );
            $updated = $wpdb->update( $table, $data, array( 'id' => (int) $row['id'], 'commerce_version' => (int) $row['commerce_version'] ) );
            $ok = false !== $updated && ( ! $updated || self::index_products( (int) $row['id'], $data ) );
            $wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
        }
        return count( $rows );
    }

    // Опорные замеры для окон динамики. Слишком старый замер не годится:
    // берём только тот, что попал в окно [цель − половина периода, цель].
    private static function deltas( $id, $views ) {
        global $wpdb;
        $table = VKT_Store::table( 'post_snapshots' );
        $result = array( 'growth' => null, 'velocity' => null );
        foreach ( self::WINDOWS as $column => $days ) {
            $result[ $column ] = null;
        }
        if ( null === $views ) {
            return $result;
        }
        $now = time();
        $selects = array();
        foreach ( self::WINDOWS as $column => $days ) {
            $target = $now - $days * DAY_IN_SECONDS;
            $selects[] = $wpdb->prepare(
                "(SELECT views FROM $table WHERE post_id=%d AND views IS NOT NULL AND measured_at<=%s AND measured_at>=%s ORDER BY measured_at DESC LIMIT 1) AS $column",
                $id,
                gmdate( 'Y-m-d H:i:s', $target ),
                gmdate( 'Y-m-d H:i:s', (int) ( $target - $days * DAY_IN_SECONDS / 2 ) )
            );
        }
        // Предыдущий замер для скорости — не моложе минуты, иначе интервал слишком мал.
        $selects[] = $wpdb->prepare(
            "(SELECT views FROM $table WHERE post_id=%d AND views IS NOT NULL AND measured_at<=%s ORDER BY measured_at DESC LIMIT 1) AS prev_views",
            $id, gmdate( 'Y-m-d H:i:s', $now - 60 )
        );
        $selects[] = $wpdb->prepare(
            "(SELECT measured_at FROM $table WHERE post_id=%d AND views IS NOT NULL AND measured_at<=%s ORDER BY measured_at DESC LIMIT 1) AS prev_at",
            $id, gmdate( 'Y-m-d H:i:s', $now - 60 )
        );
        $row = $wpdb->get_row( 'SELECT ' . implode( ',', $selects ), ARRAY_A );
        if ( ! $row ) {
            return $result;
        }
        foreach ( self::WINDOWS as $column => $days ) {
            if ( null !== $row[ $column ] ) {
                $delta = $views - (int) $row[ $column ];
                // Счётчик просмотров у VK не уменьшается: минус означает сбой данных, а не спад.
                $result[ $column ] = $delta >= 0 ? $delta : null;
            }
        }
        if ( null !== $row['prev_views'] && $row['prev_at'] ) {
            $growth = $views - (int) $row['prev_views'];
            $hours = ( $now - strtotime( $row['prev_at'] . ' UTC' ) ) / 3600;
            $result['growth'] = $growth;
            $result['velocity'] = $growth >= 0 && $hours > 0 ? round( $growth / $hours, 2 ) : null;
        }
        return $result;
    }

    /**
     * Сохраняет пост и его замер. $members — подписчики сообщества для расчёта виральности.
     * Возвращает id строки или WP_Error.
     */
    public static function save( $item, $source_id = 0, $members = null ) {
        global $wpdb;
        if ( empty( $item['owner_id'] ) || empty( $item['id'] ) ) {
            return new WP_Error( 'empty_post', 'VK вернул пост без идентификатора.', array( 'status' => 422 ) );
        }
        $posts = VKT_Store::table( 'posts' );
        $snapshots = VKT_Store::table( 'post_snapshots' );
        $now = gmdate( 'Y-m-d H:i:s' );
        // У репоста контент лежит в copy_history, а счётчики остаются у внешнего поста.
        $origin = (array) ( $item['copy_history'][0] ?? array() );
        $text = trim( (string) ( $item['text'] ?? '' ) );
        if ( '' === $text && $origin ) {
            $text = trim( (string) ( $origin['text'] ?? '' ) );
        }
        $attachments = array_merge( (array) ( $item['attachments'] ?? array() ), (array) ( $origin['attachments'] ?? array() ) );
        // Репост клипа часто идёт вовсе без текста: подставляем название вложения, чтобы карточка не была пустой.
        $fallback = '';
        if ( '' === $text ) {
            foreach ( $attachments as $attachment ) {
                $type = $attachment['type'] ?? '';
                $candidate = (string) ( $attachment[ $type ]['title'] ?? '' );
                if ( '' === $candidate && 'video' === $type ) {
                    $candidate = (string) ( $attachment['video']['description'] ?? '' );
                }
                if ( '' !== trim( $candidate ) ) {
                    $fallback = trim( $candidate );
                    break;
                }
            }
            $text = $fallback;
        }
        $types = array();
        foreach ( $attachments as $attachment ) {
            $type = sanitize_key( $attachment['type'] ?? '' );
            if ( $type ) {
                $types[ $type ] = true;
            }
        }
        $data = array(
            'owner_id' => (int) $item['owner_id'],
            'post_id' => (int) $item['id'],
            'from_id' => (int) ( $item['from_id'] ?? $item['owner_id'] ),
            'post_type' => sanitize_key( $item['post_type'] ?? 'post' ),
            'is_ad' => empty( $item['marked_as_ads'] ) ? 0 : 1,
            'is_pinned' => empty( $item['is_pinned'] ) ? 0 : 1,
            'is_repost' => $origin ? 1 : 0,
            // Текст поста хранится усечённым: карточке хватает превью, а база не раздувается.
            'text' => mb_substr( wp_strip_all_tags( $text ), 0, 2000 ),
            'thumbnail' => esc_url_raw( self::preview( $attachments ), array( 'https' ) ),
            'media' => implode( ',', array_slice( array_keys( $types ), 0, 8 ) ),
            'media_count' => count( $attachments ),
            'published_at' => empty( $item['date'] ) ? null : gmdate( 'Y-m-d H:i:s', (int) $item['date'] ),
            'measured_at' => $now,
            // Пометка «текст подставлен из вложения» помогает отличить его от настоящего текста поста.
            'text_source' => '' === trim( (string) ( $item['text'] ?? '' ) ) && '' !== $fallback ? 'attachment' : 'post',
        );
        $data = array_merge( $data, self::register_products( VKT_Commerce::extract( $item ) ) );
        if ( $source_id ) {
            $data['source_id'] = (int) $source_id;
        }
        foreach ( array( 'views', 'likes', 'comments', 'reposts' ) as $key ) {
            $data[ $key ] = self::counter( $item, $key );
        }
        $wpdb->query( 'START TRANSACTION' );
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO $posts (owner_id,post_id,text,thumbnail,link_url,cards,measured_at) VALUES (%d,%d,'','','','',%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            $data['owner_id'], $data['post_id'], $now
        ) );
        $id = (int) $wpdb->insert_id;
        if ( false === $result || ! $id ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_write', 'Не удалось сохранить пост.', array( 'status' => 500 ) );
        }
        $data = array_merge( $data, self::deltas( $id, $data['views'] ) );
        // ERR — вовлечение к охвату, виральность — охват к числу подписчиков.
        $engagement = array_filter( array( $data['likes'], $data['comments'], $data['reposts'] ), 'is_int' );
        $data['err'] = $data['views'] && $engagement ? round( array_sum( $engagement ) / $data['views'] * 100, 3 ) : null;
        $data['viral'] = $data['views'] && $members ? round( $data['views'] / $members, 3 ) : null;
        $sample = array( 'post_id' => $id, 'measured_at' => $now );
        foreach ( array( 'views', 'likes', 'comments', 'reposts' ) as $key ) {
            $sample[ $key ] = $data[ $key ];
        }
        // Пост, который перестал расти, не должен плодить одинаковые строки истории:
        // пока счётчики не менялись, новую точку пишем не чаще раза в шесть часов.
        $last = $wpdb->get_row( $wpdb->prepare( "SELECT views,likes,comments,reposts,measured_at FROM $snapshots WHERE post_id=%d ORDER BY measured_at DESC LIMIT 1", $id ), ARRAY_A );
        $unchanged = $last && strtotime( $last['measured_at'] . ' UTC' ) > time() - 6 * HOUR_IN_SECONDS;
        foreach ( array( 'views', 'likes', 'comments', 'reposts' ) as $key ) {
            $unchanged = $unchanged && ( null === $last[ $key ] ? null === $sample[ $key ] : (int) $last[ $key ] === $sample[ $key ] );
        }
        if ( false === $wpdb->update( $posts, $data, array( 'id' => $id ) ) || ! self::index_products( $id, $data ) || ( ! $unchanged && false === $wpdb->replace( $snapshots, $sample ) ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_write', 'Не удалось сохранить замер поста.', array( 'status' => 500 ) );
        }
        $wpdb->query( 'COMMIT' );
        return $id;
    }

    /**
     * Прореживание истории: до 48 часов держим все замеры, до 7 дней — один в час,
     * дальше — один в сутки. Так динамика первых суток остаётся точной, а база не растёт линейно.
     */
    public static function prune() {
        global $wpdb;
        $table = VKT_Store::table( 'post_snapshots' );
        $removed = 0;
        $steps = array(
            array( 'before' => time() - 2 * DAY_IN_SECONDS, 'format' => '%%Y%%m%%d%%H' ),
            array( 'before' => time() - 7 * DAY_IN_SECONDS, 'format' => '%%Y%%m%%d' ),
        );
        foreach ( $steps as $step ) {
            $limit = gmdate( 'Y-m-d H:i:s', $step['before'] );
            // Оставляем последний замер каждой группы, остальные удаляем пачкой.
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT s.id FROM $table s
                LEFT JOIN (SELECT MAX(id) AS id FROM $table WHERE measured_at<%s GROUP BY post_id,DATE_FORMAT(measured_at,'{$step['format']}')) k ON k.id=s.id
                WHERE s.measured_at<%s AND k.id IS NULL LIMIT 5000",
                $limit, $limit
            ) );
            if ( $ids ) {
                $removed += (int) $wpdb->query( "DELETE FROM $table WHERE id IN (" . implode( ',', array_map( 'absint', $ids ) ) . ')' );
            }
        }
        return $removed;
    }

    // Диапазоны фильтров приходят из интерфейса парами min/max по одной метрике.
    private static function range( $wpdb, $filters, $key, $column ) {
        $where = array();
        foreach ( array( 'min' => '>=', 'max' => '<=' ) as $bound => $operator ) {
            $value = $filters[ $key . '_' . $bound ] ?? null;
            if ( is_numeric( $value ) ) {
                $where[] = $wpdb->prepare( "$column $operator %f", (float) $value );
            }
        }
        return $where;
    }

    /**
     * Выборка постов с фильтрами витрины. $args: page, search, sort, source, filters, flags.
     */
    public static function query( $args = array() ) {
        global $wpdb;
        self::reextract();
        $posts = VKT_Store::table( 'posts' );
        $sources = VKT_Store::table( 'sources' );
        $links = VKT_Store::table( 'shop_links' );
        $products = VKT_Store::table( 'post_products' );
        $product_from = "FROM $products pp LEFT JOIN $links pl ON pl.id=pp.link_id WHERE pp.post_id=p.id";
        $recognized = "EXISTS (SELECT 1 $product_from AND (pp.recognized=1 OR pl.status='ok'))";
        $per_page = 24;
        $page = max( 1, (int) ( $args['page'] ?? 1 ) );
        $filters = is_array( $args['filters'] ?? null ) ? $args['filters'] : array();
        // Посты общие, а видны только из своих источников.
        $mine = VKT_Subscriptions::sources_sql();
        $where = array( "p.source_id IN ($mine)" );
        $search = trim( (string) ( $args['search'] ?? '' ) );
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = $wpdb->prepare( "(p.text LIKE %s OR s.title LIKE %s OR EXISTS (SELECT 1 $product_from AND (pp.title LIKE %s OR pl.title LIKE %s)))", $like, $like, $like, $like );
        }
        if ( ! empty( $args['source'] ) ) {
            $where[] = $wpdb->prepare( 'p.source_id=%d', (int) $args['source'] );
        }
        foreach ( array( 'velocity' => 'p.velocity', 'day' => 'p.g1', 'views' => 'p.views', 'members' => 's.members', 'err' => 'p.err', 'viral' => 'p.viral' ) as $key => $column ) {
            $where = array_merge( $where, self::range( $wpdb, $filters, $key, $column ) );
        }
        // All product filters must match the SAME attached item, including secondary links.
        $price_column = "COALESCE(CASE WHEN pl.status='ok' THEN pl.price END,pp.price)";
        $product_where = self::range( $wpdb, $filters, 'price', $price_column );
        if ( ! empty( $filters['with_price'] ) ) { $product_where[] = "$price_column IS NOT NULL"; }
        if ( ! empty( $filters['shop'] ) && is_string( $filters['shop'] ) ) {
            $product_where[] = $wpdb->prepare( "COALESCE(NULLIF(pl.shop,''),pp.shop)=%s", sanitize_text_field( $filters['shop'] ) );
        }
        if ( ! empty( $filters['recognized'] ) ) { $product_where[] = "(pp.recognized=1 OR pl.status='ok')"; }
        if ( $product_where || ! empty( $filters['with_product'] ) ) {
            $where[] = "EXISTS (SELECT 1 $product_from" . ( $product_where ? ' AND ' . implode( ' AND ', $product_where ) : '' ) . ')';
        }
        if ( ! empty( $filters['no_ads'] ) ) {
            $where[] = 'p.is_ad=0';
        }
        if ( ! empty( $filters['period'] ) && is_numeric( $filters['period'] ) ) {
            $where[] = $wpdb->prepare( 'p.published_at>=%s', gmdate( 'Y-m-d H:i:s', time() - (int) $filters['period'] * DAY_IN_SECONDS ) );
        }
        $sorts = array(
            'velocity' => 'p.velocity', 'views' => 'p.views', 'g1' => 'p.g1', 'g3' => 'p.g3', 'g7' => 'p.g7', 'g30' => 'p.g30',
            'err' => 'p.err', 'viral' => 'p.viral', 'likes' => 'p.likes', 'comments' => 'p.comments', 'published_at' => 'p.published_at', 'measured_at' => 'p.measured_at',
        );
        $sort = $sorts[ $args['sort'] ?? 'velocity' ] ?? 'p.velocity';
        $clause = implode( ' AND ', $where );
        $rows = (array) $wpdb->get_results(
            "SELECT p.*,s.title AS source_title,s.value AS source_value,s.members,s.photo,
                l.shop AS link_shop,l.domain AS link_host,l.title AS page_title,l.price AS page_price,l.status AS link_status,l.message AS link_message,l.final_url,l.sku AS shop_sku
            FROM $posts p LEFT JOIN $sources s ON s.id=p.source_id LEFT JOIN $links l ON l.id=p.link_id
            WHERE $clause ORDER BY $sort IS NULL,$sort DESC,p.id DESC LIMIT $per_page OFFSET " . ( ( $page - 1 ) * $per_page ),
            ARRAY_A
        );
        $link_ids = array();
        foreach ( $rows as &$row ) {
            $row['product_items'] = json_decode( $row['cards'], true ) ?: array();
            foreach ( $row['product_items'] as $card ) { if ( ! empty( $card['link_id'] ) ) { $link_ids[] = (int) $card['link_id']; } }
        }
        unset( $row );
        if ( $link_ids ) {
            $ids = implode( ',', array_unique( $link_ids ) );
            $pages = array();
            foreach ( (array) $wpdb->get_results( "SELECT id,title,price,sku,shop,status,message,final_url FROM $links WHERE id IN ($ids)", ARRAY_A ) as $link ) { $pages[ $link['id'] ] = $link; }
            foreach ( $rows as &$row ) {
                foreach ( $row['product_items'] as &$card ) { $card['page'] = $pages[ $card['link_id'] ?? 0 ] ?? null; }
                unset( $card );
            }
            unset( $row );
        }
        $totals = $wpdb->get_row( "SELECT COUNT(*) AS total,COALESCE(SUM(p.views),0) AS views,COALESCE(SUM(p.g1),0) AS day_growth,AVG(p.err) AS err,SUM($recognized) AS recognized_posts FROM $posts p LEFT JOIN $sources s ON s.id=p.source_id LEFT JOIN $links l ON l.id=p.link_id WHERE $clause", ARRAY_A );
        return array(
            'posts' => $rows,
            'reextract_pending' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $posts WHERE commerce_version<%d", VKT_Commerce::VERSION ) ),
            'communities' => (array) $wpdb->get_results( "SELECT id,title,value FROM $sources WHERE kind IN ('owner','domain') AND id IN ($mine) ORDER BY title,value LIMIT 500", ARRAY_A ),
            // Магазины, встречающиеся в собранных ссылках, — для выпадающего фильтра витрины.
            'shops' => (array) $wpdb->get_results( "SELECT COALESCE(NULLIF(l.shop,''),pp.shop) AS shop,COUNT(DISTINCT pp.post_id) AS posts FROM $products pp JOIN $posts p ON p.id=pp.post_id LEFT JOIN $links l ON l.id=pp.link_id WHERE p.source_id IN ($mine) AND COALESCE(NULLIF(l.shop,''),pp.shop)<>'' GROUP BY COALESCE(NULLIF(l.shop,''),pp.shop) ORDER BY posts DESC LIMIT 30", ARRAY_A ),
            // Страницы магазинов из своих постов: справочник ссылок общий на весь сайт.
            'links' => (array) $wpdb->get_row( "SELECT COUNT(*) AS total,SUM(status='ok') AS recognized,SUM(status IN ('pending','manual')) AS waiting,SUM(status IN ('blocked','error','empty')) AS failed FROM $links WHERE id IN (SELECT pp.link_id FROM $products pp JOIN $posts p ON p.id=pp.post_id WHERE p.source_id IN ($mine))", ARRAY_A ),
            'total' => (int) ( $totals['total'] ?? 0 ),
            'summary' => $totals,
            'page' => $page,
            'pages' => (int) ceil( max( 1, (int) ( $totals['total'] ?? 0 ) ) / $per_page ),
        );
    }

    // Сводка по сообществам для отдельной вкладки: агрегаты считаются по колонкам постов.
    // Только свои источники; пауза — своя, из подписки.
    public static function communities() {
        global $wpdb;
        $posts = VKT_Store::table( 'posts' );
        $sources = VKT_Store::table( 'sources' );
        $subscriptions = VKT_Store::table( 'subscriptions' );
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id,s.kind,s.value,s.title,MAX(sub.enabled) AS enabled,s.members,s.photo,s.next_run,s.synced_at,
                COUNT(p.id) AS posts,
                COALESCE(SUM(p.views),0) AS views,
                COALESCE(SUM(p.likes),0) AS likes,
                COALESCE(SUM(p.comments),0) AS comments,
                COALESCE(SUM(p.reposts),0) AS reposts,
                SUM(p.velocity) AS velocity,
                SUM(p.g1) AS g1,SUM(p.g3) AS g3,SUM(p.g7) AS g7,SUM(p.g30) AS g30,
                AVG(p.err) AS err,AVG(p.viral) AS viral,
                MAX(p.published_at) AS last_post,MAX(p.measured_at) AS last_measurement
            FROM $subscriptions sub JOIN $sources s ON s.id=sub.source_id LEFT JOIN $posts p ON p.source_id=s.id
            WHERE sub.user_id=%d
            GROUP BY s.id ORDER BY views DESC,s.id DESC LIMIT 500",
            VKT_Account::id()
        ), ARRAY_A );
    }

    public static function history( $id, $limit = 300 ) {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT views,likes,comments,reposts,measured_at FROM ' . VKT_Store::table( 'post_snapshots' ) . ' WHERE post_id=%d ORDER BY measured_at DESC LIMIT %d',
            $id, max( 10, min( 1000, (int) $limit ) )
        ), ARRAY_A );
        return array_reverse( $rows );
    }
}

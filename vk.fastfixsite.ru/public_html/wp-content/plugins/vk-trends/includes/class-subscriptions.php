<?php
defined( 'ABSPATH' ) || exit;

/**
 * Личные списки поверх общего сбора.
 *
 * Сообщество обходится один раз, сколько бы пользователей его ни добавили:
 * источники, посты и ролики общие, а подписка решает, кто что видит. Так
 * пять человек с одной нишей не умножают ни запросы к VK, ни базу.
 *
 * Ролики видны по отдельному списку user_videos, а не через подписку: их
 * можно добавить вручную, удалить из подборки и оставить после отписки от
 * источника — ровно так, как было до личных кабинетов.
 */
final class VKT_Subscriptions {
    // Потолок общего списка: сборщик берёт два задания в минуту, больше ему не обойти.
    const GLOBAL_LIMIT = 3000;

    public static function schema() {
        return array(
            'subscriptions' => "user_id bigint unsigned NOT NULL,
                source_id bigint unsigned NOT NULL,
                enabled tinyint NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                PRIMARY KEY  (user_id,source_id),
                KEY source (source_id,enabled)",
            'user_videos' => "user_id bigint unsigned NOT NULL,
                video_id bigint unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (user_id,video_id),
                KEY video (video_id)",
        );
    }

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_sources', $message, array( 'status' => $status ) );
    }

    private static function user( $user_id ) {
        return null === $user_id ? VKT_Account::id() : absint( $user_id );
    }

    /** Подзапрос с ID источников пользователя — для IN (...) в выборках постов. */
    public static function sources_sql( $user_id = null ) {
        global $wpdb;
        return $wpdb->prepare( 'SELECT source_id FROM ' . VKT_Store::table( 'subscriptions' ) . ' WHERE user_id=%d', self::user( $user_id ) );
    }

    /** Подзапрос с ID роликов из подборки пользователя. */
    public static function videos_sql( $user_id = null ) {
        global $wpdb;
        return $wpdb->prepare( 'SELECT video_id FROM ' . VKT_Store::table( 'user_videos' ) . ' WHERE user_id=%d', self::user( $user_id ) );
    }

    public static function count( $user_id = null ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'subscriptions' ) . ' WHERE user_id=%d', self::user( $user_id ) ) );
    }

    public static function has_source( $source_id, $user_id = null ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . VKT_Store::table( 'subscriptions' ) . ' WHERE user_id=%d AND source_id=%d', self::user( $user_id ), absint( $source_id ) ) );
    }

    public static function sees_video( $video_id, $user_id = null ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . VKT_Store::table( 'user_videos' ) . ' WHERE user_id=%d AND video_id=%d', self::user( $user_id ), absint( $video_id ) ) );
    }

    public static function sees_post( $post_id, $user_id = null ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT 1 FROM ' . VKT_Store::table( 'posts' ) . ' p JOIN ' . VKT_Store::table( 'subscriptions' ) . ' s ON s.source_id=p.source_id WHERE p.id=%d AND s.user_id=%d',
            absint( $post_id ), self::user( $user_id )
        ) );
    }

    /** Сколько человек следит за источником, кроме указанного. */
    public static function others( $source_id, $user_id = null ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'subscriptions' ) . ' WHERE source_id=%d AND user_id<>%d', absint( $source_id ), self::user( $user_id ) ) );
    }

    /** Ролик в подборке пользователя. */
    public static function claim_video( $video_id, $user_id = null ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . VKT_Store::table( 'user_videos' ) . ' (user_id,video_id,created_at) VALUES (%d,%d,%s)', self::user( $user_id ), absint( $video_id ), gmdate( 'Y-m-d H:i:s' ) ) );
    }

    /** Новый ролик источника сразу попадает в подборки всех его подписчиков. */
    public static function share_video( $video_id, $source_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'INSERT IGNORE INTO ' . VKT_Store::table( 'user_videos' ) . ' (user_id,video_id,created_at) SELECT user_id,%d,%s FROM ' . VKT_Store::table( 'subscriptions' ) . ' WHERE source_id=%d',
            absint( $video_id ), gmdate( 'Y-m-d H:i:s' ), absint( $source_id )
        ) );
    }

    /** Все ролики источника — в подборку пользователя: при подписке и при отписке. */
    private static function adopt_videos( $source_id, $user_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'INSERT IGNORE INTO ' . VKT_Store::table( 'user_videos' ) . ' (user_id,video_id,created_at) SELECT %d,id,%s FROM ' . VKT_Store::table( 'videos' ) . ' WHERE source_id=%d',
            $user_id, gmdate( 'Y-m-d H:i:s' ), absint( $source_id )
        ) );
    }

    /** Флажок обхода у общего источника: включён, пока его хочет хоть один подписчик. */
    public static function refresh( $source_id ) {
        global $wpdb;
        $active = (int) (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . VKT_Store::table( 'subscriptions' ) . ' WHERE source_id=%d AND enabled=1 LIMIT 1', absint( $source_id ) ) );
        $wpdb->update( VKT_Store::table( 'sources' ), array( 'enabled' => $active ), array( 'id' => absint( $source_id ) ) );
    }

    /**
     * Подписывает пользователя на сообщество. Общий источник заводится при
     * первом подписчике, остальные получают уже собранные посты и ролики.
     * Возвращает id источника и признак, добавлен ли он в список пользователя.
     */
    public static function subscribe( $kind, $value, $title = '', $user_id = null ) {
        global $wpdb;
        $user_id = self::user( $user_id );
        if ( ! $user_id ) {
            return self::error( 'Источник добавляется только вошедшему пользователю.', 401 );
        }
        $sources = VKT_Store::table( 'sources' );
        $source_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $sources WHERE kind=%s AND value=%s", $kind, $value ) );
        if ( $source_id && '' !== $title ) {
            $wpdb->update( $sources, array( 'title' => $title ), array( 'id' => $source_id ) );
        }
        if ( $source_id && self::has_source( $source_id, $user_id ) ) {
            return array( 'id' => $source_id, 'added' => false );
        }
        $limit = VKT_Account::source_limit( $user_id );
        if ( self::count( $user_id ) >= $limit ) {
            return self::error( 'Достигнут предел: ' . $limit . ' источников в кабинете. Удалите ненужные или попросите администратора поднять лимит.', 422 );
        }
        if ( ! $source_id ) {
            if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sources" ) >= self::GLOBAL_LIMIT ) {
                return self::error( 'На сайте уже ' . self::GLOBAL_LIMIT . ' источников — сборщик больше не успеет обойти. Обратитесь к администратору.', 422 );
            }
            // Гонка двух подписчиков разрешается уникальным ключом: второй получает тот же id.
            $ok = $wpdb->query( $wpdb->prepare(
                "INSERT INTO $sources (kind,value,title,photo,next_run) VALUES (%s,%s,%s,'',%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                $kind, $value, $title, gmdate( 'Y-m-d H:i:s' )
            ) );
            $source_id = (int) $wpdb->insert_id;
            if ( false === $ok || ! $source_id ) {
                return self::error( 'Не удалось сохранить источник.', 500 );
            }
        }
        $ok = $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . VKT_Store::table( 'subscriptions' ) . ' (user_id,source_id,enabled,created_at) VALUES (%d,%d,1,%s) ON DUPLICATE KEY UPDATE enabled=1',
            $user_id, $source_id, gmdate( 'Y-m-d H:i:s' )
        ) );
        if ( false === $ok ) {
            return self::error( 'Не удалось добавить источник в кабинет.', 500 );
        }
        self::refresh( $source_id );
        self::adopt_videos( $source_id, $user_id );
        return array( 'id' => $source_id, 'added' => true );
    }

    public static function toggle( $source_id, $enabled, $user_id = null ) {
        global $wpdb;
        $user_id = self::user( $user_id );
        $source_id = absint( $source_id );
        if ( ! self::has_source( $source_id, $user_id ) ) {
            return self::error( 'Источник не найден в вашем кабинете.', 404 );
        }
        $result = $wpdb->update( VKT_Store::table( 'subscriptions' ), array( 'enabled' => $enabled ? 1 : 0 ), array( 'user_id' => $user_id, 'source_id' => $source_id ) );
        if ( false === $result ) {
            return self::error( 'Не удалось изменить источник.', 500 );
        }
        self::refresh( $source_id );
        return array( 'ok' => true );
    }

    /**
     * Отписка. Ролики источника остаются в подборке — так было и при удалении
     * источника раньше. Последний подписчик уносит источник целиком вместе
     * с постами: наблюдать за ним больше некому.
     */
    public static function unsubscribe( $source_id, $user_id = null ) {
        global $wpdb;
        $user_id = self::user( $user_id );
        $source_id = absint( $source_id );
        if ( ! self::has_source( $source_id, $user_id ) ) {
            return self::error( 'Источник не найден в вашем кабинете.', 404 );
        }
        self::adopt_videos( $source_id, $user_id );
        $wpdb->delete( VKT_Store::table( 'subscriptions' ), array( 'user_id' => $user_id, 'source_id' => $source_id ) );
        if ( self::others( $source_id, 0 ) ) {
            self::refresh( $source_id );
        } else {
            self::drop_source( $source_id );
        }
        return array( 'ok' => true );
    }

    /** Источник без подписчиков: посты и их история уходят, ролики остаются у тех, кто их сохранил. */
    public static function drop_source( $source_id ) {
        global $wpdb;
        $source_id = absint( $source_id );
        $post_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'posts' ) . ' WHERE source_id=%d', $source_id ) );
        foreach ( array_chunk( array_map( 'absint', $post_ids ), 500 ) as $chunk ) {
            $list = implode( ',', $chunk );
            $wpdb->query( 'DELETE FROM ' . VKT_Store::table( 'post_snapshots' ) . " WHERE post_id IN ($list)" );
            $wpdb->query( 'DELETE FROM ' . VKT_Store::table( 'post_products' ) . " WHERE post_id IN ($list)" );
            $wpdb->query( 'DELETE FROM ' . VKT_Store::table( 'posts' ) . " WHERE id IN ($list)" );
        }
        $wpdb->delete( VKT_Store::table( 'jobs' ), array( 'job_key' => 'source:' . $source_id ) );
        $wpdb->delete( VKT_Store::table( 'sources' ), array( 'id' => $source_id ) );
    }

    /**
     * Убирает ролик из подборки пользователя. Если он больше ни у кого не
     * числится, удаляется целиком с историей замеров — иначе сборщик
     * продолжал бы мерить ролик, который никто не смотрит.
     */
    public static function release_video( $video_id, $user_id = null ) {
        global $wpdb;
        $user_id = self::user( $user_id );
        $video_id = absint( $video_id );
        $wpdb->delete( VKT_Store::table( 'user_videos' ), array( 'user_id' => $user_id, 'video_id' => $video_id ) );
        // Связи с товарами личные: снимаем только свои.
        $wpdb->query( $wpdb->prepare(
            'DELETE l FROM ' . VKT_Store::table( 'links' ) . ' l JOIN ' . VKT_Store::table( 'products' ) . ' p ON p.id=l.product_id WHERE l.video_id=%d AND p.user_id=%d',
            $video_id, $user_id
        ) );
        if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . VKT_Store::table( 'user_videos' ) . ' WHERE video_id=%d LIMIT 1', $video_id ) ) ) {
            return false;
        }
        $wpdb->delete( VKT_Store::table( 'snapshots' ), array( 'video_id' => $video_id ) );
        $wpdb->delete( VKT_Store::table( 'links' ), array( 'video_id' => $video_id ) );
        $wpdb->delete( VKT_Store::table( 'jobs' ), array( 'job_key' => 'video:' . $video_id ) );
        $wpdb->delete( VKT_Store::table( 'videos' ), array( 'id' => $video_id ) );
        return true;
    }

    /** Удалённый пользователь не держит источники и ролики, за которыми больше никто не следит. */
    public static function purge_user( $user_id ) {
        global $wpdb;
        $user_id = absint( $user_id );
        $sources = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT source_id FROM ' . VKT_Store::table( 'subscriptions' ) . ' WHERE user_id=%d', $user_id ) ) );
        $wpdb->delete( VKT_Store::table( 'subscriptions' ), array( 'user_id' => $user_id ) );
        foreach ( $sources as $source_id ) {
            if ( self::others( $source_id, 0 ) ) {
                self::refresh( $source_id );
            } else {
                self::drop_source( $source_id );
            }
        }
        $videos = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT video_id FROM ' . VKT_Store::table( 'user_videos' ) . ' WHERE user_id=%d', $user_id ) ) );
        foreach ( $videos as $video_id ) {
            self::release_video( $video_id, $user_id );
        }
    }
}

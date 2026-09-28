<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ответы на комментарии в своих сообществах.
 *
 * Читать стену и комментарии VK разрешает пользовательскому и сервисному
 * ключу, но не ключу сообщества. Отвечать (wall.createComment) — наоборот,
 * пользовательскому и ключу сообщества. Поэтому чтение идёт общим ключом
 * сбора, а отправка — ключом сообщества там, где он есть, иначе токеном
 * пользователя от имени группы.
 *
 * Любой ответ, даже одиночный, проходит через очередь: это единственное
 * место, где соблюдается пауза между ответами в одну группу.
 */
final class VKT_Replies {
    const MAX_ATTEMPTS = 4;
    const MAX_BATCH = 50;
    const MAX_LENGTH = 2000;
    // Пачка ответов в одну группу подряд выглядит для VK как флуд и
    // заканчивается капчей, поэтому cron отправляет в группу не чаще раза в минуту.
    const MIN_GAP = 60;
    const MAX_INTERVAL = 180;

    public static function schema() {
        return array(
            'comment_replies' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL DEFAULT 0,
                group_id bigint unsigned NOT NULL,
                post_id bigint unsigned NOT NULL,
                comment_id bigint unsigned NOT NULL,
                author_id bigint NOT NULL DEFAULT 0,
                author_name varchar(255) NOT NULL DEFAULT '',
                comment_text text NOT NULL,
                message text NOT NULL,
                origin varchar(20) NOT NULL DEFAULT 'manual',
                status varchar(20) NOT NULL DEFAULT 'pending',
                attempts int unsigned NOT NULL DEFAULT 0,
                available_at datetime NOT NULL,
                vk_comment_id bigint unsigned DEFAULT NULL,
                guid varchar(64) NOT NULL,
                error varchar(255) NOT NULL DEFAULT '',
                sent_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY ready (status,available_at),
                KEY author (user_id,id),
                KEY target (user_id,group_id,post_id,comment_id)",
        );
    }

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_replies', $message, array( 'status' => $status ) );
    }

    /** Группа из своего кабинета, куда есть право записи. Чужую не читаем и не трогаем. */
    private static function group( $group_id ) {
        global $wpdb;
        $group = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND group_id=%d AND enabled=1 AND can_post=1',
            VKT_Account::id(), absint( $group_id )
        ), ARRAY_A );
        return $group ? $group : self::error( 'Сообщество не найдено среди ваших включённых групп. Обновите их в «Автопостинге».', 404 );
    }

    /** Чем будет отправлен ответ в группу: от этого зависит, что показать в интерфейсе. */
    private static function sender( $group_id ) {
        if ( VKT_Community::has_key( $group_id ) ) {
            return 'community';
        }
        return VKT_Tokens::has( 'user' ) ? 'user' : '';
    }

    public static function state() {
        global $wpdb;
        $user_id = VKT_Account::id();
        $groups = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT id,group_id,name,screen_name,photo FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND enabled=1 AND can_post=1 ORDER BY name,id LIMIT 1000',
            $user_id
        ), ARRAY_A );
        foreach ( $groups as &$group ) {
            $group['sender'] = self::sender( $group['group_id'] );
        }
        unset( $group );
        $queue = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT r.id,r.group_id,r.post_id,r.comment_id,r.author_name,r.comment_text,r.message,r.origin,r.status,r.attempts,r.available_at,r.vk_comment_id,r.error,r.sent_at,g.name,g.screen_name
             FROM ' . VKT_Store::table( 'comment_replies' ) . ' r LEFT JOIN ' . VKT_Store::table( 'publishing_groups' ) . ' g ON g.group_id=r.group_id AND g.user_id=r.user_id
             WHERE r.user_id=%d ORDER BY r.status IN (\'pending\',\'sending\') DESC,CASE WHEN r.status IN (\'pending\',\'sending\') THEN r.available_at END,r.updated_at DESC,r.id DESC LIMIT 150',
            $user_id
        ), ARRAY_A );
        return array(
            'groups' => $groups,
            'queue' => $queue,
            'status' => array(
                // Читает ключ сбора: без него не будет ни постов, ни комментариев.
                'reading' => '' !== VKT_API::mode(),
                'ai' => VKT_AI::public_status(),
                'min_gap' => self::MIN_GAP,
                'max_batch' => self::MAX_BATCH,
                'max_length' => self::MAX_LENGTH,
                'last' => get_option( 'vkt_last_reply_run', null ),
            ),
        );
    }

    /** Последние записи стены группы с числом комментариев. */
    public static function posts( $group_id, $offset = 0 ) {
        $group = self::group( $group_id );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        $result = VKT_API::request( 'wall.get', array(
            'owner_id' => -absint( $group['group_id'] ),
            'count' => 20,
            'offset' => max( 0, min( 5000, absint( $offset ) ) ),
        ), 'comments' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $posts = array();
        foreach ( (array) ( $result['response']['items'] ?? array() ) as $item ) {
            if ( ! is_array( $item ) || ! absint( $item['id'] ?? 0 ) ) {
                continue;
            }
            $posts[] = array(
                'id' => absint( $item['id'] ),
                'date' => gmdate( 'Y-m-d H:i:s', absint( $item['date'] ?? 0 ) ),
                'text' => mb_substr( sanitize_textarea_field( (string) ( $item['text'] ?? '' ) ), 0, 600 ),
                'comments' => absint( $item['comments']['count'] ?? 0 ),
                // Комментарии могут быть закрыты у записи или во всём сообществе.
                'can_comment' => ! isset( $item['comments']['can_post'] ) || ! empty( $item['comments']['can_post'] ),
                'is_pinned' => ! empty( $item['is_pinned'] ),
                'thumb' => self::thumb( $item ),
            );
        }
        return array(
            'posts' => $posts,
            'total' => absint( $result['response']['count'] ?? count( $posts ) ),
            'offset' => absint( $offset ),
        );
    }

    /** Мелкое превью первой фотографии записи, чтобы пост узнавался в списке. */
    private static function thumb( $item ) {
        foreach ( (array) ( $item['attachments'] ?? array() ) as $attachment ) {
            if ( 'photo' !== ( $attachment['type'] ?? '' ) ) {
                continue;
            }
            $sizes = (array) ( $attachment['photo']['sizes'] ?? array() );
            usort( $sizes, static fn( $a, $b ) => ( $a['width'] ?? 0 ) <=> ( $b['width'] ?? 0 ) );
            foreach ( $sizes as $size ) {
                if ( ( $size['width'] ?? 0 ) >= 130 ) {
                    return esc_url_raw( (string) ( $size['url'] ?? '' ), array( 'https' ) );
                }
            }
        }
        return '';
    }

    /**
     * Комментарии записи вместе с ветками. Ответ сообщества в ветке помечает
     * комментарий как отвеченный, а очередь — как уже поставленный в работу.
     */
    public static function thread( $group_id, $post_id, $offset = 0 ) {
        global $wpdb;
        $group = self::group( $group_id );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        $group_id = absint( $group['group_id'] );
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return self::error( 'Не указана запись.' );
        }
        $result = VKT_API::request( 'wall.getComments', array(
            'owner_id' => -$group_id,
            'post_id' => $post_id,
            'count' => 100,
            'offset' => max( 0, min( 10000, absint( $offset ) ) ),
            'sort' => 'desc',
            'extended' => 1,
            'fields' => 'photo_50',
            'thread_items_count' => 10,
            // По умолчанию VK обрезает текст до 90 символов.
            'preview_length' => 0,
        ), 'comments' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $response = (array) ( $result['response'] ?? array() );
        $people = array();
        foreach ( (array) ( $response['profiles'] ?? array() ) as $profile ) {
            $people[ absint( $profile['id'] ?? 0 ) ] = array(
                'name' => trim( sanitize_text_field( ( $profile['first_name'] ?? '' ) . ' ' . ( $profile['last_name'] ?? '' ) ) ),
                'photo' => esc_url_raw( (string) ( $profile['photo_50'] ?? '' ), array( 'https' ) ),
            );
        }
        foreach ( (array) ( $response['groups'] ?? array() ) as $community ) {
            $people[ -absint( $community['id'] ?? 0 ) ] = array(
                'name' => sanitize_text_field( (string) ( $community['name'] ?? '' ) ),
                'photo' => esc_url_raw( (string) ( $community['photo_50'] ?? '' ), array( 'https' ) ),
            );
        }
        // Что уже в очереди по этой записи: второй раз на тот же комментарий не ставим.
        $queued = array();
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT comment_id,status FROM ' . VKT_Store::table( 'comment_replies' ) . " WHERE user_id=%d AND group_id=%d AND post_id=%d AND status IN ('pending','sending','sent','failed') ORDER BY id",
            VKT_Account::id(), $group_id, $post_id
        ), ARRAY_A ) as $row ) {
            $queued[ absint( $row['comment_id'] ) ] = $row['status'];
        }
        $map = static function ( $item ) use ( $people, $queued, $group_id ) {
            $from = (int) ( $item['from_id'] ?? 0 );
            $id = absint( $item['id'] ?? 0 );
            return array(
                'id' => $id,
                'from_id' => $from,
                'author' => $people[ $from ]['name'] ?? ( $from < 0 ? 'Сообщество' : 'id' . $from ),
                'photo' => $people[ $from ]['photo'] ?? '',
                'date' => gmdate( 'Y-m-d H:i:s', absint( $item['date'] ?? 0 ) ),
                'text' => mb_substr( sanitize_textarea_field( (string) ( $item['text'] ?? '' ) ), 0, 4000 ),
                // Комментарий без текста — стикер, фото или удалённый.
                'has_media' => ! empty( $item['attachments'] ),
                'deleted' => ! empty( $item['deleted'] ),
                'is_group' => -$group_id === $from,
                'queued' => $queued[ $id ] ?? '',
            );
        };
        $comments = array();
        foreach ( (array) ( $response['items'] ?? array() ) as $item ) {
            if ( ! is_array( $item ) || ! absint( $item['id'] ?? 0 ) ) {
                continue;
            }
            $comment = $map( $item );
            $replies = array_map( $map, array_filter( (array) ( $item['thread']['items'] ?? array() ), 'is_array' ) );
            $comment['thread'] = array_values( $replies );
            $comment['thread_count'] = absint( $item['thread']['count'] ?? count( $replies ) );
            $comment['answered'] = (bool) array_filter( $replies, static fn( $reply ) => $reply['is_group'] );
            $comments[] = $comment;
        }
        return array(
            'post_id' => $post_id,
            'comments' => $comments,
            'total' => absint( $response['current_level_count'] ?? $response['count'] ?? count( $comments ) ),
            'offset' => absint( $offset ),
        );
    }

    private static function message( $value ) {
        $value = is_string( $value ) ? trim( sanitize_textarea_field( $value ) ) : '';
        if ( '' === $value ) {
            return self::error( 'Пустой ответ не отправляется.' );
        }
        return mb_strlen( $value ) > self::MAX_LENGTH ? self::error( 'Ответ длиннее ' . self::MAX_LENGTH . ' символов.' ) : $value;
    }

    /**
     * Ставит ответы в очередь. Интервал в минутах: между ответами в одну группу
     * не меньше него, плюс случайная добавка до половины интервала, чтобы
     * ответы не шли ровным метрономом. Новая пачка встаёт после уже
     * ожидающих ответов этой группы, а не параллельно им.
     */
    public static function enqueue( $data ) {
        global $wpdb;
        $group = self::group( $data['group_id'] ?? 0 );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        if ( '' === self::sender( $group['group_id'] ) ) {
            return self::error( 'Отвечать нечем: подключите ключ этого сообщества или пользовательский токен в разделе «Публикация».' );
        }
        $items = is_array( $data['items'] ?? null ) ? array_values( $data['items'] ) : array();
        if ( ! $items ) {
            return self::error( 'Не выбрано ни одного комментария.' );
        }
        if ( count( $items ) > self::MAX_BATCH ) {
            return self::error( 'За один раз можно поставить не больше ' . self::MAX_BATCH . ' ответов.' );
        }
        $interval = max( 0, min( self::MAX_INTERVAL, absint( $data['interval'] ?? 0 ) ) ) * MINUTE_IN_SECONDS;
        $jitter = ! empty( $data['jitter'] );
        $table = VKT_Store::table( 'comment_replies' );
        $user_id = VKT_Account::id();
        $group_id = absint( $group['group_id'] );
        $start = time();
        if ( ! empty( $data['start_at'] ) ) {
            $wanted = strtotime( (string) $data['start_at'] );
            if ( false === $wanted ) {
                return self::error( 'Неверное время начала.' );
            }
            $start = max( $start, $wanted );
        }
        $last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(available_at) FROM $table WHERE user_id=%d AND group_id=%d AND status IN ('pending','sending')", $user_id, $group_id ) );
        if ( $last && $interval ) {
            $start = max( $start, strtotime( $last . ' UTC' ) + $interval );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $created = array();
        $skipped = array();
        $at = $start;
        foreach ( $items as $index => $item ) {
            $post_id = absint( is_array( $item ) ? ( $item['post_id'] ?? 0 ) : 0 );
            $comment_id = absint( is_array( $item ) ? ( $item['comment_id'] ?? 0 ) : 0 );
            $message = self::message( is_array( $item ) ? ( $item['message'] ?? '' ) : '' );
            if ( ! $post_id || ! $comment_id || is_wp_error( $message ) ) {
                $skipped[] = array( 'index' => (int) $index, 'error' => is_wp_error( $message ) ? $message->get_error_message() : 'Не указан комментарий.' );
                continue;
            }
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE user_id=%d AND group_id=%d AND post_id=%d AND comment_id=%d AND status IN ('pending','sending','sent') LIMIT 1",
                $user_id, $group_id, $post_id, $comment_id
            ) );
            if ( $exists ) {
                $skipped[] = array( 'index' => (int) $index, 'error' => 'На этот комментарий ответ уже в очереди или отправлен.' );
                continue;
            }
            $inserted = $wpdb->insert( $table, array(
                'user_id' => $user_id,
                'group_id' => $group_id,
                'post_id' => $post_id,
                'comment_id' => $comment_id,
                'author_id' => (int) ( $item['author_id'] ?? 0 ),
                'author_name' => mb_substr( sanitize_text_field( (string) ( $item['author'] ?? '' ) ), 0, 255 ),
                'comment_text' => mb_substr( sanitize_textarea_field( (string) ( $item['comment_text'] ?? '' ) ), 0, 300 ),
                'message' => $message,
                'origin' => 'ai' === ( $item['origin'] ?? '' ) ? 'ai' : 'manual',
                'status' => 'pending',
                'available_at' => gmdate( 'Y-m-d H:i:s', $at ),
                // guid делает повтор после обрыва связи безопасным: VK не создаст второй такой же комментарий.
                'guid' => substr( hash( 'sha256', wp_generate_uuid4() ), 0, 32 ),
                'created_at' => $now,
                'updated_at' => $now,
            ) );
            if ( ! $inserted ) {
                $skipped[] = array( 'index' => (int) $index, 'error' => 'Не удалось записать ответ в очередь.' );
                continue;
            }
            $created[] = array( 'id' => (int) $wpdb->insert_id, 'available_at' => gmdate( 'Y-m-d H:i:s', $at ) );
            $at += $interval + ( $jitter && $interval ? wp_rand( 0, (int) ( $interval / 2 ) ) : 0 );
        }
        if ( ! $created ) {
            return self::error( 'Ни один ответ не поставлен. Первая причина: ' . ( $skipped[0]['error'] ?? 'неизвестна' ), 422 );
        }
        return array(
            'created' => count( $created ),
            'replies' => $created,
            'skipped' => $skipped,
            'first_at' => $created[0]['available_at'],
            'last_at' => $created[ count( $created ) - 1 ]['available_at'],
        );
    }

    /** Одиночный ответ: встаёт в очередь и тут же уходит, если группа не ждёт паузы. */
    public static function reply_now( $data ) {
        $data['interval'] = 0;
        $data['start_at'] = '';
        $queued = self::enqueue( $data );
        if ( is_wp_error( $queued ) ) {
            return $queued;
        }
        $id = absint( $queued['replies'][0]['id'] ?? 0 );
        $run = self::run_due( 1, $id );
        return array(
            'id' => $id,
            'status' => self::status_of( $id ),
            'message' => is_wp_error( $run ) ? $run->get_error_message() : $run['message'],
        );
    }

    private static function status_of( $id ) {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . VKT_Store::table( 'comment_replies' ) . ' WHERE id=%d', absint( $id ) ) );
    }

    /** Отменяет ожидающие ответы: выбранные или все свои. */
    public static function cancel( $ids, $all = false ) {
        global $wpdb;
        $table = VKT_Store::table( 'comment_replies' );
        $now = gmdate( 'Y-m-d H:i:s' );
        if ( $all ) {
            $changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='cancelled',updated_at=%s WHERE user_id=%d AND status='pending'", $now, VKT_Account::id() ) );
            return false === $changed ? self::error( 'Не удалось отменить ответы.', 500 ) : array( 'cancelled' => (int) $changed );
        }
        $ids = array_slice( array_filter( array_map( 'absint', (array) $ids ) ), 0, 500 );
        if ( ! $ids ) {
            return self::error( 'Не выбраны ответы для отмены.' );
        }
        $changed = $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET status='cancelled',updated_at=%s WHERE user_id=%d AND status IN ('pending','failed') AND id IN (" . implode( ',', $ids ) . ')',
            $now, VKT_Account::id()
        ) );
        return false === $changed ? self::error( 'Не удалось отменить ответы.', 500 ) : array( 'cancelled' => (int) $changed );
    }

    public static function retry( $id ) {
        global $wpdb;
        $now = gmdate( 'Y-m-d H:i:s' );
        $changed = $wpdb->query( $wpdb->prepare(
            'UPDATE ' . VKT_Store::table( 'comment_replies' ) . " SET status='pending',attempts=0,error='',available_at=%s,updated_at=%s WHERE id=%d AND user_id=%d AND status='failed'",
            $now, $now, absint( $id ), VKT_Account::id()
        ) );
        if ( ! $changed ) {
            return self::error( 'Ответ не найден или уже не в ошибке.', 404 );
        }
        return array( 'ok' => true );
    }

    /**
     * Вход для cron. do_action передаёт обработчику пустую строку первым
     * аргументом, и run_due получил бы лимит в один ответ на весь сайт.
     */
    public static function cron() {
        self::run_due( 5 );
    }

    /**
     * Отправляет созревшие ответы. Cron обходит очередь всех кабинетов,
     * кнопка — только свою. За один проход в группу уходит не больше одного
     * ответа и не раньше чем через MIN_GAP после предыдущего: cron срабатывает
     * раз в минуту, этого достаточно для ровного темпа. Одиночный ответ по
     * кнопке ($reply_id) паузу тоже соблюдает — иначе серия ручных ответов
     * обошла бы её.
     */
    public static function run_due( $limit = 3, $reply_id = 0, $user_id = 0 ) {
        global $wpdb;
        if ( ! VKT_Store::lock( 'replies', 120 ) ) {
            return self::error( 'Очередь ответов уже разбирается.', 409 );
        }
        $table = VKT_Store::table( 'comment_replies' );
        $processed = 0;
        $waiting = 0;
        try {
            update_option( 'vkt_last_reply_run', gmdate( 'Y-m-d H:i:s' ), false );
            $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='pending' WHERE status='sending' AND updated_at<%s", gmdate( 'Y-m-d H:i:s', time() - 180 ) ) );
            $filter = $reply_id ? $wpdb->prepare( ' AND id=%d', absint( $reply_id ) ) : '';
            $filter .= $user_id ? $wpdb->prepare( ' AND user_id=%d', absint( $user_id ) ) : '';
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $table WHERE status='pending' AND available_at<=%s$filter ORDER BY available_at,id LIMIT 30",
                gmdate( 'Y-m-d H:i:s' )
            ), ARRAY_A );
            $limit = max( 1, min( 10, absint( $limit ) ) );
            $used = array();
            foreach ( $rows as $reply ) {
                if ( $processed >= $limit ) {
                    break;
                }
                $group_id = absint( $reply['group_id'] );
                if ( isset( $used[ $group_id ] ) ) {
                    continue;
                }
                // Пауза считается по группе VK, а не по кабинету: одну группу могут вести двое.
                $last_sent = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sent_at) FROM $table WHERE group_id=%d AND status='sent'", $group_id ) );
                if ( $last_sent && strtotime( $last_sent . ' UTC' ) > time() - self::MIN_GAP ) {
                    $used[ $group_id ] = true;
                    ++$waiting;
                    continue;
                }
                $claimed = $wpdb->update( $table, array( 'status' => 'sending', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $reply['id'], 'status' => 'pending' ) );
                if ( 1 !== $claimed ) {
                    continue;
                }
                $used[ $group_id ] = true;
                $attempts = (int) $reply['attempts'] + 1;
                $result = VKT_Account::act_as( absint( $reply['user_id'] ), static fn() => self::attempt( $reply ) );
                $changes = array( 'attempts' => $attempts, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
                if ( is_wp_error( $result ) ) {
                    $error_data = (array) $result->get_error_data();
                    $retry = ! empty( $error_data['retryable'] ) && $attempts < self::MAX_ATTEMPTS;
                    $changes['status'] = $retry ? 'pending' : 'failed';
                    $changes['available_at'] = gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** $attempts ) ) );
                    $changes['error'] = mb_substr( $result->get_error_message(), 0, 255 );
                    // Отвергнут ключ: ответ ждёт переподключения, но не дольше суток — потом он уже не к месту.
                    if ( ! empty( $error_data['auth'] ) && strtotime( $reply['created_at'] . ' UTC' ) > time() - DAY_IN_SECONDS ) {
                        $changes['status'] = 'pending';
                        $changes['attempts'] = (int) $reply['attempts'];
                        $changes['available_at'] = gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS );
                        $changes['error'] = mb_substr( $result->get_error_message() . ' Ждём переподключения ключа.', 0, 255 );
                    }
                } else {
                    $changes['status'] = 'sent';
                    $changes['vk_comment_id'] = absint( $result['response']['comment_id'] ?? 0 );
                    $changes['sent_at'] = gmdate( 'Y-m-d H:i:s' );
                    $changes['error'] = '';
                }
                $wpdb->update( $table, $changes, array( 'id' => $reply['id'] ) );
                ++$processed;
            }
            if ( $processed ) {
                $message = 'Отправлено ответов: ' . $processed . '.';
            } elseif ( $waiting ) {
                $message = 'Группа ещё выдерживает паузу между ответами — ответ уйдёт в течение минуты.';
            } else {
                $message = 'Нет ответов, готовых к отправке.';
            }
            return array( 'processed' => $processed, 'waiting' => $waiting, 'message' => $message );
        } finally {
            VKT_Store::unlock( 'replies' );
        }
    }

    /** Одна попытка отправки. Вызывается уже от имени автора ответа. */
    private static function attempt( $reply ) {
        global $wpdb;
        if ( ! VKT_Account::can_use() ) {
            return self::error( 'Кабинет автора ответа закрыт администратором.' );
        }
        $group_id = absint( $reply['group_id'] );
        $allowed = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND group_id=%d AND enabled=1 AND can_post=1',
            absint( $reply['user_id'] ), $group_id
        ) );
        if ( ! $allowed ) {
            return self::error( 'Сообщество выключено или право записи отозвано.' );
        }
        $params = array(
            'owner_id' => -$group_id,
            'post_id' => absint( $reply['post_id'] ),
            'message' => (string) $reply['message'],
            'reply_to_comment' => absint( $reply['comment_id'] ),
            'guid' => (string) $reply['guid'],
        );
        $sender = self::sender( $group_id );
        if ( 'community' === $sender ) {
            $result = VKT_Community::comment( $params );
        } elseif ( 'user' === $sender ) {
            // from_group у wall.createComment — ID группы, а не флаг, как у wall.post.
            $params['from_group'] = $group_id;
            $result = VKT_API::publishing_request( 'wall.createComment', $params );
        } else {
            return self::error( 'В кабинете нет ни ключа этого сообщества, ни пользовательского токена.' );
        }
        if ( ! is_wp_error( $result ) && ! absint( $result['response']['comment_id'] ?? 0 ) ) {
            return self::error( 'VK не вернул ID комментария.', 502 );
        }
        return $result;
    }

    public static function purge_user( $user_id ) {
        global $wpdb;
        $wpdb->delete( VKT_Store::table( 'comment_replies' ), array( 'user_id' => absint( $user_id ) ) );
    }
}

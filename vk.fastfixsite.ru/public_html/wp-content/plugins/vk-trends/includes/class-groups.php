<?php
defined( 'ABSPATH' ) || exit;

/**
 * «Мои сообщества»: свои группы кабинета с метриками, паспортом и сводкой.
 *
 * Метрики берёт общий сборщик: своя группа становится источником с отметкой
 * own в подписке. Такая подписка не занимает лимит источников и не мешается
 * в «Источниках» и «Сообществах» — там только чужие группы.
 *
 * Паспорт — Markdown от владельца: кто ведёт, формат, тон, призывы. Он и
 * сводка последних постов уходят в модель, когда пишутся посты, серия и
 * ответы на комментарии этой группы.
 */
final class VKT_Groups {
    const PASSPORT_MAX = 20000;
    // Сводка смотрит на два месяца назад: дальше формат группы мог смениться.
    const DIGEST_DAYS = 60;
    // Свежий пост ещё набирает просмотры: в среднем и в «лучших часах» его нет.
    const DIGEST_SETTLE = 2 * DAY_IN_SECONDS;
    const STATS_DAYS = 14;

    const TEMPLATE = "# Паспорт сообщества\n\n## Кто ведёт\n- Ответственный:\n- Закреплено за:\n- Контакт для срочных вопросов:\n\n## О сообществе\n- Тема и ниша:\n- Аудитория (кто читает, возраст, интересы):\n- Цель (продажи, охват, заявки):\n\n## Формат\n- Рубрики:\n- Частота и время выхода:\n- Длина постов:\n- Визуал (фото, видео, стиль картинок):\n\n## Тон общения\n- Обращение (ты / вы):\n- Настроение и манера:\n- Эмодзи и хештеги:\n\n## Призывы к действию\n- Основной призыв:\n- Куда вести (ссылки, сообщения, товары):\n\n## Нельзя\n- Запретные темы и формулировки:\n\n## Заметки\n";

    private static function error( $message, $status = 400, $extra = array() ) {
        return new WP_Error( 'vkt_groups', $message, array_merge( array( 'status' => $status ), $extra ) );
    }

    /** Своя группа кабинета по локальному ID. Чужая не находится, как удалённая. */
    public static function group( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE id=%d AND user_id=%d', absint( $id ), VKT_Account::id() ), ARRAY_A );
        return $row ? $row : self::error( 'Сообщество не найдено в вашем кабинете.', 404 );
    }

    /** То же по ID группы VK: так её знают комментарии. */
    public static function by_vk( $group_id ) {
        global $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE group_id=%d AND user_id=%d', absint( $group_id ), VKT_Account::id() ) );
        return $id ? self::group( $id ) : self::error( 'Сообщество не найдено в вашем кабинете.', 404 );
    }

    /**
     * Сверяет слежение со списком групп. Видимая группа собирается, скрытая
     * встаёт на паузу — история остаётся и вернётся вместе с группой. Группа,
     * ушедшая из кабинета, отписывается. Подписку, заведённую руками в
     * «Источниках», не трогаем: её пользователь хотел сам.
     */
    public static function sync_tracking( $groups ) {
        global $wpdb;
        $user_id = VKT_Account::id();
        $subscriptions = VKT_Store::table( 'subscriptions' );
        $wanted = array();
        $paused = array();
        foreach ( $groups as $group ) {
            $value = '-' . (int) $group['group_id'];
            if ( (int) $group['hidden'] ) {
                $paused[ $value ] = true;
            } else {
                $wanted[ $value ] = (string) $group['name'];
            }
        }
        $own = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id,s.value,sub.enabled FROM $subscriptions sub JOIN " . VKT_Store::table( 'sources' ) . " s ON s.id=sub.source_id WHERE sub.user_id=%d AND sub.own=1 AND s.kind='owner'",
            $user_id
        ), ARRAY_A );
        $tracked = array();
        foreach ( $own as $row ) {
            if ( isset( $wanted[ $row['value'] ] ) && (int) $row['enabled'] ) {
                $tracked[ $row['value'] ] = true;
            } elseif ( isset( $paused[ $row['value'] ] ) ) {
                if ( (int) $row['enabled'] ) {
                    $wpdb->update( $subscriptions, array( 'enabled' => 0 ), array( 'user_id' => $user_id, 'source_id' => (int) $row['id'] ) );
                    VKT_Subscriptions::refresh( (int) $row['id'] );
                }
            } elseif ( ! isset( $wanted[ $row['value'] ] ) ) {
                VKT_Subscriptions::unsubscribe( (int) $row['id'], $user_id );
            }
        }
        $warning = '';
        foreach ( $wanted as $value => $title ) {
            if ( isset( $tracked[ $value ] ) ) {
                continue;
            }
            $result = VKT_Subscriptions::track_own( $value, $title, $user_id );
            if ( is_wp_error( $result ) ) {
                $warning = $result->get_error_message();
            }
        }
        return $warning;
    }

    /** Метрики сборщика по группе: считаются по стене (owner_id), а не по источнику — источник мог быть заведён и коротким именем. */
    private static function metrics( $group_id ) {
        global $wpdb;
        $posts = VKT_Store::table( 'posts' );
        $owner = -absint( $group_id );
        $since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $row = (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS posts,SUM(published_at>=%s) AS posts30,COALESCE(SUM(views),0) AS views,
                AVG(CASE WHEN published_at>=%s THEN views END) AS avg_views30,
                SUM(g1) AS g1,SUM(g7) AS g7,AVG(err) AS err,MAX(published_at) AS last_post,MAX(measured_at) AS measured_at
             FROM $posts WHERE owner_id=%d AND is_ad=0",
            $since, $since, $owner
        ), ARRAY_A );
        $source = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT id,members,photo,synced_at FROM ' . VKT_Store::table( 'sources' ) . " WHERE kind='owner' AND value=%s", (string) $owner ), ARRAY_A );
        return array_merge( $row, array(
            'source_id' => (int) ( $source['id'] ?? 0 ),
            'members' => isset( $source['members'] ) ? (int) $source['members'] : null,
            'source_photo' => (string) ( $source['photo'] ?? '' ),
            'synced_at' => $source['synced_at'] ?? null,
        ) );
    }

    /** Список «Моих сообществ» с метриками. Заодно сверяет, что собирается. */
    public static function state() {
        global $wpdb;
        $groups = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT id,group_id,screen_name,name,photo,enabled,can_post,hidden,passport,passport_at,stats,stats_at FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d ORDER BY hidden,name,id LIMIT 500',
            VKT_Account::id()
        ), ARRAY_A );
        $warning = self::sync_tracking( $groups );
        foreach ( $groups as &$group ) {
            $group['metrics'] = self::metrics( $group['group_id'] );
            $group['has_passport'] = '' !== trim( (string) $group['passport'] );
            // Паспорт и сводку список не везёт: они большие и нужны только в карточке группы.
            unset( $group['passport'] );
            $group['stats'] = self::stats_summary( $group['stats'] );
            $group['best_times'] = self::digest( $group['group_id'] )['best_hours'];
        }
        unset( $group );
        return array( 'groups' => $groups, 'warning' => $warning, 'template' => self::TEMPLATE );
    }

    /** Карточка группы: метрики, последние посты, сводка, охваты и паспорт целиком. */
    public static function detail( $id ) {
        global $wpdb;
        $group = self::group( $id );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        $group['metrics'] = self::metrics( $group['group_id'] );
        $group['digest'] = self::digest( $group['group_id'] );
        $group['stats'] = self::stats_summary( $group['stats'] );
        $group['passport'] = (string) $group['passport'];
        $group['posts'] = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT id,post_id,owner_id,published_at,LEFT(text,300) AS text,thumbnail,views,likes,comments,reposts,g1,g7,err,is_pinned,is_ad FROM ' . VKT_Store::table( 'posts' ) . ' WHERE owner_id=%d ORDER BY published_at DESC,id DESC LIMIT 20',
            -absint( $group['group_id'] )
        ), ARRAY_A );
        unset( $group['callback_code'], $group['callback_secret'] );
        return $group;
    }

    /** Скрыть группу из списка. Скрытая не собирается — сборщику незачем её обходить. */
    public static function hide( $id, $hidden ) {
        global $wpdb;
        $group = self::group( $id );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        $wpdb->update( VKT_Store::table( 'publishing_groups' ), array( 'hidden' => $hidden ? 1 : 0 ), array( 'id' => (int) $group['id'] ) );
        return array( 'ok' => true );
    }

    public static function save_passport( $id, $text ) {
        global $wpdb;
        $group = self::group( $id );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        $text = is_string( $text ) ? trim( str_replace( "\r\n", "\n", wp_strip_all_tags( $text ) ) ) : '';
        if ( mb_strlen( $text ) > self::PASSPORT_MAX ) {
            return self::error( 'Паспорт — не длиннее ' . self::PASSPORT_MAX . ' символов.' );
        }
        $wpdb->update( VKT_Store::table( 'publishing_groups' ), array( 'passport' => $text, 'passport_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $group['id'] ) );
        return array( 'ok' => true, 'passport_at' => gmdate( 'Y-m-d H:i:s' ) );
    }

    /**
     * Сводка по постам группы из собранного: сколько выходит, как заходят,
     * в какие часы лучше, какой длины и с чем. Считается на лету — данные
     * сборщика и так свежие, хранить копию незачем.
     */
    public static function digest( $group_id ) {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT id,published_at,text,views,likes,comments,reposts,err,media_count,link_url FROM ' . VKT_Store::table( 'posts' ) . ' WHERE owner_id=%d AND is_ad=0 AND published_at>=%s ORDER BY published_at DESC LIMIT 150',
            -absint( $group_id ), gmdate( 'Y-m-d H:i:s', time() - self::DIGEST_DAYS * DAY_IN_SECONDS )
        ), ARRAY_A );
        $digest = array( 'posts' => count( $rows ), 'days' => self::DIGEST_DAYS, 'per_week' => null, 'avg_views' => null, 'median_views' => null, 'avg_err' => null, 'avg_length' => null, 'media_share' => null, 'link_share' => null, 'best_hours' => array(), 'best_days' => array(), 'hashtags' => array(), 'top' => array(), 'weak' => array(), 'recent' => array() );
        if ( ! $rows ) {
            return $digest;
        }
        $zone = wp_timezone();
        $settled = array_values( array_filter( $rows, static fn( $row ) => null !== $row['views'] && strtotime( $row['published_at'] . ' UTC' ) < time() - self::DIGEST_SETTLE ) );
        $first = strtotime( end( $rows )['published_at'] . ' UTC' );
        $weeks = max( 1, ( time() - $first ) / WEEK_IN_SECONDS );
        $digest['per_week'] = round( count( $rows ) / $weeks, 1 );
        $lengths = array_map( static fn( $row ) => mb_strlen( trim( (string) $row['text'] ) ), $rows );
        $digest['avg_length'] = (int) round( array_sum( $lengths ) / count( $lengths ) );
        $digest['media_share'] = (int) round( 100 * count( array_filter( $rows, static fn( $row ) => (int) $row['media_count'] > 0 ) ) / count( $rows ) );
        $digest['link_share'] = (int) round( 100 * count( array_filter( $rows, static fn( $row ) => '' !== (string) $row['link_url'] ) ) / count( $rows ) );
        $tags = array();
        foreach ( $rows as $row ) {
            if ( preg_match_all( '/#([\p{L}\p{N}_]{2,40})/u', (string) $row['text'], $found ) ) {
                foreach ( $found[1] as $tag ) {
                    $tag = mb_strtolower( $tag );
                    $tags[ $tag ] = ( $tags[ $tag ] ?? 0 ) + 1;
                }
            }
        }
        arsort( $tags );
        $digest['hashtags'] = array_slice( array_keys( $tags ), 0, 10 );
        $snippet = static fn( $row ) => array( 'id' => (int) $row['id'], 'published_at' => $row['published_at'], 'text' => mb_substr( trim( preg_replace( '/\s+/u', ' ', (string) $row['text'] ) ), 0, 220 ), 'views' => null === $row['views'] ? null : (int) $row['views'], 'err' => null === $row['err'] ? null : round( (float) $row['err'], 2 ) );
        $digest['recent'] = array_map( $snippet, array_slice( $rows, 0, 15 ) );
        if ( ! $settled ) {
            return $digest;
        }
        $views = array_map( static fn( $row ) => (int) $row['views'], $settled );
        sort( $views );
        $middle = intdiv( count( $views ), 2 );
        $digest['median_views'] = count( $views ) % 2 ? $views[ $middle ] : (int) round( ( $views[ $middle - 1 ] + $views[ $middle ] ) / 2 );
        $digest['avg_views'] = (int) round( array_sum( $views ) / count( $views ) );
        $errs = array_filter( array_map( static fn( $row ) => $row['err'], $settled ), static fn( $value ) => null !== $value );
        $digest['avg_err'] = $errs ? round( array_sum( $errs ) / count( $errs ), 2 ) : null;
        $by_views = $settled;
        usort( $by_views, static fn( $a, $b ) => (int) $b['views'] <=> (int) $a['views'] );
        $digest['top'] = array_map( $snippet, array_slice( $by_views, 0, 5 ) );
        $digest['weak'] = count( $by_views ) >= 8 ? array_map( $snippet, array_slice( array_reverse( $by_views ), 0, 3 ) ) : array();
        // Часы и дни — по часовому поясу сайта: в нём же строится сетка серии.
        $hours = array();
        $days = array();
        foreach ( $settled as $row ) {
            $local = ( new DateTimeImmutable( $row['published_at'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone );
            $hours[ $local->format( 'H' ) . ':00' ][] = (int) $row['views'];
            $days[ (int) $local->format( 'N' ) ][] = (int) $row['views'];
        }
        $rank = static function ( $groups, $min ) {
            $out = array();
            foreach ( $groups as $key => $list ) {
                if ( count( $list ) >= $min ) {
                    $out[] = array( 'key' => $key, 'avg' => (int) round( array_sum( $list ) / count( $list ) ), 'posts' => count( $list ) );
                }
            }
            usort( $out, static fn( $a, $b ) => $b['avg'] <=> $a['avg'] );
            return $out;
        };
        // Час с одним постом — случайность, а не закономерность.
        $digest['best_hours'] = array_slice( $rank( $hours, 2 ), 0, 3 );
        $names = array( 1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс' );
        $digest['best_days'] = array_map( static fn( $item ) => array_merge( $item, array( 'label' => $names[ $item['key'] ] ) ), array_slice( $rank( $days, 2 ), 0, 3 ) );
        return $digest;
    }

    /**
     * Охват и посещаемость из stats.get за две недели. Сначала ключом
     * группы, затем пользовательским токеном. Отказ сохраняется дословно:
     * по нему видно, какого права не хватило.
     */
    public static function refresh_stats( $id ) {
        global $wpdb;
        $group = self::group( $id );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        $params = array(
            'group_id' => (int) $group['group_id'],
            'timestamp_from' => time() - self::STATS_DAYS * DAY_IN_SECONDS,
            'timestamp_to' => time(),
            'interval' => 'day',
            'extended' => 1,
        );
        $errors = array();
        $result = null;
        $via = '';
        if ( VKT_Community::has_key( $group['group_id'] ) ) {
            $answer = VKT_Community::stats( $group['group_id'], $params );
            if ( is_wp_error( $answer ) ) {
                $errors[] = 'ключ сообщества: ' . $answer->get_error_message();
            } else {
                $result = $answer;
                $via = 'community';
            }
        }
        if ( null === $result && VKT_Tokens::alive( 'user' ) ) {
            $answer = VKT_API::request( 'stats.get', $params, 'stats' );
            if ( is_wp_error( $answer ) ) {
                $errors[] = 'пользовательский токен: ' . $answer->get_error_message();
            } else {
                $result = $answer['response'] ?? array();
                $via = 'user';
            }
        }
        if ( null === $result && ! $errors ) {
            $errors[] = 'нет ни ключа сообщества, ни живого пользовательского токена';
        }
        $stored = null === $result
            ? array( 'error' => 'VK не отдал статистику: ' . implode( '; ', $errors ) . '. Статистику видят администраторы группы, а сервисному ключу она закрыта.', 'at' => gmdate( 'Y-m-d H:i:s' ) )
            : array( 'via' => $via, 'days' => self::parse_stats( $result ), 'at' => gmdate( 'Y-m-d H:i:s' ) );
        $wpdb->update( VKT_Store::table( 'publishing_groups' ), array( 'stats' => wp_json_encode( $stored, JSON_UNESCAPED_UNICODE ), 'stats_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $group['id'] ) );
        return self::stats_summary( wp_json_encode( $stored ) );
    }

    /** Ответ stats.get в список дней. Поля VK бывают неполными — отсутствующее остаётся null. */
    public static function parse_stats( $response ) {
        $days = array();
        $value = static fn( $set, $key ) => isset( $set[ $key ] ) && is_numeric( $set[ $key ] ) ? (int) $set[ $key ] : null;
        foreach ( (array) $response as $period ) {
            if ( ! is_array( $period ) || empty( $period['period_from'] ) ) {
                continue;
            }
            $reach = (array) ( $period['reach'] ?? array() );
            $visitors = (array) ( $period['visitors'] ?? array() );
            $activity = (array) ( $period['activity'] ?? array() );
            $days[] = array(
                'day' => wp_date( 'Y-m-d', (int) $period['period_from'] ),
                'reach' => $value( $reach, 'reach' ),
                'reach_subscribers' => $value( $reach, 'reach_subscribers' ),
                'views' => $value( $visitors, 'views' ),
                'visitors' => $value( $visitors, 'visitors' ),
                'subscribed' => $value( $activity, 'subscribed' ),
                'unsubscribed' => $value( $activity, 'unsubscribed' ),
            );
        }
        usort( $days, static fn( $a, $b ) => strcmp( $a['day'], $b['day'] ) );
        return $days;
    }

    /** Итоги за 7 дней и дни целиком — для карточки и графика. */
    public static function stats_summary( $raw ) {
        $data = is_string( $raw ) ? json_decode( $raw, true ) : null;
        if ( ! is_array( $data ) ) {
            return null;
        }
        if ( ! empty( $data['error'] ) ) {
            return array( 'error' => (string) $data['error'], 'at' => $data['at'] ?? null );
        }
        $days = (array) ( $data['days'] ?? array() );
        $week = array_slice( $days, -7 );
        $sum = static function ( $key ) use ( $week ) {
            $values = array_filter( array_column( $week, $key ), static fn( $value ) => null !== $value );
            return $values ? array_sum( $values ) : null;
        };
        return array(
            'via' => $data['via'] ?? '',
            'at' => $data['at'] ?? null,
            'days' => $days,
            'week' => array( 'reach' => $sum( 'reach' ), 'reach_subscribers' => $sum( 'reach_subscribers' ), 'visitors' => $sum( 'visitors' ), 'views' => $sum( 'views' ), 'subscribed' => $sum( 'subscribed' ), 'unsubscribed' => $sum( 'unsubscribed' ) ),
        );
    }

    /**
     * Что модель должна знать о группе: паспорт, как заходят посты и о чём
     * писали недавно. Пусто — если группы нет или о ней ничего не известно.
     */
    public static function context( $group, $with_history = true ) {
        if ( is_wp_error( $group ) || ! is_array( $group ) ) {
            return '';
        }
        $parts = array( 'Сообщество: «' . sanitize_text_field( (string) $group['name'] ) . '»' . ( '' !== (string) $group['screen_name'] ? ' (vk.com/' . sanitize_text_field( (string) $group['screen_name'] ) . ')' : '' ) . '.' );
        $passport = trim( (string) ( $group['passport'] ?? '' ) );
        if ( '' !== $passport ) {
            $parts[] = "Паспорт сообщества от владельца — следуй ему в тоне, формате, призывах и запретах:\n" . mb_substr( $passport, 0, 8000 );
        }
        if ( $with_history ) {
            $digest = self::digest( $group['group_id'] );
            if ( $digest['posts'] ) {
                $facts = array( 'За ' . $digest['days'] . ' дней вышло постов: ' . $digest['posts'] . ( $digest['per_week'] ? ' (около ' . $digest['per_week'] . ' в неделю)' : '' ) . ', средняя длина ' . $digest['avg_length'] . ' символов' );
                if ( null !== $digest['avg_views'] ) {
                    $facts[] = 'средние просмотры ' . $digest['avg_views'];
                }
                if ( $digest['hashtags'] ) {
                    $facts[] = 'хештеги: #' . implode( ' #', array_slice( $digest['hashtags'], 0, 6 ) );
                }
                $parts[] = 'История группы по данным сборщика: ' . implode( '; ', $facts ) . '.';
                if ( $digest['top'] ) {
                    $parts[] = "Лучше всего заходили (перенимай подачу, а не текст):\n" . implode( "\n", array_map( static fn( $post ) => '- ' . $post['text'] . ' — ' . $post['views'] . ' просмотров', array_slice( $digest['top'], 0, 3 ) ) );
                }
                if ( $digest['recent'] ) {
                    $parts[] = "Недавние посты — эти темы уже были, не повторяй их:\n" . implode( "\n", array_map( static fn( $post ) => '- ' . mb_substr( $post['text'], 0, 140 ), array_slice( $digest['recent'], 0, 12 ) ) );
                }
            }
        }
        return 1 === count( $parts ) && '' === $passport ? '' : mb_substr( implode( "\n\n", $parts ), 0, 14000 );
    }

    /** Черновик паспорта нейросетью по постам группы. Сохраняет его пользователь, после правки. */
    public static function draft_passport( $id, $model = '' ) {
        $group = self::group( $id );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        $digest = self::digest( $group['group_id'] );
        if ( ! $digest['posts'] ) {
            return self::error( 'Сборщик ещё не собрал посты этой группы. Подождите первого обхода — обычно это несколько минут — и повторите.' );
        }
        return VKT_AI::draft_passport( self::TEMPLATE, (string) $group['passport'], (string) $group['name'], $digest, $model );
    }
}

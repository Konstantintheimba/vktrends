<?php
defined( 'ABSPATH' ) || exit;

/**
 * Автосбор новостей: раз в несколько часов плагин сам ищет свежее по
 * настройкам новостной группы, отбирает, пишет записи и раскладывает их в
 * сетку серии до следующего захода.
 *
 * Заход идёт от имени владельца группы: его лимитом текстов, его ключами и
 * с его ручной проверкой публикаций, если она включена. Когда пора, решает
 * колонка news_auto_at — cron находит готовую группу, не разбирая настройки
 * каждой.
 */
final class VKT_Autonews {
    // Сбор, отбор, рерайт десятка статей и фото: дольше четверти часа заход не идёт.
    const LOCK_TTL = 900;
    // Первая запись выходит не сразу: её успевают увидеть в сетке и поправить.
    const LEAD = 5 * MINUTE_IN_SECONDS;
    const MIN_STEP = 10 * MINUTE_IN_SECONDS;

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_autonews', $message, array( 'status' => $status ) );
    }

    /**
     * Время следующего захода. $after задаёт его явно; без него сохранение
     * настроек не сбивает уже назначенный срок, если он укладывается в новый
     * интервал. Возвращает то, что записано в колонку.
     */
    public static function plan( $group, $auto, $after = null ) {
        global $wpdb;
        $next = null;
        if ( ! empty( $auto['enabled'] ) ) {
            $current = (string) ( $group['news_auto_at'] ?? '' );
            $ceiling = time() + (int) $auto['hours'] * HOUR_IN_SECONDS;
            if ( null !== $after ) {
                $next = gmdate( 'Y-m-d H:i:s', (int) $after );
            } elseif ( '' !== $current && strtotime( $current . ' UTC' ) <= $ceiling ) {
                $next = $current;
            } else {
                // Только что включённый автосбор стартует со следующим проходом cron, а не через весь интервал.
                $next = gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS );
            }
        }
        $wpdb->update( VKT_Store::table( 'publishing_groups' ), array( 'news_auto_at' => $next ), array( 'id' => (int) $group['id'] ) );
        return $next;
    }

    /** Проход cron: одна группа за раз — заход долгий, а событие общее с очередью публикаций. */
    public static function cron() {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT id,user_id FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE news_auto_at IS NOT NULL AND news_auto_at<=%s ORDER BY news_auto_at LIMIT 1',
            gmdate( 'Y-m-d H:i:s' )
        ), ARRAY_A );
        if ( ! $row || ! VKT_Store::lock( 'autonews', self::LOCK_TTL ) ) {
            return;
        }
        try {
            VKT_Account::act_as( absint( $row['user_id'] ), static fn() => self::run( absint( $row['id'] ) ) );
        } finally {
            VKT_Store::unlock( 'autonews' );
        }
    }

    /**
     * Кнопка «Собрать сейчас»: срок переносится на эту минуту, а работу
     * делает cron. Заход идёт минуты — браузер такого ответа не дождётся.
     */
    public static function hurry( $id ) {
        $group = VKT_Groups::group( $id );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        $auto = VKT_News::settings( $group['news'] ?? '' )['auto'];
        if ( ! $auto['enabled'] ) {
            return self::error( 'Сначала включите автосбор и сохраните настройки.' );
        }
        return array( 'ok' => true, 'next_at' => self::plan( $group, $auto, time() ) );
    }

    private static function run( $id ) {
        $group = VKT_Groups::group( $id );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        $settings = VKT_News::settings( $group['news'] ?? '' );
        $auto = $settings['auto'];
        if ( ! $settings['enabled'] || ! $auto['enabled'] ) {
            self::plan( $group, array() );
            return self::error( 'Автосбор для этой группы выключен: включите его в настройках новостей и сохраните.' );
        }
        // Следующий заход назначаем до работы: оборвавшийся посреди сбора PHP не должен запускать его каждую минуту.
        self::plan( $group, $auto, time() + $auto['hours'] * HOUR_IN_SECONDS );
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( self::LOCK_TTL );
        }
        $started = microtime( true );
        $result = self::work( $group, $settings );
        $failed = is_wp_error( $result );
        // «Свежего нет» — обычный исход между выпусками новостей, а не сбой.
        $quiet = $failed && 404 === (int) ( ( (array) $result->get_error_data() )['status'] ?? 0 );
        $message = $failed ? $result->get_error_message() : $result['message'];
        VKT_Groups::news_auto_state( $id, array_merge(
            array( 'last_at' => time(), 'last_ok' => ! $failed || $quiet, 'last' => $message ),
            $failed ? array() : array( 'series' => $result['series_id'], 'series_day' => wp_date( 'Y-m-d' ) )
        ) );
        $name = '«' . sanitize_text_field( (string) $group['name'] ) . '»';
        VKT_Store::log( 'news.auto', 'news', $failed && ! $quiet ? 'error' : 'ok', 0, mb_substr( $name . ': ' . $message, 0, 250 ), (int) round( ( microtime( true ) - $started ) * 1000 ) );
        if ( ! $failed ) {
            // Пуш нужен, только когда записи ждут человека; иначе хватит строки в колокольчике.
            VKT_Notify::add( VKT_Account::id(), 'autonews_ok_' . $id, 'info', 'Автосбор новостей: ' . $name, $message, 'series', ! empty( $result['review'] ), 0 );
        } elseif ( ! $quiet && 'vkt_ai_quota' !== $result->get_error_code() ) {
            // Об исчерпанном лимите кабинет уже оповещён в момент, когда он кончился.
            VKT_Notify::add( VKT_Account::id(), 'autonews_fail_' . $id, 'warning', 'Автосбор новостей не сработал: ' . $name, $message, 'groups' );
        }
        return $failed ? $result : array_merge( $result, array( 'ok' => true ) );
    }

    private static function work( $group, $settings ) {
        $id = (int) $group['id'];
        $auto = $settings['auto'];
        if ( ! VKT_Account::can_use() ) {
            return self::error( 'Кабинет владельца группы не активен.', 403 );
        }
        // Проверяем до сбора: иначе новости отметились бы использованными, а записи так и не встали.
        if ( empty( $group['enabled'] ) || empty( $group['can_post'] ) ) {
            return self::error( 'Сообщество выключено в «Автопостинге» или право публикации не подтверждено.' );
        }
        if ( ! VKT_Tokens::has( 'user' ) && ! VKT_Community::any_key() ) {
            return self::error( 'Публиковать нечем: нужен ключ сообщества или пользовательский токен в «Публикации».' );
        }
        $allowed = VKT_Account::ai_allow( 'text' );
        if ( is_wp_error( $allowed ) ) {
            return $allowed;
        }
        $collected = VKT_Groups::collect_news( $id, $auto['model'], $auto );
        if ( is_wp_error( $collected ) ) {
            return $collected;
        }
        VKT_Account::ai_spend( 'text' );
        // Фото в VK грузит только пользовательский токен: без него запись уйдёт текстом.
        $with_photo = 'none' !== $auto['photo'] && VKT_Tokens::has( 'user' );
        $slots = array();
        $rewritten = 0;
        $photos = 0;
        foreach ( $collected['posts'] as $post ) {
            $text = (string) $post['text'];
            $link = (string) $post['link'];
            if ( $auto['rewrite'] && '' !== $link && ! is_wp_error( VKT_Account::ai_allow( 'text' ) ) ) {
                $full = VKT_Groups::rewrite_news( $id, $link, $auto['model'] );
                // Статья не открылась или модель отказала — остаётся короткая запись по анонсу.
                if ( ! is_wp_error( $full ) ) {
                    $text = $full['text'];
                    VKT_Account::ai_spend( 'text' );
                    ++$rewritten;
                }
            }
            $media = $with_photo && '' !== $link ? self::photo( $id, $auto['photo'], $link, $text ) : array();
            $photos += $media ? 1 : 0;
            $slots[] = array( 'message' => $text, 'media' => $media );
        }
        // Время слотов считаем после рерайта и фото: они идут минуты, и ранний слот успел бы оказаться в прошлом.
        $step = max( self::MIN_STEP, intdiv( $auto['hours'] * HOUR_IN_SECONDS, count( $slots ) ) );
        foreach ( $slots as $index => &$slot ) {
            $slot['scheduled_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + self::LEAD + $index * $step );
        }
        unset( $slot );
        // Серия на каждый день своя: сетку удобно читать, а неудачный день можно отменить целиком.
        $series = wp_date( 'Y-m-d' ) === $auto['series_day'] ? $auto['series'] : '';
        $payload = array( 'slots' => $slots, 'groups' => array( $id ), 'title' => 'Новости · авто · ' . wp_date( 'd.m' ) );
        $created = VKT_Publisher::create_series( $payload + ( '' !== $series ? array( 'series_id' => $series ) : array() ) );
        if ( is_wp_error( $created ) && '' !== $series ) {
            // Сегодняшнюю серию могли отменить или увести в другую группу — начинаем новую.
            $created = VKT_Publisher::create_series( $payload );
        }
        if ( is_wp_error( $created ) ) {
            return $created;
        }
        $times = array_map( static fn( $post ) => wp_date( 'H:i', strtotime( $post['scheduled_at'] ) ), $created['posts'] );
        $review = ! empty( VKT_Account::get( 'publishing_review' ) );
        $message = 'Найдено новостей: ' . (int) $collected['found'] . ', в сетку поставлено: ' . (int) $created['created']
            . ( $times ? ' (' . $times[0] . ( count( $times ) > 1 ? '–' . end( $times ) : '' ) . ')' : '' ) . '.'
            . ( $rewritten ? ' Полных текстов по статье: ' . $rewritten . '.' : '' )
            . ( $photos ? ' С фото: ' . $photos . '.' : '' )
            . ( $created['failed'] ? ' Не встало: ' . count( $created['failed'] ) . ' — ' . $created['failed'][0]['error'] : '' )
            . ( $review ? ' Записи ждут вашей проверки в «Автопостинге».' : '' );
        return array( 'message' => $message, 'created' => (int) $created['created'], 'series_id' => $created['series_id'], 'review' => $review );
    }

    /** Главное фото статьи или обложка по нему. Не вышло — пустой список: запись уйдёт без картинки. */
    private static function photo( $group_id, $kind, $link, $text ) {
        $found = VKT_News::photos( $link );
        $url = is_wp_error( $found ) ? '' : (string) ( $found['photos'][0] ?? '' );
        if ( '' === $url ) {
            return array();
        }
        $media = 'cover' === $kind ? VKT_Groups::cover( $group_id, (string) strtok( $text, "\n" ), 0, $url ) : null;
        if ( null === $media || is_wp_error( $media ) ) {
            // Обложку нарисовать не вышло (нет GD, фон не открылся) — берём само фото.
            $media = VKT_News::photo_save( $url );
        }
        if ( is_wp_error( $media ) || empty( $media['id'] ) ) {
            return array();
        }
        // У cron нет пользователя, и WordPress записал бы файл ничьим: тогда очередь сочла бы его чужим.
        wp_update_post( array( 'ID' => (int) $media['id'], 'post_author' => VKT_Account::id() ) );
        return array( (int) $media['id'] );
    }
}

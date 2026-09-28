<?php
defined( 'ABSPATH' ) || exit;

/**
 * Что сейчас мешает работе кабинета и где это чинить.
 *
 * Плагин работает в фоне, и отказ ключа или простой планировщика раньше
 * было видно только в журнале — неделю спустя. Здесь каждая неполадка
 * превращается в уведомление с вкладкой, на которой её исправляют; дашборд
 * показывает их над любым разделом.
 */
final class VKT_Health {
    // Сбор идёт каждую минуту: 20 минут тишины — это уже простой, а не пауза между заходами.
    const CRON_STALE = 1200;
    // За столько до конца жизни токена без пары обновления начинаем предупреждать.
    const EXPIRY_WARNING = 6 * HOUR_IN_SECONDS;
    // Коды VK, которыми отвергнут сам ключ, а не конкретный запрос.
    const DEAD_CODES = array( 5, 1117 );
    const RIGHTS_CODES = array( 7, 27, 28 );

    /** Где чинить ошибку ключа: личные ключи — в «Публикации», общий ключ сбора — в «Чтении постов». */
    public static function fix_for( $slot, $code ) {
        $code = (int) $code;
        if ( ! in_array( $code, array_merge( self::DEAD_CODES, self::RIGHTS_CODES ), true ) ) {
            return null;
        }
        if ( 'service' === $slot ) {
            return VKT_Account::is_admin()
                ? array( 'view' => 'reading', 'label' => 'Заменить ключ сбора — «Чтение постов»' )
                : null;
        }
        if ( in_array( $slot, array( 'user', 'community' ), true ) ) {
            $what = 'user' === $slot ? 'пользовательский токен' : 'ключ сообщества';
            return array( 'view' => 'posting', 'label' => ( in_array( $code, self::DEAD_CODES, true ) ? 'Переподключить ' : 'Проверить права: ' ) . $what . ' — «Публикация»' );
        }
        return null;
    }

    private static function issue( $level, $title, $text, $view = '', $action = '', $at = null ) {
        return array( 'level' => $level, 'title' => $title, 'text' => $text, 'view' => $view, 'action' => $action, 'at' => $at );
    }

    public static function issues() {
        $out = array();
        $problem = self::token_issue( 'user', 'Пользовательский токен VK' );
        if ( $problem ) {
            $out[] = $problem;
        }
        // Ключи сообществ — по группе: у каждой свой ключ, и отказ одного не касается остальных.
        foreach ( VKT_Community::key_list() as $key ) {
            $name = '' !== $key['name'] ? '«' . $key['name'] . '»' : 'club' . $key['group_id'];
            if ( ! $key['alive'] ) {
                $out[] = self::issue( 'error', 'Ключ группы ' . $name . ' не действует', 'VK отверг ключ: ' . ( $key['error']['message'] ?? '' ) . ' Записи и ответы в эту группу ждут нового ключа.', 'posting', 'Заменить ключ', $key['error']['at'] ?? null );
            } elseif ( ! $key['can_post'] ) {
                $out[] = self::issue( 'warning', 'У ключа группы ' . $name . ' нет права «Стена»', 'Публиковать и отвечать им нельзя. Создайте ключ с доступом к стене и добавьте его заново.', 'posting', 'Заменить ключ' );
            }
        }
        $out = array_merge( $out, self::queue_issues() );
        if ( VKT_Account::is_admin() ) {
            $out = array_merge( $out, self::site_issues() );
        }
        // Сначала то, что уже остановило работу.
        usort( $out, static fn( $a, $b ) => ( 'error' === $b['level'] ) <=> ( 'error' === $a['level'] ) );
        return $out;
    }

    private static function token_issue( $slot, $title ) {
        if ( ! VKT_Tokens::has( $slot ) ) {
            return null;
        }
        $entry = VKT_Tokens::get( $slot );
        $error = VKT_Tokens::error( $slot );
        $refreshable = '' !== $entry['refresh_token'] && '' !== $entry['device_id'] && '' !== $entry['client_id'];
        $what = 'user' === $slot
            ? 'Без него не загружаются фото в записи и не обновляется список групп; текст по-прежнему уходит ключом сообщества, сбор идёт сервисным ключом.'
            : 'Без него записи и ответы в эту группу не публикуются.';
        if ( ! VKT_Tokens::alive( $slot ) ) {
            $reason = ! empty( $error['dead'] ) ? 'VK отверг ключ: ' . $error['message'] : 'Срок действия истёк ' . gmdate( 'd.m H:i', (int) $entry['expires_at'] ) . ' UTC.';
            return self::issue( 'error', $title . ' не действует', $reason . ' ' . $what, 'posting', 'Переподключить', $error['at'] ?? null );
        }
        $left = $entry['expires_at'] ? (int) $entry['expires_at'] - time() : 0;
        if ( $entry['expires_at'] && ! $refreshable && $left < self::EXPIRY_WARNING ) {
            $hours = max( 1, (int) ceil( $left / HOUR_IN_SECONDS ) );
            return self::issue( 'warning', $title . ' истекает через ' . $hours . ' ч', 'VK выдаёт такой токен примерно на сутки и продлить его без вас не даёт. Переподключите заранее — это два клика на странице согласия VK. ' . $what, 'posting', 'Переподключить' );
        }
        return null;
    }

    /** Свои застрявшие публикации и ответы: их видно в кабинете, но не с каждой вкладки. */
    private static function queue_issues() {
        global $wpdb;
        $out = array();
        $user_id = VKT_Account::id();
        $since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
        $failed = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . VKT_Store::table( 'outbound_deliveries' ) . ' d JOIN ' . VKT_Store::table( 'outbound_posts' ) . " p ON p.id=d.outbound_post_id WHERE p.user_id=%d AND d.status='failed' AND d.updated_at>=%s",
            $user_id, $since
        ) );
        if ( $failed ) {
            $out[] = self::issue( 'warning', 'Публикации с ошибкой: ' . $failed, 'Причина указана у каждой; после исправления нажмите «Повторить».', 'publishing', 'Открыть очередь' );
        }
        $waiting = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . VKT_Store::table( 'outbound_deliveries' ) . ' d JOIN ' . VKT_Store::table( 'outbound_posts' ) . " p ON p.id=d.outbound_post_id WHERE p.user_id=%d AND d.status='pending' AND d.error<>''",
            $user_id
        ) );
        if ( $waiting ) {
            $out[] = self::issue( 'warning', 'Публикации ждут повтора: ' . $waiting, 'VK отказал временно или ждём переподключения ключа — очередь повторит их сама.', 'publishing', 'Открыть очередь' );
        }
        $replies = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . VKT_Store::table( 'comment_replies' ) . " WHERE user_id=%d AND status='failed' AND updated_at>=%s",
            $user_id, $since
        ) );
        if ( $replies ) {
            $out[] = self::issue( 'warning', 'Ответы на комментарии с ошибкой: ' . $replies, 'Причина — в очереди ответов.', 'comments', 'Открыть очередь' );
        }
        return $out;
    }

    /** Общие для сайта: ключ сбора, планировщик и сам сбор. Чинить их может только администратор. */
    private static function site_issues() {
        global $wpdb;
        $out = array();
        $settings = VKT_Plugin::settings();
        if ( ! VKT_Tokens::has( 'service' ) ) {
            $out[] = self::issue( 'error', 'Нет сервисного ключа', 'Сбор постов и чтение комментариев идут им.', 'reading', 'Подключить' );
        } elseif ( ! VKT_Tokens::alive( 'service' ) ) {
            $error = VKT_Tokens::error( 'service' );
            $out[] = self::issue( 'error', 'Сервисный ключ не действует', (string) ( $error['message'] ?? '' ), 'reading', 'Заменить ключ', $error['at'] ?? null );
        }
        if ( ! empty( $settings['paused'] ) ) {
            return $out;
        }
        $last = (string) get_option( 'vkt_cron_tick', '' );
        if ( '' !== $last && strtotime( $last . ' UTC' ) < time() - self::CRON_STALE ) {
            $out[] = self::issue( 'error', 'Планировщик не запускался с ' . self::moment( $last ), self::cron_advice(), 'collector', 'Сбор данных', $last );
        }
        // Простой, который уже закончился, тоже важен: пока кто-то заходит на
        // сайт, WP-cron оживает, и без этой записи провал было бы не заметить.
        $gap = get_option( 'vkt_cron_gap', array() );
        if ( is_array( $gap ) && ! empty( $gap['to'] ) && strtotime( $gap['to'] . ' UTC' ) > time() - 3 * DAY_IN_SECONDS ) {
            $out[] = self::issue( 'warning', 'Сбор простаивал с ' . self::moment( $gap['from'] ) . ' до ' . self::moment( $gap['to'] ), self::cron_advice(), 'collector', 'Сбор данных', $gap['to'] );
        }
        $failed = (array) $wpdb->get_row( 'SELECT COUNT(*) AS amount,MAX(updated_at) AS at FROM ' . VKT_Store::table( 'jobs' ) . " WHERE status='failed'", ARRAY_A );
        if ( (int) ( $failed['amount'] ?? 0 ) ) {
            $message = (string) $wpdb->get_var( 'SELECT message FROM ' . VKT_Store::table( 'jobs' ) . " WHERE status='failed' ORDER BY updated_at DESC LIMIT 1" );
            $out[] = self::issue( 'warning', 'Заданий сбора с ошибкой: ' . (int) $failed['amount'], 'Последняя причина: ' . $message . ' Сборщик сам повторит их через ' . VKT_Collector::FAILED_COOLDOWN_HOURS . ' ч или сразу после замены ключа.', 'collector', 'Повторить', $failed['at'] );
        }
        // Источник, который давно не обходился, — прямой признак простоя, какой бы ни была причина.
        $window = max( 3, 3 * (int) $settings['source_hours'] ) * HOUR_IN_SECONDS;
        $stale = (array) $wpdb->get_row( $wpdb->prepare(
            'SELECT COUNT(*) AS amount,MIN(COALESCE(synced_at,\'1970-01-01 00:00:00\')) AS oldest FROM ' . VKT_Store::table( 'sources' ) . ' WHERE enabled=1 AND (synced_at IS NULL OR synced_at<%s)',
            gmdate( 'Y-m-d H:i:s', time() - $window )
        ), ARRAY_A );
        if ( (int) ( $stale['amount'] ?? 0 ) ) {
            $out[] = self::issue( 'error', 'Источники не обновлялись: ' . (int) $stale['amount'], 'Самый давний — ' . ( '1970-01-01 00:00:00' === $stale['oldest'] ? 'ни разу не обходился' : 'с ' . self::moment( $stale['oldest'] ) ) . '. Проверьте задания сбора и журнал.', 'collector', 'Сбор данных', null );
        }
        return $out;
    }

    private static function moment( $utc ) {
        return wp_date( 'd.m H:i', strtotime( $utc . ' UTC' ) );
    }

    private static function cron_advice() {
        return 'WP-cron срабатывает только когда кто-то открывает сайт. Чтобы сбор, публикации и ответы шли без заходов, добавьте в планировщик хостинга задачу раз в минуту: wget -q -O /dev/null "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '"';
    }
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Публикация записей в собственные сообщества VK.
 *
 * Наблюдаемые источники и собственные сообщества намеренно хранятся отдельно:
 * наличие стены в мониторинге не означает права записи в неё.
 *
 * Группы и записи принадлежат кабинету (user_id). Очередь общая, но каждое
 * задание выполняется от имени автора записи — его токеном и его ключом
 * сообщества.
 */
final class VKT_Publisher {
    const MAX_GROUPS_PER_POST = 50;
    const MAX_SERIES_SLOTS = 60;
    const MAX_ATTEMPTS = 4;
    const AUTH_WAIT = 15 * MINUTE_IN_SECONDS;
    const AUTH_GRACE = DAY_IN_SECONDS;

    public static function schema() {
        return array(
            'publishing_groups' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL DEFAULT 0,
                group_id bigint unsigned NOT NULL,
                screen_name varchar(100) NOT NULL DEFAULT '',
                name varchar(255) NOT NULL DEFAULT '',
                photo text NOT NULL,
                admin_level tinyint unsigned NOT NULL DEFAULT 0,
                can_post tinyint unsigned NOT NULL DEFAULT 0,
                enabled tinyint unsigned NOT NULL DEFAULT 1,
                callback_code varchar(32) NOT NULL DEFAULT '',
                callback_secret varchar(255) NOT NULL DEFAULT '',
                callback_server int unsigned NOT NULL DEFAULT 0,
                callback_status varchar(20) NOT NULL DEFAULT '',
                callback_at datetime DEFAULT NULL,
                hidden tinyint unsigned NOT NULL DEFAULT 0,
                passport mediumtext NULL,
                passport_at datetime DEFAULT NULL,
                materials longtext NULL,
                news longtext NULL,
                news_auto_at datetime DEFAULT NULL,
                stats mediumtext NULL,
                stats_at datetime DEFAULT NULL,
                synced_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_group (user_id,group_id),
                KEY available (enabled,can_post),
                KEY news_auto (news_auto_at)",
            'outbound_posts' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL DEFAULT 0,
                message longtext NOT NULL,
                attachments text NOT NULL,
                media text NOT NULL,
                signed tinyint unsigned NOT NULL DEFAULT 0,
                close_comments tinyint unsigned NOT NULL DEFAULT 0,
                origin varchar(20) NOT NULL DEFAULT 'manual',
                series_id varchar(40) NOT NULL DEFAULT '',
                series_title varchar(255) NOT NULL DEFAULT '',
                editor_status varchar(20) NOT NULL DEFAULT 'awaiting',
                status varchar(20) NOT NULL DEFAULT 'scheduled',
                scheduled_at datetime NOT NULL,
                published_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY schedule (status,scheduled_at),
                KEY author (user_id,id),
                KEY series (user_id,series_id)",
            'outbound_deliveries' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                outbound_post_id bigint unsigned NOT NULL,
                group_id bigint unsigned NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                attempts int unsigned NOT NULL DEFAULT 0,
                available_at datetime NOT NULL,
                vk_post_id bigint unsigned DEFAULT NULL,
                media_attachments text NOT NULL,
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

    /**
     * Собирает итоговую строку attachments для wall.post.
     *
     * Загруженные файлы идут первыми, ручные вложения следом, а внешняя ссылка
     * по требованию VK остаётся единственной и последней.
     */
    private static function merge_attachments( $media, $manual ) {
        $ids = array();
        $link = '';
        foreach ( array_merge( explode( ',', (string) $media ), explode( ',', (string) $manual ) ) as $item ) {
            $item = trim( $item );
            if ( '' === $item || in_array( $item, $ids, true ) ) {
                continue;
            }
            if ( preg_match( '~^https?://~i', $item ) ) {
                if ( '' !== $link && $link !== $item ) {
                    return self::error( 'В записи допустима одна ссылка. Уберите ссылку из поля вложений или снимите локальный файл.' );
                }
                $link = $item;
                continue;
            }
            $ids[] = $item;
        }
        if ( '' !== $link ) {
            $ids[] = $link;
        }
        if ( count( $ids ) > 10 ) {
            return self::error( 'В одной записи VK допускает не больше 10 вложений вместе с загруженными файлами.' );
        }
        return implode( ',', $ids );
    }

    /**
     * Чем публиковать запись на стене своего сообщества.
     *
     * Всегда ключом сообщества. Пользовательскому токену VK отказывает в
     * wall.post для приложений не типа Standalone («Permission to perform this
     * action is denied for non-standalone applications»), зато он единственный,
     * кому разрешена загрузка фото. Поэтому роли разделены: токен загружает
     * фотографию в альбом стены группы, а публикует запись ключ сообщества —
     * фотография принадлежит той же группе, и вложение принимается.
     */
    private static function use_community_key( $group_id, $media ) {
        unset( $media );
        return VKT_Community::has_key( $group_id );
    }

    /**
     * Отдаёт вложения VK для локальных файлов задания.
     *
     * Результат сохраняется в самом задании: повторная попытка после сетевой
     * ошибки не должна заново загружать те же файлы в сообщество.
     */
    private static function resolve_media( $delivery ) {
        global $wpdb;
        if ( '' === (string) $delivery['media'] ) {
            return '';
        }
        if ( '' !== (string) $delivery['media_attachments'] ) {
            return (string) $delivery['media_attachments'];
        }
        $attachments = VKT_Media::prepare_for_vk( explode( ',', (string) $delivery['media'] ), absint( $delivery['group_id'] ) );
        if ( is_wp_error( $attachments ) ) {
            return $attachments;
        }
        $attachments = (string) $attachments;
        if ( '' !== $attachments ) {
            $wpdb->update(
                VKT_Store::table( 'outbound_deliveries' ),
                array( 'media_attachments' => $attachments, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
                array( 'id' => absint( $delivery['id'] ) )
            );
        }
        return $attachments;
    }

    /** Загружает все сообщества, где пользователь — администратор или редактор. */
    public static function sync_groups() {
        global $wpdb;
        $user_id = VKT_Account::id();
        if ( ! $user_id ) {
            return self::error( 'Список групп обновляется только вошедшему пользователю.', 401 );
        }
        $items = array();
        $total = 0;
        // Список без групп токена неполный: права остальных групп тогда не трогаем.
        $partial = VKT_Tokens::has( 'user' ) && ! VKT_Tokens::alive( 'user' );
        $user_error = null;
        if ( VKT_Tokens::alive( 'user' ) ) {
            $result = VKT_API::publishing_request( 'groups.get', array(
                'filter' => 'editor',
                'extended' => 1,
                'fields' => 'members_count,photo_200,screen_name',
                'count' => 1000,
            ) );
            if ( is_wp_error( $result ) ) {
                // Токен отказал — группы с ключами сообществ всё равно обновятся.
                if ( ! VKT_Community::any_key() ) {
                    return $result;
                }
                $user_error = $result;
                $partial = true;
            } else {
                $response = (array) ( $result['response'] ?? array() );
                $items = (array) ( $response['items'] ?? array() );
                $total = absint( $response['count'] ?? count( $items ) );
            }
        }
        // Каждая группа с ключом проверяется своим ключом. Такие группы есть в
        // списке всегда, даже если токен их не видит: groups.get не отдаёт
        // группы, где админ не состоит участником.
        $key_error = null;
        foreach ( array_keys( VKT_Community::keys() ) as $keyed ) {
            $community = VKT_Community::check( $keyed );
            if ( is_wp_error( $community ) ) {
                // Ключ отказал — его группа остаётся с прежними правами до замены ключа.
                $key_error = $key_error ?: $community;
                $partial = true;
                continue;
            }
            $can_post = in_array( VKT_Community::REQUIRED_RIGHT, $community['permissions'], true ) ? 1 : 0;
            foreach ( $items as &$item ) {
                if ( absint( $item['id'] ?? 0 ) === absint( $community['group_id'] ) ) {
                    $item['can_post'] = max( $can_post, (int) ! empty( $item['can_post'] ) );
                    continue 2;
                }
            }
            unset( $item );
            $items[] = array(
                'id' => absint( $community['group_id'] ),
                'name' => $community['name'],
                'screen_name' => $community['screen_name'],
                'photo_200' => $community['photo'],
                'admin_level' => 3,
                'can_post' => $can_post,
            );
            ++$total;
        }
        unset( $item );
        if ( ! $items && $key_error && ! $user_error ) {
            return $key_error;
        }
        if ( ! $items ) {
            if ( $user_error ) {
                return $user_error;
            }
            if ( $partial ) {
                return new WP_Error( 'vkt_publisher', 'Пользовательский токен не действует, а ключа сообщества нет — обновить группы нечем.', array( 'status' => 400, 'fix' => VKT_Health::fix_for( 'user', 5 ) ) );
            }
            return self::error( 'Настройте ключ своего сообщества либо пользовательский токен с правами wall и groups.' );
        }
        $table = VKT_Store::table( 'publishing_groups' );
        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->query( 'START TRANSACTION' );
        try {
            // После успешного полного ответа права считаем отозванными у отсутствующих групп.
            if ( ! $partial ) {
                $wpdb->query( $wpdb->prepare( "UPDATE $table SET can_post=0 WHERE user_id=%d", $user_id ) );
            }
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
                    "INSERT INTO $table (user_id,group_id,screen_name,name,photo,admin_level,can_post,enabled,synced_at,created_at)
                     VALUES (%d,%d,%s,%s,%s,%d,%d,1,%s,%s)
                     ON DUPLICATE KEY UPDATE screen_name=VALUES(screen_name),name=VALUES(name),photo=VALUES(photo),admin_level=VALUES(admin_level),can_post=VALUES(can_post),synced_at=VALUES(synced_at)",
                    $user_id,
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
        $result = array( 'synced' => $synced, 'total' => max( $total, $synced ) );
        if ( $partial ) {
            $result['warning'] = $key_error && VKT_Tokens::alive( 'user' ) && ! $user_error
                ? 'Ключ одной из групп не прошёл проверку: ' . $key_error->get_error_message() . ' Её права оставлены как были.'
                : 'Пользовательский токен не действует — обновлены только группы с ключами сообществ, остальные оставлены как были.';
            $result['fix'] = $key_error && ! $user_error && VKT_Tokens::alive( 'user' ) ? VKT_Health::fix_for( 'community', 5 ) : VKT_Health::fix_for( 'user', 5 );
        }
        return $result;
    }

    public static function toggle_group( $id, $enabled ) {
        global $wpdb;
        $table = VKT_Store::table( 'publishing_groups' );
        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id=%d AND user_id=%d", absint( $id ), VKT_Account::id() ) ) ) {
            return self::error( 'Сообщество не найдено в вашем кабинете.', 404 );
        }
        $result = $wpdb->update(
            $table,
            array( 'enabled' => $enabled ? 1 : 0 ),
            array( 'id' => absint( $id ), 'user_id' => VKT_Account::id() )
        );
        return false === $result ? self::error( 'Не удалось изменить сообщество.', 500 ) : array( 'ok' => true );
    }

    /**
     * Адресаты, куда плагин действительно грузит файлы. Право загрузки надо
     * проверять на них: groups.get(filter=editor) приводит в список и
     * сообщества, где владелец токена просто участник, а туда загрузка
     * закрыта — и видно это только на конкретном group_id. Своя группа из
     * константы идёт первой и попадает в список даже выключенной.
     */
    public static function upload_targets( $limit = 5 ) {
        global $wpdb;
        $table = VKT_Store::table( 'publishing_groups' );
        $limit = max( 1, min( 50, (int) $limit ) );
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT group_id,name FROM $table WHERE user_id=%d AND enabled=1 AND can_post=1 ORDER BY name,id LIMIT %d",
            VKT_Account::id(), $limit
        ), ARRAY_A );
        $community = VKT_Community::group_id();
        if ( ! $community || in_array( $community, array_map( static fn( $row ) => absint( $row['group_id'] ), $rows ), true ) ) {
            return $rows;
        }
        $name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $table WHERE group_id=%d AND user_id=%d", $community, VKT_Account::id() ) );
        array_unshift( $rows, array( 'group_id' => $community, 'name' => '' !== $name ? $name : 'своё сообщество ' . $community ) );
        return array_slice( $rows, 0, $limit );
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
                // VK строит из ссылки карточку и требует превью: прямой адрес
                // файла её не даёт и отвечает «link_photo_sizing_rule».
                if ( preg_match( '/\.(jpe?g|png|gif|webp|bmp|avif|svg|mp4|mov|webm|m4v)$/i', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
                    return self::error( 'Прямая ссылка на файл вложением не работает: VK делает из ссылки карточку и без превью отвечает «link_photo_sizing_rule. No photo given». Приложите файл через блок «Файлы с сервера» — для этого нужен пользовательский токен VK ID.' );
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

    /**
     * Текст, вложения и файлы записи с проверками VK. Общая точка для новой
     * записи и для правки поставленной: правка не должна пропустить то, что
     * create() отклонил бы.
     */
    private static function content( $data ) {
        $message = is_string( $data['message'] ?? null ) ? trim( $data['message'] ) : '';
        if ( mb_strlen( $message ) > 16000 ) {
            return self::error( 'Текст записи должен быть не длиннее 16 000 символов.' );
        }
        $attachments = self::sanitize_attachments( $data['attachments'] ?? '' );
        if ( is_wp_error( $attachments ) ) {
            return $attachments;
        }
        // Файлы с нашего сервера: храним ID вложений WordPress, а ID для VK
        // получаем уже при отправке — он зависит от сообщества-адресата.
        $media = VKT_Media::validate_ids( $data['media'] ?? array() );
        if ( is_wp_error( $media ) ) {
            return $media;
        }
        // Отказ на этапе создания: иначе запись уходит в очередь и падает уже
        // после публикации, а пользователь видит только код ошибки VK.
        if ( $media && ! VKT_Tokens::has( 'user' ) ) {
            return self::error( 'Файлы с сервера умеет публиковать только пользовательский токен VK ID: ключу сообщества VK запрещает загрузку фото и видео. Сохраните такой токен в настройках либо уберите файлы из записи.' );
        }
        if ( count( $media ) + count( array_filter( explode( ',', $attachments ) ) ) > 10 ) {
            return self::error( 'В одной записи VK допускает не больше 10 вложений вместе с загруженными файлами.' );
        }
        if ( '' === $message && '' === $attachments && ! $media ) {
            return self::error( 'Добавьте текст, файл или вложение.' );
        }
        if ( '' === $message && ! $media && ! preg_match( '~(?:^|,)(?:photo|video)-?[1-9]\d{0,18}_[1-9]\d{0,18}(?:_[a-zA-Z0-9_-]{1,255})?(?:,|$)|https?://~i', $attachments ) ) {
            return self::error( 'Запись без текста должна содержать фото, видео или внешнюю ссылку.' );
        }
        return array( $message, $attachments, $media );
    }

    public static function create( $data ) {
        global $wpdb;
        $user_id = VKT_Account::id();
        if ( ! $user_id ) {
            return self::error( 'Запись создаётся только вошедшему пользователю.', 401 );
        }
        if ( ! VKT_Tokens::has( 'user' ) && ! VKT_Community::any_key() ) {
            return self::error( 'Для автопостинга нужен ключ своего сообщества либо пользовательский токен с правами wall и groups.' );
        }
        // Будущий адаптер агентов проходит через те же проверки входных данных.
        $data = apply_filters( 'vkt_publisher_prepare_draft', $data );
        if ( ! is_array( $data ) ) {
            return self::error( 'Модуль подготовки вернул неверный формат записи.', 500 );
        }
        $content = self::content( $data );
        if ( is_wp_error( $content ) ) {
            return $content;
        }
        list( $message, $attachments, $media ) = $content;
        $group_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $data['groups'] ?? array() ) ) ) ) );
        if ( ! $group_ids || count( $group_ids ) > self::MAX_GROUPS_PER_POST ) {
            return self::error( 'Выберите от 1 до ' . self::MAX_GROUPS_PER_POST . ' сообществ.' );
        }
        $placeholders = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );
        // Чужая группа в списке не найдётся — как выключенная.
        $query = $wpdb->prepare(
            'SELECT id,group_id FROM ' . VKT_Store::table( 'publishing_groups' ) . " WHERE id IN ($placeholders) AND user_id=%d AND enabled=1 AND can_post=1",
            ...array_merge( $group_ids, array( $user_id ) )
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
        // Ручная проверка — личная настройка кабинета.
        $approval_required = ! empty( VKT_Account::get( 'publishing_review' ) );
        $status = $approval_required ? 'draft' : ( strtotime( $scheduled_at . ' UTC' ) > time() + 30 ? 'scheduled' : 'queued' );
        $delivery_status = $approval_required ? 'waiting_approval' : 'pending';
        // Прежняя запись читала $data['origin'] даже когда ключа нет: PHP
        // ругался, а в базу уходил null вместо 'manual'. Происхождение бывает
        // только двух видов, и всё, что не агент, — ручная запись.
        $origin = 'agents' === ( $data['origin'] ?? '' ) ? 'agents' : 'manual';
        $wpdb->query( 'START TRANSACTION' );
        try {
            $ok = $wpdb->insert( VKT_Store::table( 'outbound_posts' ), array(
                'user_id' => $user_id,
                'message' => $message,
                'attachments' => $attachments,
                'media' => implode( ',', $media ),
                'signed' => empty( $data['signed'] ) ? 0 : 1,
                'close_comments' => empty( $data['close_comments'] ) ? 0 : 1,
                'origin' => $origin,
                // Запись серии помнит её: так серию видно в сетке и её можно отменить целиком.
                'series_id' => preg_match( '/^s[0-9a-z]{6,39}$/', (string) ( $data['series_id'] ?? '' ) ) ? $data['series_id'] : '',
                'series_title' => mb_substr( sanitize_text_field( (string) ( $data['series_title'] ?? '' ) ), 0, 255 ),
                'editor_status' => $approval_required ? 'awaiting' : 'approved',
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
        do_action( 'vkt_publisher_post_created', $post_id, $origin, $status );
        if ( $approval_required ) {
            do_action( 'vkt_publisher_draft_created', $post_id, $origin );
        }
        // Сразу обрабатываем именно только что созданную запись: старая очередь
        // не должна отодвинуть публикацию, ради которой пользователь нажал кнопку.
        $run = 'queued' === $status ? self::run_due( min( 10, count( $groups ) ), $post_id ) : array( 'processed' => 0 );
        if ( is_wp_error( $run ) ) {
            return array( 'id' => $post_id, 'status' => $status, 'warning' => $run->get_error_message(), 'processed' => 0 );
        }
        $current_status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . VKT_Store::table( 'outbound_posts' ) . ' WHERE id=%d', $post_id ) );
        $response = array( 'id' => $post_id, 'status' => $current_status ?: $status, 'processed' => $run['processed'] );
        if ( in_array( $response['status'], array( 'failed', 'partial' ), true ) ) {
            $errors = (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT error FROM " . VKT_Store::table( 'outbound_deliveries' ) . " WHERE outbound_post_id=%d AND status='failed' AND error<>''",
                $post_id
            ) );
            $response['warning'] = $errors ? implode( ' ', array_map( 'sanitize_text_field', $errors ) ) : 'VK не опубликовал запись. Подробности сохранены в истории.';
        } elseif ( 'queued' === $response['status'] && ! empty( $run['processed'] ) ) {
            $errors = (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT error FROM " . VKT_Store::table( 'outbound_deliveries' ) . " WHERE outbound_post_id=%d AND status='pending' AND error<>''",
                $post_id
            ) );
            if ( $errors ) {
                $response['warning'] = implode( ' ', array_map( 'sanitize_text_field', $errors ) ) . ' Плагин повторит отправку автоматически.';
            }
        }
        return $response;
    }

    public static function state() {
        global $wpdb;
        $user_id = VKT_Account::id();
        $groups = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d ORDER BY enabled DESC,can_post DESC,name,id LIMIT 1000', $user_id ), ARRAY_A );
        // Паспорт, материалы и охваты живут в карточке группы, секреты Callback — на сервере: списку они только утяжеляют ответ.
        foreach ( $groups as &$group ) {
            unset( $group['passport'], $group['materials'], $group['news'], $group['stats'], $group['callback_code'], $group['callback_secret'] );
        }
        unset( $group );
        $posts = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'outbound_posts' ) . ' WHERE user_id=%d ORDER BY id DESC LIMIT 100', $user_id ), ARRAY_A );
        $deliveries_table = VKT_Store::table( 'outbound_deliveries' );
        $groups_table = VKT_Store::table( 'publishing_groups' );
        foreach ( $posts as &$post ) {
            $post['deliveries'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT d.id,d.outbound_post_id,d.group_id,d.status,d.attempts,d.available_at,d.vk_post_id,d.error,d.published_at,d.updated_at,g.name,g.screen_name,g.photo
                 FROM $deliveries_table d LEFT JOIN $groups_table g ON g.group_id=d.group_id AND g.user_id=%d WHERE d.outbound_post_id=%d ORDER BY d.id",
                $user_id, $post['id']
            ), ARRAY_A );
            // Показываем сами файлы, а не их ID: удалённые из медиатеки отпадают.
            $media = '' === (string) $post['media'] ? array() : VKT_Media::public_items( explode( ',', (string) $post['media'] ) );
            $post['media_items'] = is_wp_error( $media ) ? array() : $media;
        }
        unset( $post );
        $native_media = VKT_Tokens::has( 'user' );
        return array(
            'groups' => $groups,
            'posts' => $posts,
            'series' => self::series_overview(),
            'status' => array(
                'token_ready' => $native_media || VKT_Community::any_key(),
                'community_only' => ! $native_media && VKT_Community::any_key(),
                // Ключ сообщества не может загружать фото и видео: VK отвечает
                // ошибкой 27, поэтому файл уходит публичной ссылкой.
                'media_native' => $native_media,
                'media_limit' => VKT_Media::MAX_ITEMS,
                'ai' => VKT_AI::public_status(),
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

    /** Запись своего кабинета: чужую нельзя ни отменить, ни подтвердить, ни повторить. */
    private static function owns( $post_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'outbound_posts' ) . ' WHERE id=%d AND user_id=%d', absint( $post_id ), VKT_Account::id() ) );
    }

    public static function cancel( $post_id ) {
        global $wpdb;
        $post_id = absint( $post_id );
        if ( ! self::owns( $post_id ) ) {
            return self::error( 'Запись не найдена в вашем кабинете.', 404 );
        }
        $deliveries = VKT_Store::table( 'outbound_deliveries' );
        $posts = VKT_Store::table( 'outbound_posts' );
        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->query( $wpdb->prepare( "UPDATE $deliveries SET status='cancelled',updated_at=%s WHERE outbound_post_id=%d AND status IN ('pending','waiting_approval')", $now, $post_id ) );
        $wpdb->query( $wpdb->prepare( "UPDATE $posts SET editor_status='rejected',updated_at=%s WHERE id=%d AND status='draft'", $now, $post_id ) );
        self::refresh_post_status( $post_id );
        return array( 'ok' => true );
    }

    /** Необязательный шлюз проверки перед тем, как задания увидит cron. */
    public static function approve( $post_id ) {
        global $wpdb;
        $post_id = absint( $post_id );
        $posts = VKT_Store::table( 'outbound_posts' );
        $post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $posts WHERE id=%d AND user_id=%d AND status='draft'", $post_id, VKT_Account::id() ), ARRAY_A );
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
        $run = 'queued' === $status ? self::run_due( 3, $post_id ) : array( 'processed' => 0 );
        if ( is_wp_error( $run ) ) {
            return array( 'id' => $post_id, 'status' => $status, 'warning' => $run->get_error_message() );
        }
        $current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $posts WHERE id=%d", $post_id ) );
        return array( 'id' => $post_id, 'status' => $current ?: $status, 'processed' => $run['processed'] );
    }

    /**
     * Серия записей одним действием. Каждый слот проходит через create(): это
     * единственная точка, где запись попадает в очередь, и обходить её ради
     * пакета нельзя — там и проверки вложений, и лимиты, и транзакция.
     *
     * Слот в прошлом отклоняется: create() отправляет такую запись сразу, а
     * пакет из десятков слотов ушёл бы в VK залпом вместо расписания.
     */
    public static function create_series( $data ) {
        $slots = is_array( $data['slots'] ?? null ) ? array_values( $data['slots'] ) : array();
        if ( ! $slots ) {
            return self::error( 'Серия пустая: постройте сетку и заполните хотя бы один слот.' );
        }
        if ( count( $slots ) > self::MAX_SERIES_SLOTS ) {
            return self::error( 'За один раз в серию можно поставить не больше ' . self::MAX_SERIES_SLOTS . ' записей.' );
        }
        // Серия живёт в одном сообществе: так её сетку можно дополнять и
        // править, не гадая, в какую из групп ушла каждая запись.
        $groups = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $data['groups'] ?? array() ) ) ) ) );
        if ( 1 !== count( $groups ) ) {
            return self::error( 'Серия публикуется в одно сообщество — выберите его.' );
        }
        $created = array();
        $failed = array();
        $series_id = (string) ( $data['series_id'] ?? '' );
        if ( '' !== $series_id ) {
            // Дополнение: новые слоты встают в ту же серию и только в её сообщество.
            $existing = self::series_group( $series_id );
            if ( is_wp_error( $existing ) ) {
                return $existing;
            }
            if ( $existing['group'] && $existing['group'] !== $groups[0] ) {
                return self::error( 'Серия «' . $existing['title'] . '» привязана к другому сообществу. Новые записи встают только туда.' );
            }
            $series_title = $existing['title'];
        } else {
            $series_id = 's' . strtolower( wp_generate_password( 12, false ) );
            $title = trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
            $series_title = mb_substr( '' !== $title ? $title : 'Серия от ' . wp_date( 'd.m H:i' ), 0, 255 );
        }
        foreach ( $slots as $index => $slot ) {
            $when = is_array( $slot ) ? (string) ( $slot['scheduled_at'] ?? '' ) : '';
            $timestamp = '' === $when ? false : strtotime( $when );
            if ( ! is_array( $slot ) || false === $timestamp ) {
                $failed[] = array( 'index' => (int) $index, 'scheduled_at' => $when, 'error' => 'У слота нет корректной даты публикации.' );
                continue;
            }
            if ( $timestamp < time() + 60 ) {
                $failed[] = array( 'index' => (int) $index, 'scheduled_at' => $when, 'error' => 'Время уже прошло: серия ставится только на будущее.' );
                continue;
            }
            $result = self::create( array(
                'message' => $slot['message'] ?? '',
                'attachments' => $slot['attachments'] ?? '',
                'media' => $slot['media'] ?? array(),
                'groups' => $groups,
                'scheduled_at' => $when,
                'signed' => $data['signed'] ?? false,
                'close_comments' => $data['close_comments'] ?? false,
                'series_id' => $series_id,
                'series_title' => $series_title,
            ) );
            if ( is_wp_error( $result ) ) {
                $failed[] = array( 'index' => (int) $index, 'scheduled_at' => $when, 'error' => $result->get_error_message() );
                continue;
            }
            $created[] = array( 'id' => absint( $result['id'] ?? 0 ), 'scheduled_at' => $when );
        }
        if ( ! $created ) {
            return self::error( 'Ни одна запись серии не создана. Первая причина: ' . ( $failed[0]['error'] ?? 'неизвестна' ), 422 );
        }
        return array( 'created' => count( $created ), 'posts' => $created, 'failed' => $failed, 'series_id' => $series_id, 'title' => $series_title );
    }

    /**
     * Запущенные серии кабинета и записи для сетки. Кроме серий в сетку идут
     * все ещё не отправленные записи: серии, поставленные до появления
     * отметки серии, тоже должны быть видны.
     */
    public static function series_overview() {
        global $wpdb;
        $user_id = VKT_Account::id();
        $posts_table = VKT_Store::table( 'outbound_posts' );
        $series = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT series_id,MAX(series_title) AS title,COUNT(*) AS total,MIN(scheduled_at) AS first_at,MAX(scheduled_at) AS last_at,
                SUM(status IN ('scheduled','queued','draft')) AS waiting,SUM(status='published') AS published,
                SUM(status IN ('failed','partial')) AS failed,SUM(status='cancelled') AS cancelled,MAX(created_at) AS created_at
             FROM $posts_table WHERE user_id=%d AND series_id<>'' GROUP BY series_id ORDER BY created_at DESC LIMIT 20",
            $user_id
        ), ARRAY_A );
        $ids = array_map( static fn( $row ) => $row['series_id'], $series );
        $filter = $ids ? " OR p.series_id IN ('" . implode( "','", array_map( 'esc_sql', $ids ) ) . "')" : '';
        $posts = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id,p.series_id,p.series_title,p.scheduled_at,p.published_at,p.status,LEFT(p.message,140) AS message,p.media,p.attachments<>'' AS has_attachments,
                (SELECT GROUP_CONCAT(COALESCE(NULLIF(g.name,''),CONCAT('club',d.group_id)) SEPARATOR ', ') FROM " . VKT_Store::table( 'outbound_deliveries' ) . ' d LEFT JOIN ' . VKT_Store::table( 'publishing_groups' ) . " g ON g.group_id=d.group_id AND g.user_id=p.user_id WHERE d.outbound_post_id=p.id) AS groups_names,
                (SELECT GROUP_CONCAT(g.id) FROM " . VKT_Store::table( 'outbound_deliveries' ) . ' d JOIN ' . VKT_Store::table( 'publishing_groups' ) . " g ON g.group_id=d.group_id AND g.user_id=p.user_id WHERE d.outbound_post_id=p.id) AS group_ids,
                (SELECT COUNT(*) FROM " . VKT_Store::table( 'outbound_deliveries' ) . " d WHERE d.outbound_post_id=p.id AND d.status IN ('publishing','published')) AS started
             FROM $posts_table p WHERE p.user_id=%d AND (p.status IN ('scheduled','queued','draft')$filter) ORDER BY p.scheduled_at LIMIT 1500",
            $user_id
        ), ARRAY_A );
        foreach ( $posts as &$post ) {
            // Сетке хватает числа файлов: сами файлы грузятся при открытии записи.
            $post['media_count'] = '' === (string) $post['media'] ? 0 : count( array_filter( explode( ',', (string) $post['media'] ) ) );
            $post['group_ids'] = array_map( 'intval', array_filter( explode( ',', (string) $post['group_ids'] ) ) );
            $post['editable'] = self::editable_status( $post['status'] ) && ! (int) $post['started'];
            unset( $post['media'], $post['started'] );
        }
        unset( $post );
        return array( 'list' => $series, 'posts' => $posts );
    }

    /** Править можно то, что ещё ждёт своего времени или проверки. */
    private static function editable_status( $status ) {
        return in_array( (string) $status, array( 'scheduled', 'queued', 'draft' ), true );
    }

    /** Сообщество серии (локальный ID) и её название. Серия чужого кабинета не находится. */
    private static function series_group( $series_id ) {
        global $wpdb;
        if ( ! preg_match( '/^s[0-9a-z]{6,39}$/', (string) $series_id ) ) {
            return self::error( 'Серия не найдена.', 404 );
        }
        $posts = VKT_Store::table( 'outbound_posts' );
        $title = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(series_title) FROM $posts WHERE user_id=%d AND series_id=%s", VKT_Account::id(), $series_id ) );
        if ( null === $title ) {
            return self::error( 'Серия не найдена.', 404 );
        }
        // Серии до 0.26 могли уходить в несколько групп: тогда привязки нет.
        $groups = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT g.id FROM $posts p JOIN " . VKT_Store::table( 'outbound_deliveries' ) . ' d ON d.outbound_post_id=p.id JOIN ' . VKT_Store::table( 'publishing_groups' ) . " g ON g.group_id=d.group_id AND g.user_id=p.user_id
             WHERE p.user_id=%d AND p.series_id=%s AND d.status<>'cancelled'",
            VKT_Account::id(), $series_id
        ) );
        return array( 'title' => (string) $title, 'group' => 1 === count( $groups ) ? (int) $groups[0] : 0 );
    }

    /** Запись целиком для окна правки: сетка знает только начало текста. */
    public static function get( $post_id ) {
        global $wpdb;
        $post = $wpdb->get_row( $wpdb->prepare( 'SELECT id,message,attachments,media,status,scheduled_at,series_id,series_title FROM ' . VKT_Store::table( 'outbound_posts' ) . ' WHERE id=%d AND user_id=%d', absint( $post_id ), VKT_Account::id() ), ARRAY_A );
        if ( ! $post ) {
            return self::error( 'Запись не найдена в вашем кабинете.', 404 );
        }
        $media = '' === (string) $post['media'] ? array() : VKT_Media::public_items( explode( ',', (string) $post['media'] ) );
        $post['media_items'] = is_wp_error( $media ) ? array() : $media;
        $post['media_missing'] = is_wp_error( $media );
        $post['editable'] = self::editable_status( $post['status'] ) && ! self::started( $post['id'] );
        unset( $post['media'] );
        return $post;
    }

    private static function started( $post_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'outbound_deliveries' ) . " WHERE outbound_post_id=%d AND status IN ('publishing','published')", absint( $post_id ) ) );
    }

    /**
     * Правка поставленной записи: текст, вложения, файлы и время. Поле, которого
     * нет в запросе, остаётся прежним — пакетная генерация фото шлёт только файлы.
     *
     * Идёт под замком очереди: run_due держит его всю отправку и читает текст
     * до захвата задания, так что без замка в VK мог бы уйти прежний текст.
     */
    public static function update( $post_id, $data ) {
        global $wpdb;
        $post_id = absint( $post_id );
        $posts = VKT_Store::table( 'outbound_posts' );
        $deliveries = VKT_Store::table( 'outbound_deliveries' );
        $post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $posts WHERE id=%d AND user_id=%d", $post_id, VKT_Account::id() ), ARRAY_A );
        if ( ! $post ) {
            return self::error( 'Запись не найдена в вашем кабинете.', 404 );
        }
        $data = is_array( $data ) ? $data : array();
        $merged = array(
            'message' => array_key_exists( 'message', $data ) ? $data['message'] : $post['message'],
            'attachments' => array_key_exists( 'attachments', $data ) ? $data['attachments'] : $post['attachments'],
            'media' => array_key_exists( 'media', $data ) ? $data['media'] : array_filter( explode( ',', (string) $post['media'] ) ),
        );
        $content = self::content( $merged );
        if ( is_wp_error( $content ) ) {
            return $content;
        }
        list( $message, $attachments, $media ) = $content;
        $scheduled_at = $post['scheduled_at'];
        if ( ! empty( $data['scheduled_at'] ) ) {
            $timestamp = is_string( $data['scheduled_at'] ) ? strtotime( $data['scheduled_at'] ) : false;
            if ( false === $timestamp || $timestamp > time() + YEAR_IN_SECONDS ) {
                return self::error( 'Укажите корректную дату публикации не дальше одного года.' );
            }
            // Перенос в прошлое отправил бы запись немедленно — это уже не перенос.
            if ( $timestamp < time() + 60 ) {
                return self::error( 'Новое время уже прошло: переносить можно только на будущее.' );
            }
            $scheduled_at = gmdate( 'Y-m-d H:i:s', $timestamp );
        }
        if ( ! VKT_Store::lock( 'publisher', 120 ) ) {
            return self::error( 'Сейчас идёт отправка очереди. Повторите через минуту.', 409 );
        }
        try {
            // Статус перечитываем под замком: за время проверок запись могла уйти.
            $status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $posts WHERE id=%d", $post_id ) );
            if ( ! self::editable_status( $status ) || self::started( $post_id ) ) {
                return self::error( 'Запись уже публикуется или опубликована — править её поздно.', 409 );
            }
            $now = gmdate( 'Y-m-d H:i:s' );
            $changes = array( 'message' => $message, 'attachments' => $attachments, 'media' => implode( ',', $media ), 'scheduled_at' => $scheduled_at, 'updated_at' => $now );
            if ( 'draft' !== $status ) {
                $changes['status'] = strtotime( $scheduled_at . ' UTC' ) > time() + 30 ? 'scheduled' : 'queued';
            }
            $wpdb->update( $posts, $changes, array( 'id' => $post_id ) );
            // Загруженные в VK копии прежних файлов больше не годятся: сбрасываем их.
            $wpdb->query( $wpdb->prepare(
                "UPDATE $deliveries SET available_at=%s,media_attachments='',updated_at=%s WHERE outbound_post_id=%d AND status IN ('pending','waiting_approval')",
                $scheduled_at, $now, $post_id
            ) );
        } finally {
            VKT_Store::unlock( 'publisher' );
        }
        return self::get( $post_id );
    }

    /** Отменяет всё ещё не отправленное в серии; опубликованное остаётся. */
    public static function cancel_series( $series_id ) {
        global $wpdb;
        $series_id = (string) $series_id;
        if ( ! preg_match( '/^s[0-9a-z]{6,39}$/', $series_id ) ) {
            return self::error( 'Серия не найдена.', 404 );
        }
        $ids = $wpdb->get_col( $wpdb->prepare(
            'SELECT id FROM ' . VKT_Store::table( 'outbound_posts' ) . " WHERE user_id=%d AND series_id=%s AND status IN ('scheduled','queued','draft')",
            VKT_Account::id(), $series_id
        ) );
        foreach ( $ids as $id ) {
            self::cancel( (int) $id );
        }
        return array( 'cancelled' => count( $ids ) );
    }

    public static function retry( $post_id ) {
        global $wpdb;
        $post_id = absint( $post_id );
        if ( ! self::owns( $post_id ) ) {
            return self::error( 'Запись не найдена в вашем кабинете.', 404 );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        // Кэш вложений VK сбрасывается. Он нужен, чтобы автоматический повтор
        // после сетевой ошибки не загрузил те же файлы дважды, но эта кнопка
        // работает только по status='failed': там в кэше лежит ровно то, что VK
        // отклонил. Автоматический повтор идёт по 'pending' и кэш сохраняет.
        $result = $wpdb->query( $wpdb->prepare( "UPDATE " . VKT_Store::table( 'outbound_deliveries' ) . " SET status='pending',attempts=0,available_at=%s,error='',media_attachments='',updated_at=%s WHERE outbound_post_id=%d AND status='failed'", $now, $now, $post_id ) );
        if ( false === $result ) {
            return self::error( 'Не удалось вернуть публикацию в очередь.', 500 );
        }
        self::refresh_post_status( $post_id );
        return array( 'ok' => true, 'deliveries' => (int) $result );
    }

    /**
     * Отправляет готовые задания. Cron обходит очередь всех кабинетов, кнопка
     * «Запустить очередь» — только свою ($user_id). Каждое задание выполняется
     * от имени автора записи: у cron нет своего пользователя, а токен и ключ
     * сообщества у каждого кабинета свои.
     */
    public static function run_due( $limit = 2, $post_id = 0, $user_id = 0 ) {
        global $wpdb;
        if ( ! VKT_Store::lock( 'publisher', 120 ) ) {
            return self::error( 'Публикация уже выполняется.', 409 );
        }
        $processed = 0;
        $deliveries = VKT_Store::table( 'outbound_deliveries' );
        $posts = VKT_Store::table( 'outbound_posts' );
        $groups = VKT_Store::table( 'publishing_groups' );
        $post_id = absint( $post_id );
        $filter = $post_id ? $wpdb->prepare( ' AND d.outbound_post_id=%d', $post_id ) : '';
        $filter .= $user_id ? $wpdb->prepare( ' AND p.user_id=%d', absint( $user_id ) ) : '';
        try {
            update_option( 'vkt_last_publish_run', gmdate( 'Y-m-d H:i:s' ), false );
            $wpdb->query( $wpdb->prepare( "UPDATE $deliveries SET status='pending' WHERE status='publishing' AND updated_at<%s", gmdate( 'Y-m-d H:i:s', time() - 180 ) ) );
            // Группа ищется в кабинете автора: одну и ту же группу VK могут вести разные люди.
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT d.*,p.user_id,p.message,p.attachments,p.media,p.signed,p.close_comments,p.scheduled_at,g.id AS local_group_id,g.name,g.enabled,g.can_post
                 FROM $deliveries d JOIN $posts p ON p.id=d.outbound_post_id LEFT JOIN $groups g ON g.group_id=d.group_id AND g.user_id=p.user_id
                 WHERE d.status='pending' AND d.available_at<=%s$filter ORDER BY d.available_at,d.id LIMIT %d",
                gmdate( 'Y-m-d H:i:s' ), max( 1, min( 10, absint( $limit ) ) )
            ), ARRAY_A );
            foreach ( $rows as $index => $delivery ) {
                $claimed = $wpdb->update( $deliveries, array( 'status' => 'publishing', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $delivery['id'], 'status' => 'pending' ) );
                if ( 1 !== $claimed ) {
                    continue;
                }
                $attempts = (int) $delivery['attempts'] + 1;
                $result = VKT_Account::act_as( absint( $delivery['user_id'] ), static fn() => self::attempt( $delivery ) );
                $changes = array( 'attempts' => $attempts, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
                if ( is_wp_error( $result ) ) {
                    $error_data = (array) $result->get_error_data();
                    $retry = ! empty( $error_data['retryable'] ) && $attempts < self::MAX_ATTEMPTS;
                    $changes['status'] = $retry ? 'pending' : 'failed';
                    $changes['available_at'] = gmdate( 'Y-m-d H:i:s', time() + min( 3600, 60 * ( 2 ** $attempts ) ) );
                    // VK строит из ссылки карточку и требует у неё превью.
                    // Дословное link_photo_sizing_rule читается как поломка
                    // плагина, хотя дело в самой ссылке во вложениях.
                    $message = $result->get_error_message();
                    // Только ответ самого VK: собственная подсказка плагина про
                    // прямую ссылку на файл точнее и упоминает то же правило.
                    if ( 100 === (int) ( $error_data['vk_code'] ?? 0 ) && str_contains( $message, 'link_photo_sizing_rule' ) ) {
                        $message = 'VK не собрал карточку из ссылки во вложениях: на странице нет изображения подходящего размера. Уберите ссылку или замените её на страницу с превью.';
                    }
                    // Отвергнут ключ: запись ждёт переподключения и уходит сама, попытки не
                    // списываются. Но не дольше суток после назначенного времени — позже она
                    // уже неуместна, и лучше решить вручную.
                    if ( ! empty( $error_data['auth'] ) && strtotime( $delivery['scheduled_at'] . ' UTC' ) > time() - self::AUTH_GRACE ) {
                        $changes['status'] = 'pending';
                        $changes['attempts'] = (int) $delivery['attempts'];
                        $changes['available_at'] = gmdate( 'Y-m-d H:i:s', time() + self::AUTH_WAIT );
                        $message .= ' Ждём переподключения ключа — запись уйдёт сама.';
                    }
                    $changes['error'] = mb_substr( $message, 0, 255 );
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

    /** Одна попытка отправки. Вызывается уже от имени автора записи. */
    private static function attempt( $delivery ) {
        // Заблокированный кабинет перестаёт публиковать сразу, а не после разбора очереди.
        if ( ! VKT_Account::can_use() ) {
            return self::error( 'Кабинет автора записи закрыт администратором.' );
        }
        if ( ! VKT_Tokens::has( 'user' ) && ! VKT_Community::any_key() ) {
            return self::error( 'В кабинете автора нет ни пользовательского токена, ни ключа сообщества. Подключите их в разделе «Публикация» и повторите запись.' );
        }
        if ( ! $delivery['local_group_id'] || ! $delivery['enabled'] || ! $delivery['can_post'] ) {
            return self::error( 'Сообщество выключено или право публикации отозвано.' );
        }
        // Записи прежних версий проходят текущие проверки: иначе
        // разрешённое тогда уходит в VK и возвращается кодом 100.
        $manual = self::sanitize_attachments( $delivery['attachments'] );
        // ID вложения VK зависит от сообщества, поэтому файлы
        // загружаются на каждого адресата и кэшируются в задании.
        $media = is_wp_error( $manual ) ? $manual : self::resolve_media( $delivery );
        $attachments = is_wp_error( $media ) ? $media : self::merge_attachments( $media, $manual );
        if ( is_wp_error( $attachments ) ) {
            return $attachments;
        }
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
        if ( '' !== $attachments ) {
            $params['attachments'] = $attachments;
        }
        $result = self::use_community_key( $delivery['group_id'], $delivery['media'] )
            ? VKT_Community::publish( $params )
            : VKT_API::publishing_request( 'wall.post', $params );
        if ( ! is_wp_error( $result ) && ! absint( $result['response']['post_id'] ?? 0 ) ) {
            return self::error( 'VK не вернул ID опубликованной записи.', 502 );
        }
        return $result;
    }
}

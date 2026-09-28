<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ключи сообществ и приём событий Callback API.
 *
 * У кабинета может быть сколько угодно групп, у каждой свой ключ. Прежний
 * единственный ключ (слот community + community_id, у хозяина — константы
 * wp-config.php) остаётся одним из них.
 */
final class VKT_Community {
    // Права ключа, без которых группа не годится для записи.
    const REQUIRED_RIGHT = 'wall';

    /**
     * ID своего сообщества. У каждого кабинета оно своё; константа из
     * wp-config.php действует только для хозяина сайта — вместе с его ключом.
     */
    public static function group_id() {
        if ( VKT_Account::is_owner() && defined( 'VKT_COMMUNITY_ID' ) && absint( VKT_COMMUNITY_ID ) ) {
            return absint( VKT_COMMUNITY_ID );
        }
        return absint( VKT_Account::get( 'community_id' ) );
    }

    public static function token() {
        return VKT_Tokens::token( 'community' );
    }

    public static function configured() {
        return self::group_id() > 0 && '' !== self::token();
    }

    /** Ключ настроен и VK его не отверг. */
    public static function usable() {
        return self::configured() && VKT_Tokens::alive( 'community' );
    }

    /** Все ключи кабинета: ID группы => ключ. Прежний единственный ключ — тоже здесь. */
    public static function keys() {
        $keys = array();
        foreach ( VKT_Tokens::group_keys() as $group_id => $entry ) {
            $keys[ $group_id ] = $entry['access_token'];
        }
        if ( self::configured() && ! isset( $keys[ self::group_id() ] ) ) {
            $keys[ self::group_id() ] = self::token();
        }
        return $keys;
    }

    public static function has_key( $group_id ) {
        return isset( self::keys()[ absint( $group_id ) ] );
    }

    public static function any_key() {
        return (bool) self::keys();
    }

    /** Прежний единственный ключ или добавленный по группе: от этого зависит, куда писать его ошибки. */
    private static function legacy( $group_id ) {
        return self::configured() && absint( $group_id ) === self::group_id() && ! isset( VKT_Tokens::group_keys()[ absint( $group_id ) ] );
    }

    private static function error_slot( $group_id ) {
        return self::legacy( $group_id ) ? 'community' : 'group:' . absint( $group_id );
    }

    /** Ключ группы есть и VK его не отверг. */
    public static function key_alive( $group_id ) {
        if ( ! self::has_key( $group_id ) ) {
            return false;
        }
        return empty( VKT_Tokens::error( self::error_slot( $group_id ) )['dead'] );
    }

    public static function key_error( $group_id ) {
        return VKT_Tokens::error( self::error_slot( $group_id ) );
    }

    /** Callback API настраивается константами, поэтому он только у хозяина сайта. */
    public static function callback_configured() {
        return VKT_Account::is_owner()
            && self::configured()
            && defined( 'VKT_CALLBACK_CONFIRMATION' )
            && '' !== trim( (string) VKT_CALLBACK_CONFIRMATION )
            && defined( 'VKT_CALLBACK_SECRET' )
            && '' !== trim( (string) VKT_CALLBACK_SECRET );
    }

    public static function callback_url() {
        return admin_url( 'admin-post.php?action=vkt_callback' );
    }

    public static function public_status() {
        return array(
            'configured' => self::configured(),
            'group_id' => self::configured() ? self::group_id() : 0,
            'callback_configured' => self::callback_configured(),
            'callback_url' => self::callback_configured() ? self::callback_url() : '',
            'keys' => self::key_list(),
        );
    }

    /** Ключи для интерфейса: группа, живой ли ключ, последняя ошибка. Сами ключи наружу не уходят. */
    public static function key_list() {
        global $wpdb;
        $out = array();
        $stored = VKT_Tokens::group_keys();
        foreach ( array_keys( self::keys() ) as $group_id ) {
            $group = (array) $wpdb->get_row( $wpdb->prepare(
                'SELECT name,screen_name,photo,can_post FROM ' . VKT_Store::table( 'publishing_groups' ) . ' WHERE user_id=%d AND group_id=%d',
                VKT_Account::id(), $group_id
            ), ARRAY_A );
            $out[] = array(
                'group_id' => $group_id,
                'name' => (string) ( $group['name'] ?? '' ),
                'screen_name' => (string) ( $group['screen_name'] ?? '' ),
                'photo' => (string) ( $group['photo'] ?? '' ),
                'can_post' => ! empty( $group['can_post'] ),
                'alive' => self::key_alive( $group_id ),
                'error' => self::key_error( $group_id ),
                'saved_at' => isset( $stored[ $group_id ] ) ? gmdate( 'Y-m-d H:i:s', $stored[ $group_id ]['saved_at'] ) : null,
                // Прежний ключ из поля «Ключ сообщества» или wp-config.php удаляется там же, где задан.
                'legacy' => self::legacy( $group_id ),
            );
        }
        return $out;
    }

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_community', $message, array( 'status' => $status ) );
    }

    /**
     * Запрос ключом группы. $group_id = 0 — прежний единственный ключ.
     * $token передаётся, когда ключ ещё только проверяется перед сохранением:
     * его ошибки тогда ни за кем не закрепляются.
     */
    private static function request( $method, $params = array(), $group_id = 0, $token = null ) {
        $group_id = absint( $group_id ) ?: self::group_id();
        $candidate = null !== $token;
        $token = $candidate ? (string) $token : ( self::keys()[ $group_id ] ?? '' );
        if ( '' === $token ) {
            return self::error( 'Для этой группы не сохранён ключ сообщества.' );
        }
        $allowed = array(
            'groups.getTokenPermissions' => array(),
            'groups.getById' => array( 'group_id', 'fields' ),
            'wall.post' => array( 'owner_id', 'from_group', 'message', 'attachments', 'signed', 'close_comments', 'guid' ),
            'wall.createComment' => array( 'owner_id', 'post_id', 'from_group', 'message', 'reply_to_comment', 'guid' ),
        );
        if ( ! isset( $allowed[ $method ] ) ) {
            return self::error( 'Метод не разрешён модулю сообщества.' );
        }
        foreach ( $params as $key => $value ) {
            $max_bytes = 'message' === $key ? 70000 : ( 'attachments' === $key ? 20000 : 1000 );
            if ( ! in_array( $key, $allowed[ $method ], true ) || ! is_scalar( $value ) || strlen( (string) $value ) > $max_bytes ) {
                return self::error( 'Неверные параметры запроса сообщества.' );
            }
        }
        $response = wp_remote_post( 'https://api.vk.com/method/' . $method, array(
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 262144,
            'body' => array_merge( $params, array(
                'access_token' => $token,
                'v' => VKT_Plugin::settings()['api_version'],
            ) ),
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'vkt_community_transport', 'Не удалось подключиться к VK для запроса сообщества.', array( 'status' => 502, 'retryable' => true ) );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) ) {
            return new WP_Error( 'vkt_community_response', 'VK вернул некорректный ответ на запрос сообщества.', array( 'status' => 502, 'retryable' => true ) );
        }
        $slot = self::error_slot( $group_id );
        if ( isset( $body['error'] ) ) {
            $code = absint( $body['error']['error_code'] ?? 0 );
            // Причину VK называет текстом: без неё код 100 не подсказывает, какой
            // параметр не понравился. Сам ключ из текста вырезаем.
            $reason = str_replace( $token, '[hidden]', sanitize_text_field( (string) ( $body['error']['error_msg'] ?? '' ) ) );
            $message = 'VK отклонил запрос сообщества, код ' . $code . ( '' !== $reason ? ': ' . mb_substr( $reason, 0, 160 ) : '.' );
            $dead = in_array( $code, VKT_Health::DEAD_CODES, true );
            // Отказ ключа виден в кабинете, а не только в ответе на конкретную запись.
            if ( ! $candidate && ( $dead || in_array( $code, VKT_Health::RIGHTS_CODES, true ) ) ) {
                VKT_Tokens::note( $slot, $message, $dead );
            }
            return new WP_Error( 'vk_' . $code, $message, array(
                'status' => 422,
                'vk_code' => $code,
                'retryable' => in_array( $code, array( 1, 6, 9, 10, 29, 32, 36 ), true ),
                'auth' => $dead,
                'slot' => 'community',
                'fix' => VKT_Health::fix_for( 'community', $code ),
            ) );
        }
        if ( ! $candidate && VKT_Tokens::error( $slot ) ) {
            VKT_Tokens::note( $slot, '' );
        }
        return $body['response'] ?? array();
    }

    /**
     * Проверяет ключ группы: какой группе он принадлежит и какие у него права.
     * $group_id = 0 — прежний единственный ключ; с $token — ключ, который
     * ещё не сохранён.
     */
    public static function check( $group_id = 0, $token = null ) {
        $group_id = absint( $group_id );
        $permissions = self::request( 'groups.getTokenPermissions', array(), $group_id, $token );
        if ( is_wp_error( $permissions ) ) {
            return $permissions;
        }
        // Ключ сообщества без group_id возвращает свою группу — так и узнаём, чей он.
        $target = $group_id ?: ( null === $token ? self::group_id() : 0 );
        $query = array( 'fields' => 'screen_name,photo_200' );
        if ( $target ) {
            $query['group_id'] = $target;
        }
        $group_response = self::request( 'groups.getById', $query, $group_id, $token );
        if ( is_wp_error( $group_response ) ) {
            return $group_response;
        }
        $groups = (array) ( $group_response['groups'] ?? $group_response );
        $group = (array) ( $groups[0] ?? array() );
        $found = absint( $group['id'] ?? 0 );
        if ( ! $found || ( $target && $target !== $found ) ) {
            return self::error( 'Ключ не подтвердил указанное сообщество.', 422 );
        }
        $names = array();
        foreach ( (array) ( $permissions['permissions'] ?? array() ) as $permission ) {
            if ( is_array( $permission ) && ! empty( $permission['name'] ) ) {
                $names[] = sanitize_key( $permission['name'] );
            }
        }
        return array(
            'group_id' => $found,
            'name' => sanitize_text_field( (string) ( $group['name'] ?? '' ) ),
            'screen_name' => preg_replace( '/[^a-zA-Z0-9._]/', '', (string) ( $group['screen_name'] ?? '' ) ),
            'photo' => esc_url_raw( (string) ( $group['photo_200'] ?? '' ), array( 'https' ) ),
            'permissions' => array_values( array_unique( array_filter( $names ) ) ),
            'callback_url' => self::callback_configured() ? self::callback_url() : '',
        );
    }

    /**
     * Добавляет группу по её ключу. Группа сразу появляется в «Моих
     * сообществах», автопостинге и комментариях — без суточного
     * пользовательского токена и без groups.get, который не видит группы,
     * где админ не состоит участником.
     */
    public static function add_key( $raw, $group_hint = '' ) {
        global $wpdb;
        $token = trim( (string) $raw );
        if ( ! preg_match( '/^[a-zA-Z0-9._\-]{20,2048}$/', $token ) ) {
            return self::error( 'Вставьте ключ доступа сообщества целиком: Управление → Работа с API → Ключи доступа.' );
        }
        $hint = self::parse_group( $group_hint );
        if ( is_wp_error( $hint ) ) {
            return $hint;
        }
        $checked = self::check( $hint, $token );
        if ( is_wp_error( $checked ) ) {
            $code = (int) ( ( (array) $checked->get_error_data() )['vk_code'] ?? 0 );
            if ( in_array( $code, VKT_Health::DEAD_CODES, true ) || 27 === $code ) {
                return self::error( 'VK не принял этот ключ как ключ сообщества. Создайте новый в группе: Управление → Работа с API → Ключи доступа.', 422 );
            }
            return $checked;
        }
        $saved = VKT_Tokens::save_group_key( $checked['group_id'], $token );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        $can_post = in_array( self::REQUIRED_RIGHT, $checked['permissions'], true ) ? 1 : 0;
        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . VKT_Store::table( 'publishing_groups' ) . " (user_id,group_id,screen_name,name,photo,admin_level,can_post,enabled,synced_at,created_at)
             VALUES (%d,%d,%s,%s,%s,3,%d,1,%s,%s)
             ON DUPLICATE KEY UPDATE screen_name=VALUES(screen_name),name=VALUES(name),photo=VALUES(photo),can_post=VALUES(can_post),enabled=1,synced_at=VALUES(synced_at)",
            VKT_Account::id(), $checked['group_id'], sanitize_key( $checked['screen_name'] ), $checked['name'], $checked['photo'], $can_post, $now, $now
        ) );
        $checked['can_post'] = (bool) $can_post;
        if ( ! $can_post ) {
            $checked['warning'] = 'У ключа нет права «Стена» — публиковать и отвечать им нельзя. Создайте ключ с доступом к стене и добавьте его заново.';
        }
        return $checked;
    }

    /** Убирает ключ группы. Сама группа остаётся в списке, но без права записи — пока его не подтвердит токен или новый ключ. */
    public static function forget_key( $group_id ) {
        global $wpdb;
        $group_id = absint( $group_id );
        if ( ! isset( VKT_Tokens::group_keys()[ $group_id ] ) ) {
            return self::error( self::legacy( $group_id ) ? 'Этот ключ задан в поле «Ключ сообщества» или в wp-config.php — удалите его там.' : 'Ключ этой группы не найден.', 404 );
        }
        $forgotten = VKT_Tokens::forget_group_key( $group_id );
        if ( is_wp_error( $forgotten ) ) {
            return $forgotten;
        }
        $wpdb->update( VKT_Store::table( 'publishing_groups' ), array( 'can_post' => 0 ), array( 'user_id' => VKT_Account::id(), 'group_id' => $group_id ) );
        return array( 'ok' => true );
    }

    /**
     * ID группы из подсказки: число, club123/public123 или ссылка на них.
     * Короткое имя без ключа не разрешить, поэтому его не принимаем — поле
     * можно оставить пустым, VK сам скажет, чей ключ.
     */
    private static function parse_group( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return 0;
        }
        if ( preg_match( '~(?:^|/)(?:club|public|event)?-?(\d{1,12})/?$~', $value, $match ) ) {
            return absint( $match[1] );
        }
        return self::error( 'Укажите ID группы числом или ссылкой вида vk.com/club123 — либо оставьте поле пустым.' );
    }

    /** Публикует только в группу, чей ключ сохранён: ключ другой группы VK не примет. */
    public static function publish( $params ) {
        $owner = is_array( $params ) ? (int) ( $params['owner_id'] ?? 0 ) : 0;
        if ( ! is_array( $params ) || $owner >= 0 || ! self::has_key( -$owner ) || 1 !== (int) ( $params['from_group'] ?? 0 ) ) {
            return self::error( 'Ключ сообщества может публиковать только на своей стене.' );
        }
        $response = self::request( 'wall.post', $params, -$owner );
        return is_wp_error( $response ) ? $response : array( 'response' => $response );
    }

    /** Отвечает на комментарий только на стене группы, чей ключ сохранён. */
    public static function comment( $params ) {
        $owner = is_array( $params ) ? (int) ( $params['owner_id'] ?? 0 ) : 0;
        if ( ! is_array( $params ) || $owner >= 0 || ! self::has_key( -$owner ) ) {
            return self::error( 'Ключ сообщества может отвечать только на своей стене.' );
        }
        $params['from_group'] = -$owner;
        $response = self::request( 'wall.createComment', $params, -$owner );
        return is_wp_error( $response ) ? $response : array( 'response' => $response );
    }

    /** Публичная точка Callback API. Сырые события и секреты не сохраняются. */
    public static function callback() {
        // Запрос приходит от VK без пользователя: сверяемся с сообществом хозяина.
        VKT_Account::act_as( VKT_Account::owner(), array( self::class, 'receive' ) );
    }

    public static function receive() {
        $raw = file_get_contents( 'php://input' );
        if ( ! self::callback_configured() || ! is_string( $raw ) || strlen( $raw ) > 262144 ) {
            self::respond( 'forbidden', 403 );
        }
        $data = json_decode( $raw, true );
        $secret = is_array( $data ) && is_string( $data['secret'] ?? null ) ? $data['secret'] : '';
        if ( ! is_array( $data )
            || absint( $data['group_id'] ?? 0 ) !== self::group_id()
            || ! hash_equals( (string) VKT_CALLBACK_SECRET, $secret ) ) {
            self::respond( 'forbidden', 403 );
        }
        $type = sanitize_key( (string) ( $data['type'] ?? '' ) );
        if ( 'confirmation' === $type ) {
            self::respond( (string) VKT_CALLBACK_CONFIRMATION );
        }
        if ( '' !== $type ) {
            VKT_Store::log( 'callback.' . $type, 'callback', 'ok', 0, 'Событие сообщества получено', 0 );
        }
        self::respond( 'ok' );
    }

    private static function respond( $body, $status = 200 ) {
        status_header( $status );
        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed protocol values only.
        exit;
    }
}

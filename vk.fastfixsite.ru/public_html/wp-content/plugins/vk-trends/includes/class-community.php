<?php
defined( 'ABSPATH' ) || exit;

/** Управление тестовым сообществом и приём событий Callback API. */
final class VKT_Community {
    /** ID сообщества: константа важнее, иначе берём из настроек. */
    public static function group_id() {
        if ( defined( 'VKT_COMMUNITY_ID' ) && absint( VKT_COMMUNITY_ID ) ) {
            return absint( VKT_COMMUNITY_ID );
        }
        return absint( VKT_Plugin::settings()['community_id'] ?? 0 );
    }

    public static function token() {
        return VKT_Tokens::token( 'community' );
    }

    public static function configured() {
        return self::group_id() > 0 && '' !== self::token();
    }

    public static function callback_configured() {
        return self::configured()
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
        );
    }

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_community', $message, array( 'status' => $status ) );
    }

    private static function request( $method, $params = array() ) {
        if ( ! self::configured() ) {
            return self::error( 'Тестовый ключ сообщества не настроен.' );
        }
        $allowed = array(
            'groups.getTokenPermissions' => array(),
            'groups.getById' => array( 'group_id', 'fields' ),
            'wall.post' => array( 'owner_id', 'from_group', 'message', 'attachments', 'signed', 'close_comments', 'guid' ),
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
                'access_token' => self::token(),
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
        if ( isset( $body['error'] ) ) {
            $code = absint( $body['error']['error_code'] ?? 0 );
            // Причину VK называет текстом: без неё код 100 не подсказывает, какой
            // параметр не понравился. Сам ключ из текста вырезаем.
            $reason = str_replace( self::token(), '[hidden]', sanitize_text_field( (string) ( $body['error']['error_msg'] ?? '' ) ) );
            return new WP_Error( 'vk_' . $code, 'VK отклонил запрос сообщества, код ' . $code . ( '' !== $reason ? ': ' . mb_substr( $reason, 0, 160 ) : '.' ), array(
                'status' => 422,
                'vk_code' => $code,
                'retryable' => in_array( $code, array( 1, 6, 9, 10, 29, 32, 36 ), true ),
            ) );
        }
        return $body['response'] ?? array();
    }

    public static function check() {
        $permissions = self::request( 'groups.getTokenPermissions' );
        if ( is_wp_error( $permissions ) ) {
            return $permissions;
        }
        $group_id = self::group_id();
        $group_response = self::request( 'groups.getById', array( 'group_id' => $group_id, 'fields' => 'screen_name,photo_200' ) );
        if ( is_wp_error( $group_response ) ) {
            return $group_response;
        }
        $groups = (array) ( $group_response['groups'] ?? $group_response );
        $group = (array) ( $groups[0] ?? array() );
        if ( $group_id !== absint( $group['id'] ?? 0 ) ) {
            return self::error( 'Ключ не подтвердил указанное сообщество.', 422 );
        }
        $names = array();
        foreach ( (array) ( $permissions['permissions'] ?? array() ) as $permission ) {
            if ( is_array( $permission ) && ! empty( $permission['name'] ) ) {
                $names[] = sanitize_key( $permission['name'] );
            }
        }
        return array(
            'group_id' => $group_id,
            'name' => sanitize_text_field( (string) ( $group['name'] ?? '' ) ),
            'screen_name' => preg_replace( '/[^a-zA-Z0-9._]/', '', (string) ( $group['screen_name'] ?? '' ) ),
            'photo' => esc_url_raw( (string) ( $group['photo_200'] ?? '' ), array( 'https' ) ),
            'permissions' => array_values( array_unique( array_filter( $names ) ) ),
            'callback_url' => self::callback_configured() ? self::callback_url() : '',
        );
    }

    /** Публикует только в сообщество, которому принадлежит настроенный ключ. */
    public static function publish( $params ) {
        $group_id = self::configured() ? self::group_id() : 0;
        if ( ! is_array( $params )
            || -$group_id !== (int) ( $params['owner_id'] ?? 0 )
            || 1 !== (int) ( $params['from_group'] ?? 0 ) ) {
            return self::error( 'Ключ сообщества может публиковать только на своей стене.' );
        }
        $response = self::request( 'wall.post', $params );
        return is_wp_error( $response ) ? $response : array( 'response' => $response );
    }

    /** Публичная точка Callback API. Сырые события и секреты не сохраняются. */
    public static function callback() {
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

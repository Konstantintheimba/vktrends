<?php
defined( 'ABSPATH' ) || exit;

/**
 * Подключение пользовательского токена через VK ID (OAuth 2.1 + PKCE).
 *
 * Старый Implicit Flow отдаёт токен на сутки и без refresh_token, поэтому для
 * автопостинга не годится. Здесь плагин сам проводит обмен кода на пару токенов
 * и сохраняет комплект, который умеет обновлять VKT_API::maybe_refresh().
 */
final class VKT_VKID {
    const SCOPE = 'wall photos groups video';
    const AUTHORIZE = 'https://id.vk.ru/authorize';
    const TOKEN = 'https://id.vk.ru/oauth2/auth';
    const STATE_TTL = 900;

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_vkid', $message, array( 'status' => $status ) );
    }

    /** ID приложения — не секрет: он виден в адресе страницы согласия. */
    public static function client_id() {
        if ( defined( 'VKT_VKID_CLIENT_ID' ) && absint( VKT_VKID_CLIENT_ID ) ) {
            return absint( VKT_VKID_CLIENT_ID );
        }
        return absint( VKT_Plugin::settings()['vkid_client_id'] ?? 0 );
    }

    public static function configured() {
        return self::client_id() > 0;
    }

    /**
     * Защищённый ключ приложения. По умолчанию не нужен: подключение идёт по
     * PKCE. Константа пригодится, только если VK ID сочтёт приложение
     * серверным и потребует аутентификацию клиента при обмене кода.
     */
    private static function client_secret() {
        return defined( 'VKT_VKID_CLIENT_SECRET' ) ? trim( (string) VKT_VKID_CLIENT_SECRET ) : '';
    }

    /**
     * Адрес возврата. VK ID сверяет его с доверенными адресами приложения, а
     * какие именно формы там принимаются — решает консоль VK, не плагин.
     * Поэтому адрес настраивается, а возврат ловится на любой своей странице.
     */
    public static function redirect_uri() {
        $custom = is_string( VKT_Plugin::settings()['vkid_redirect'] ?? null ) ? trim( VKT_Plugin::settings()['vkid_redirect'] ) : '';
        return '' !== $custom ? $custom : home_url( '/' );
    }

    /** Разрешаем только адреса своего сайта: чужой увёл бы код авторизации. */
    public static function sanitize_redirect( $url ) {
        $url = esc_url_raw( trim( (string) $url ), array( 'https', 'http' ) );
        if ( '' === $url ) {
            return '';
        }
        if ( ! str_starts_with( set_url_scheme( $url ), set_url_scheme( home_url( '/' ) ) )
            && ! str_starts_with( set_url_scheme( $url ), set_url_scheme( admin_url() ) ) ) {
            return new WP_Error( 'vkt_vkid', 'Адрес возврата должен вести на этот же сайт.', array( 'status' => 400 ) );
        }
        return $url;
    }

    /**
     * Ловит возврат VK ID на любой странице сайта: опознаём его по своему
     * state, а не по адресу, поэтому доверенный адрес можно менять свободно.
     */
    public static function maybe_capture() {
        if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) || is_admin() ) {
            return;
        }
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
        if ( ! current_user_can( 'manage_options' ) || ! is_array( get_transient( self::key( $state ) ) ) ) {
            return;
        }
        self::finish();
    }

    /** Константа сервисного ключа имеет приоритет и не даст сохранить пользовательский токен. */
    public static function blocked_by_constant() {
        return defined( 'VKT_ACCESS_TOKEN' );
    }

    public static function public_status() {
        return array(
            'configured' => self::configured(),
            'client_id' => self::client_id(),
            'locked' => defined( 'VKT_VKID_CLIENT_ID' ) && absint( VKT_VKID_CLIENT_ID ),
            'redirect_uri' => self::redirect_uri(),
            'scope' => self::SCOPE,
            'has_secret' => '' !== self::client_secret(),
            'blocked_by_constant' => self::blocked_by_constant(),
        );
    }

    private static function key( $state ) {
        return 'vkt_vkid_' . hash( 'sha256', $state );
    }

    private static function base64url( $raw ) {
        return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
    }

    /** Адрес возврата принимаем только свой, чтобы не увести администратора наружу. */
    private static function safe_return( $url ) {
        $url = esc_url_raw( (string) $url, array( 'https', 'http' ) );
        $home = set_url_scheme( home_url( '/' ) );
        $admin = set_url_scheme( admin_url() );
        foreach ( array( $home, $admin ) as $allowed ) {
            if ( $url && str_starts_with( set_url_scheme( $url ), $allowed ) ) {
                return $url;
            }
        }
        return admin_url( 'admin.php?page=vk-trends' );
    }

    /** Готовит PKCE-пару и отдаёт адрес страницы согласия VK ID. */
    public static function start( $return_to = '' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return self::error( 'Недостаточно прав.', 403 );
        }
        if ( ! self::configured() ) {
            return self::error( 'Сначала укажите ID приложения VK ID.' );
        }
        if ( self::blocked_by_constant() ) {
            return self::error( 'В wp-config.php задан VKT_ACCESS_TOKEN — он всегда трактуется как сервисный ключ и имеет приоритет. Удалите или закомментируйте эту строку, иначе пользовательский токен сохранить нельзя.' );
        }
        $verifier = self::base64url( random_bytes( 48 ) );
        $state = self::base64url( random_bytes( 24 ) );
        $stored = set_transient( self::key( $state ), array(
            'verifier' => $verifier,
            'user' => get_current_user_id(),
            'return' => self::safe_return( $return_to ),
        ), self::STATE_TTL );
        if ( ! $stored ) {
            return self::error( 'Не удалось сохранить состояние авторизации. Проверьте объектный кэш.', 500 );
        }
        return array( 'url' => self::AUTHORIZE . '?' . http_build_query( array(
            'response_type' => 'code',
            'client_id' => self::client_id(),
            'code_challenge' => self::base64url( hash( 'sha256', $verifier, true ) ),
            'code_challenge_method' => 'S256',
            'redirect_uri' => self::redirect_uri(),
            'state' => $state,
            'scope' => self::SCOPE,
        ), '', '&', PHP_QUERY_RFC3986 ) );
    }

    /** Точка возврата VK ID: меняет код на пару токенов и сохраняет комплект. */
    public static function finish() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
        }
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $stored = $state ? get_transient( self::key( $state ) ) : false;
        if ( is_array( $stored ) ) {
            delete_transient( self::key( $state ) );
        }
        // Ссылку возврата берём из своего состояния, а не из запроса VK.
        $return = is_array( $stored ) ? (string) $stored['return'] : admin_url( 'admin.php?page=vk-trends' );
        if ( ! is_array( $stored ) || absint( $stored['user'] ) !== get_current_user_id() ) {
            self::back( $return, 'Ссылка возврата устарела или открыта другим пользователем. Начните подключение заново.' );
        }
        $vk_error = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : ( isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '' );
        if ( '' !== $vk_error ) {
            self::back( $return, 'VK ID отклонил запрос: ' . mb_substr( $vk_error, 0, 160 ) );
        }
        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        $device_id = isset( $_GET['device_id'] ) ? sanitize_text_field( wp_unslash( $_GET['device_id'] ) ) : '';
        if ( ! preg_match( '/^[a-zA-Z0-9._\-]{8,1024}$/', $code ) || ! preg_match( '/^[a-zA-Z0-9._\-]{4,1024}$/', $device_id ) ) {
            self::back( $return, 'VK ID не передал код авторизации и device_id.' );
        }
        $body_params = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $stored['verifier'],
            'client_id' => self::client_id(),
            'device_id' => $device_id,
            'redirect_uri' => self::redirect_uri(),
            'state' => $state,
        );
        if ( '' !== self::client_secret() ) {
            $body_params['client_secret'] = self::client_secret();
        }
        $response = wp_remote_post( self::TOKEN, array(
            'timeout' => 20,
            'redirection' => 0,
            'sslverify' => true,
            'body' => $body_params,
        ) );
        if ( is_wp_error( $response ) ) {
            VKT_Store::log( 'oauth2.auth', 'refresh', 'error', 0, 'Нет связи с id.vk.ru при подключении', 0 );
            self::back( $return, 'Не удалось связаться с id.vk.ru. Повторите попытку.' );
        }
        $http = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $http || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            $reason = is_array( $body ) ? sanitize_text_field( (string) ( $body['error_description'] ?? $body['error'] ?? '' ) ) : '';
            VKT_Store::log( 'oauth2.auth', 'refresh', 'error', $http, 'VK ID отклонил обмен кода', 0 );
            self::back( $return, 'VK ID не выдал токен' . ( $reason ? ': ' . mb_substr( $reason, 0, 160 ) : ' (HTTP ' . $http . ').' ) );
        }
        $saved = VKT_API::save_token( (string) $body['access_token'], 'user', array(
            'scope' => (string) ( $body['scope'] ?? '' ),
            'refresh_token' => (string) ( $body['refresh_token'] ?? '' ),
            'device_id' => (string) ( $body['device_id'] ?? $device_id ),
            'client_id' => (string) self::client_id(),
            'expires_in' => absint( $body['expires_in'] ?? 0 ),
        ) );
        if ( is_wp_error( $saved ) ) {
            self::back( $return, $saved->get_error_message() );
        }
        VKT_Store::log( 'oauth2.auth', 'refresh', 'ok', 0, 'Пользовательский токен получен через VK ID', 0 );
        // Права возвращает сам VK ID: сразу показываем, что именно выдано.
        $scope = isset( $body['scope'] ) && is_string( $body['scope'] ) ? preg_replace( '/[^a-z_, ]/', '', $body['scope'] ) : '';
        self::back( $return, '', 'Пользовательский токен VK ID подключён.' . ( $scope ? ' Права: ' . $scope . '.' : '' ) );
    }

    private static function back( $return, $error, $notice = '' ) {
        $url = add_query_arg( array_filter( array(
            'vkt_vkid' => $error ? 'error' : 'ok',
            'vkt_vkid_message' => rawurlencode( $error ?: $notice ),
        ) ), $return );
        wp_safe_redirect( $url );
        exit;
    }

    /** Сообщение о результате подключения для дашборда. */
    public static function notice() {
        if ( empty( $_GET['vkt_vkid'] ) || ! current_user_can( 'manage_options' ) ) {
            return null;
        }
        $message = isset( $_GET['vkt_vkid_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['vkt_vkid_message'] ) ) ) : '';
        return array(
            'error' => 'ok' !== $_GET['vkt_vkid'],
            'message' => mb_substr( $message, 0, 240 ) ?: 'Подключение VK ID завершено.',
        );
    }
}

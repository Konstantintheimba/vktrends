<?php
defined( 'ABSPATH' ) || exit;

/**
 * Вход в личный кабинет через VK ID.
 *
 * Работает на приложении из кабинета VK ID (OAuth 2.1 + PKCE): оно принимает
 * возврат на свой домен и сообщает ID пользователя VK. Прав на стены и группы
 * этот вход не даёт и не просит — токен для публикации каждый получает сам,
 * обменом кода в разделе «Публикация» через общее приложение сайта.
 *
 * Состояние входа хранится в подписанной cookie, а не в базе: кнопку жмёт
 * аноним, и каждое нажатие не должно оставлять запись в wp_options.
 */
final class VKT_Login {
    const COOKIE = 'vkt_login';
    const TTL = 900;
    const SCOPE = 'vkid.personal_info';
    const USER_INFO = 'https://id.vk.ru/oauth2/user_info';
    // Заявки копятся, пока их не разберёт администратор: без потолка ими можно завалить сайт.
    const PENDING_LIMIT = 200;

    public static function boot() {
        add_action( 'admin_post_nopriv_vkt_login', array( self::class, 'start' ) );
        add_action( 'admin_post_vkt_login', array( self::class, 'start' ) );
        // Раньше захвата токена VK ID и проверки доступа к дашборду.
        add_action( 'template_redirect', array( self::class, 'maybe_capture' ), 0 );
        add_action( 'admin_init', array( self::class, 'keep_out_of_admin' ) );
        add_filter( 'show_admin_bar', static function ( $show ) {
            return self::member_only() ? false : $show;
        } );
        add_action( 'delete_user', array( 'VKT_Account', 'purge' ) );
    }

    public static function available() {
        return VKT_VKID::configured();
    }

    public static function url() {
        return admin_url( 'admin-post.php?action=vkt_login' );
    }

    /** Участник без прав редактора: ему нечего делать в консоли WordPress. */
    private static function member_only() {
        return is_user_logged_in() && current_user_can( VKT_Account::CAP ) && ! current_user_can( 'edit_posts' );
    }

    public static function keep_out_of_admin() {
        global $pagenow;
        if ( wp_doing_ajax() || in_array( $pagenow, array( 'admin-post.php', 'async-upload.php' ), true ) || ! self::member_only() ) {
            return;
        }
        wp_safe_redirect( VKT_Plugin::dashboard_url() );
        exit;
    }

    private static function base64url( $raw ) {
        return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
    }

    private static function sign( $payload ) {
        return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . '|vkt_login' );
    }

    private static function set_cookie( $value, $expires ) {
        setcookie( self::COOKIE, $value, array(
            'expires' => $expires,
            'path' => COOKIEPATH ? COOKIEPATH : '/',
            'domain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            // Возврат из VK — переход верхнего уровня, Lax его пропускает.
            'samesite' => 'Lax',
        ) );
    }

    /** Состояние входа из cookie, если подпись и срок в порядке. */
    private static function read_cookie() {
        $raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
        $parts = explode( '.', $raw );
        if ( 2 !== count( $parts ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
            return null;
        }
        $data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
        if ( ! is_array( $data ) || empty( $data['s'] ) || empty( $data['v'] ) || (int) ( $data['e'] ?? 0 ) < time() ) {
            return null;
        }
        return $data;
    }

    /** Кнопка «Войти через VK ID»: PKCE-пара и state уходят в cookie, браузер — в VK. */
    public static function start() {
        if ( ! self::available() ) {
            self::back( 'error', 'Вход через VK ID ещё не настроен: администратору нужно указать ID приложения VK ID.' );
        }
        $verifier = self::base64url( random_bytes( 48 ) );
        $state = self::base64url( random_bytes( 24 ) );
        $payload = self::base64url( wp_json_encode( array( 's' => $state, 'v' => $verifier, 'e' => time() + self::TTL ) ) );
        self::set_cookie( $payload . '.' . self::sign( $payload ), time() + self::TTL );
        // Адрес внешний, поэтому wp_redirect, а не wp_safe_redirect.
        wp_redirect( VKT_VKID::AUTHORIZE . '?' . http_build_query( array(
            'response_type' => 'code',
            'client_id' => VKT_VKID::client_id(),
            'code_challenge' => self::base64url( hash( 'sha256', $verifier, true ) ),
            'code_challenge_method' => 'S256',
            'redirect_uri' => VKT_VKID::redirect_uri(),
            'state' => $state,
            'scope' => self::SCOPE,
        ), '', '&', PHP_QUERY_RFC3986 ) );
        exit;
    }

    /**
     * Возврат VK ID ловится на любой странице сайта. Свой возврат опознаём по
     * state из cookie: чужой state — это подключение токена VK ID в разделе
     * «Публикация», его обрабатывает VKT_VKID.
     */
    public static function maybe_capture() {
        if ( empty( $_GET['state'] ) || ( empty( $_GET['code'] ) && empty( $_GET['error'] ) ) || is_admin() ) {
            return;
        }
        $cookie = self::read_cookie();
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
        if ( ! $cookie || ! hash_equals( (string) $cookie['s'], $state ) ) {
            return;
        }
        // Cookie одноразовая: повторный заход по той же ссылке ничего не даст.
        self::set_cookie( '', time() - HOUR_IN_SECONDS );
        self::finish( (string) $cookie['v'], $state );
    }

    private static function finish( $verifier, $state ) {
        $vk_error = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : ( isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '' );
        if ( '' !== $vk_error ) {
            self::back( 'error', 'VK ID отклонил вход: ' . mb_substr( $vk_error, 0, 160 ) );
        }
        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        $device_id = isset( $_GET['device_id'] ) ? sanitize_text_field( wp_unslash( $_GET['device_id'] ) ) : '';
        if ( ! preg_match( '/^[a-zA-Z0-9._\-]{8,1024}$/', $code ) || ! preg_match( '/^[a-zA-Z0-9._\-]{4,1024}$/', $device_id ) ) {
            self::back( 'error', 'VK ID не передал код входа. Попробуйте ещё раз.' );
        }
        $params = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => VKT_VKID::client_id(),
            'device_id' => $device_id,
            'redirect_uri' => VKT_VKID::redirect_uri(),
            'state' => $state,
        );
        if ( defined( 'VKT_VKID_CLIENT_SECRET' ) && '' !== trim( (string) VKT_VKID_CLIENT_SECRET ) ) {
            $params['client_secret'] = trim( (string) VKT_VKID_CLIENT_SECRET );
        }
        $response = wp_remote_post( VKT_VKID::TOKEN, array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'body' => $params ) );
        if ( is_wp_error( $response ) ) {
            VKT_Store::log( 'vkid.login', 'auth', 'error', 0, 'Нет связи с id.vk.ru при входе', 0 );
            self::back( 'error', 'Не удалось связаться с id.vk.ru. Повторите вход.' );
        }
        $http = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $http || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            $reason = is_array( $body ) ? sanitize_text_field( (string) ( $body['error_description'] ?? $body['error'] ?? '' ) ) : '';
            VKT_Store::log( 'vkid.login', 'auth', 'error', $http, 'VK ID отклонил обмен кода входа', 0 );
            self::back( 'error', 'VK ID не подтвердил вход' . ( $reason ? ': ' . mb_substr( $reason, 0, 160 ) : ' (HTTP ' . $http . ').' ) );
        }
        // Токен VK ID нужен только здесь — узнать, кто вошёл. Он не сохраняется.
        $profile = self::user_info( (string) $body['access_token'] );
        $vk_id = absint( $body['user_id'] ?? 0 ) ?: absint( $profile['user_id'] ?? 0 );
        if ( ! $vk_id ) {
            self::back( 'error', 'VK ID не сообщил, кто вошёл. Повторите вход.' );
        }
        $user_id = self::user_for( $vk_id, $profile );
        if ( is_wp_error( $user_id ) ) {
            self::back( 'error', $user_id->get_error_message() );
        }
        $user = get_userdata( $user_id );
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true, is_ssl() );
        update_user_meta( $user_id, 'vkt_last_login', gmdate( 'Y-m-d H:i:s' ) );
        do_action( 'wp_login', $user->user_login, $user );
        VKT_Store::log( 'vkid.login', 'auth', 'ok', 0, 'Вход через VK ID', 0 );
        self::back( 'ok', '' );
    }

    /** Имя и аватар. Сбой не мешает входу: ID уже пришёл вместе с токеном. */
    private static function user_info( $access_token ) {
        $response = wp_remote_post( self::USER_INFO, array(
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 65536,
            'body' => array( 'client_id' => VKT_VKID::client_id(), 'access_token' => $access_token ),
        ) );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return array();
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body['user'] ?? null ) ? $body['user'] : array();
    }

    /** Учётная запись по ID VK: находим прежнюю или заводим новую заявку. */
    private static function user_for( $vk_id, $profile ) {
        $found = get_users( array( 'meta_key' => 'vkt_vk_id', 'meta_value' => (string) $vk_id, 'number' => 1, 'fields' => 'ID' ) );
        $user_id = absint( $found[0] ?? 0 );
        $first = sanitize_text_field( (string) ( $profile['first_name'] ?? '' ) );
        $last = sanitize_text_field( (string) ( $profile['last_name'] ?? '' ) );
        $name = trim( $first . ' ' . $last );
        if ( ! $user_id ) {
            if ( VKT_Account::pending_count() >= self::PENDING_LIMIT ) {
                return new WP_Error( 'vkt_login', 'Сейчас слишком много заявок ждут одобрения. Попробуйте позже.' );
            }
            // Роль могли удалить вручную — без неё новый пользователь остался бы без доступа.
            VKT_Account::ensure_role();
            $login = 'vk' . $vk_id;
            if ( username_exists( $login ) ) {
                $login .= '_' . strtolower( wp_generate_password( 5, false, false ) );
            }
            $user_id = wp_insert_user( array(
                'user_login' => $login,
                'user_pass' => wp_generate_password( 32, true, true ),
                'display_name' => '' !== $name ? $name : 'VK ' . $vk_id,
                'nickname' => '' !== $name ? $name : $login,
                'first_name' => $first,
                'last_name' => $last,
                'role' => VKT_Account::ROLE,
            ) );
            if ( is_wp_error( $user_id ) ) {
                return new WP_Error( 'vkt_login', 'Не удалось создать учётную запись: ' . $user_id->get_error_message() );
            }
            update_user_meta( $user_id, 'vkt_vk_id', $vk_id );
            update_user_meta( $user_id, 'vkt_status', 'pending' );
            self::notify_admin( $user_id, '' !== $name ? $name : 'VK ' . $vk_id, $vk_id );
        } elseif ( '' !== $name ) {
            // Имя в VK могли сменить — держим кабинет в актуальном виде.
            wp_update_user( array( 'ID' => $user_id, 'display_name' => $name, 'first_name' => $first, 'last_name' => $last ) );
        }
        $avatar = esc_url_raw( (string) ( $profile['avatar'] ?? '' ), array( 'https' ) );
        if ( '' !== $avatar ) {
            update_user_meta( $user_id, 'vkt_avatar', $avatar );
        }
        if ( 'blocked' === VKT_Account::status( $user_id ) ) {
            return new WP_Error( 'vkt_login', 'Доступ к кабинету закрыт администратором.' );
        }
        return (int) $user_id;
    }

    private static function notify_admin( $user_id, $name, $vk_id ) {
        $to = (string) get_option( 'admin_email' );
        if ( '' === $to ) {
            return;
        }
        wp_mail(
            $to,
            'VK Trends: новая заявка на кабинет',
            $name . ' (vk.com/id' . absint( $vk_id ) . ') вошёл через VK ID и ждёт одобрения.' . "\n\n"
            . 'Одобрить или отклонить: ' . VKT_Plugin::dashboard_url() . '#users' . "\n"
        );
    }

    private static function back( $status, $message ) {
        $args = array( 'vkt_auth' => $status );
        if ( '' !== $message ) {
            // wp_safe_redirect вырезает пробелы и кириллицу, поэтому кодируем сами.
            $args['vkt_auth_message'] = rawurlencode( $message );
        }
        wp_safe_redirect( add_query_arg( $args, VKT_Plugin::dashboard_url() ) );
        exit;
    }

    /** Сообщение об ошибке входа для экрана входа. */
    public static function notice() {
        if ( empty( $_GET['vkt_auth'] ) || 'error' !== $_GET['vkt_auth'] ) {
            return '';
        }
        $message = isset( $_GET['vkt_auth_message'] ) ? sanitize_text_field( wp_unslash( $_GET['vkt_auth_message'] ) ) : '';
        return '' !== $message ? mb_substr( $message, 0, 240 ) : 'Вход не удался. Попробуйте ещё раз.';
    }
}

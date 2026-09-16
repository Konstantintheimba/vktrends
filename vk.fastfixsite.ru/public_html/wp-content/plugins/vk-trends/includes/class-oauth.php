<?php
defined( 'ABSPATH' ) || exit;

/**
 * Классический Authorization Code Flow ВКонтакте.
 *
 * Единственный найденный способ получить пользовательский токен с правами
 * wall, photos и groups, который работает с сервера:
 *
 * - Implicit Flow отдаёт токен в браузер, и VK привязывает его к IP браузера
 *   («access_token was given to another ip address»), поэтому на сервере он
 *   бесполезен, а право offline, снимавшее привязку, отменено;
 * - VK ID (OAuth 2.1) меняет код на сервере, но выдаёт токен с правами VK ID
 *   (email, phone) — классические методы отвечают «unavailable with current
 *   profile type»;
 * - здесь браузер получает только одноразовый код, а на токен его меняет сам
 *   сервер своим защищённым ключом — значит и права классические, и привязка
 *   приходится на IP сервера.
 */
final class VKT_OAuth {
    const AUTHORIZE = 'https://oauth.vk.com/authorize';
    const EXCHANGE = 'https://oauth.vk.com/access_token';
    const REDIRECT = 'https://oauth.vk.com/blank.html';
    const SCOPE = 'wall,photos,groups,video,offline';

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_oauth', $message, array( 'status' => $status ) );
    }

    public static function app_id() {
        if ( defined( 'VKT_APP_ID' ) && absint( VKT_APP_ID ) ) {
            return absint( VKT_APP_ID );
        }
        return absint( VKT_Plugin::settings()['app_id'] ?? 0 );
    }

    public static function configured() {
        return self::app_id() > 0 && VKT_Tokens::has( 'app_secret' );
    }

    /** Права запрашиваем без offline: VK его отменил и отвечает invalid scope. */
    public static function scope() {
        return 'wall,photos,groups,video';
    }

    public static function public_status() {
        return array(
            'app_id' => self::app_id(),
            'has_secret' => VKT_Tokens::has( 'app_secret' ),
            'configured' => self::configured(),
            'redirect' => self::REDIRECT,
            'scope' => self::scope(),
            'authorize_url' => self::app_id() ? self::authorize_url() : '',
        );
    }

    /** Адрес согласия: браузер вернётся на blank.html с кодом в строке запроса. */
    public static function authorize_url() {
        return self::AUTHORIZE . '?' . http_build_query( array(
            'client_id' => self::app_id(),
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => self::scope(),
            'display' => 'page',
            'v' => VKT_Plugin::settings()['api_version'],
        ), '', '&', PHP_QUERY_RFC3986 );
    }

    /**
     * Проверяет приложение до перехода в VK.
     *
     * Приложения из кабинета VK ID классический OAuth не обслуживает и отвечает
     * «Security Error». Ошибку видно только после перехода, поэтому спрашиваем
     * VK заранее и объясняем, какое приложение нужно.
     */
    public static function check_app() {
        if ( ! self::app_id() ) {
            return self::error( 'Укажите ID приложения.' );
        }
        $response = wp_remote_get( self::authorize_url(), array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536 ) );
        if ( is_wp_error( $response ) ) {
            return self::error( 'Не удалось связаться с oauth.vk.com.', 502 );
        }
        $body = (string) wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );
        if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
            $reason = sanitize_text_field( (string) ( $decoded['error_description'] ?? $decoded['error'] ) );
            if ( false !== stripos( $reason, 'security error' ) ) {
                return self::error( 'Приложение ' . self::app_id() . ' не обслуживает классический OAuth. Так отвечают приложения из кабинета VK ID — здесь нужен ID приложения из консоли dev.vk.ru, это другое число.', 422 );
            }
            if ( false !== stripos( $reason, 'redirect_uri' ) ) {
                return self::error( 'Приложение ' . self::app_id() . ' не принимает адрес возврата ' . self::REDIRECT . '. Проверьте, что это приложение из dev.vk.ru.', 422 );
            }
            return self::error( 'VK отклонил запрос: ' . mb_substr( $reason, 0, 180 ), 422 );
        }
        return array( 'ok' => true, 'app_id' => self::app_id(), 'authorize_url' => self::authorize_url() );
    }

    /**
     * Проверяет пару «приложение + защищённый ключ», не тратя настоящий код.
     *
     * VK сверяет client_id и client_secret раньше, чем сам код, поэтому с заведомо
     * негодным кодом ответ различается: жалоба на client_secret означает неверный
     * ключ, жалоба на код — что ключ принят.
     */
    public static function check_secret() {
        if ( ! self::configured() ) {
            return self::error( 'Укажите ID приложения и его защищённый ключ.' );
        }
        $response = wp_remote_get( self::EXCHANGE . '?' . http_build_query( array(
            'client_id' => self::app_id(),
            'client_secret' => VKT_Tokens::token( 'app_secret' ),
            'redirect_uri' => self::REDIRECT,
            'code' => 'vkt-probe-not-a-real-code',
        ), '', '&', PHP_QUERY_RFC3986 ), array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536 ) );
        if ( is_wp_error( $response ) ) {
            return self::error( 'Не удалось связаться с oauth.vk.com.', 502 );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $reason = is_array( $body ) ? strtolower( (string) ( $body['error_description'] ?? $body['error'] ?? '' ) ) : '';
        if ( str_contains( $reason, 'client_secret' ) || str_contains( $reason, 'client id' ) || str_contains( $reason, 'client_id' ) ) {
            VKT_Tokens::note( 'app_secret', 'VK не принял защищённый ключ приложения ' . self::app_id() . '.' );
            return self::error( 'VK не принял защищённый ключ. Нужен ключ приложения ' . self::app_id() . ' из консоли dev.vk.ru → Разработка → Ключи доступа → «Защищённый ключ». Ключ другого приложения и перевыпущенный старый не подойдут.', 422 );
        }
        VKT_Tokens::note( 'app_secret', '' );
        return array(
            'slot' => 'app_secret',
            'title' => 'Защищённый ключ приложения',
            'ok' => true,
            'checks' => array( array(
                'method' => 'oauth.access_token',
                'label' => 'Приложение ' . self::app_id() . ' и его защищённый ключ',
                'ok' => true,
                'code' => 0,
                'message' => 'Пара принята: VK ругается только на пробный код, а не на ключ. Можно менять настоящий код.',
            ) ),
        );
    }

    /** Достаёт код из вставленного адреса: выцеплять его руками легко ошибиться. */
    public static function parse_code( $raw ) {
        $raw = trim( (string) $raw );
        if ( preg_match( '~[?&#]code=([a-zA-Z0-9._\-]{8,512})~', $raw, $match ) ) {
            return $match[1];
        }
        return preg_match( '/^[a-zA-Z0-9._\-]{8,512}$/', $raw ) ? $raw : '';
    }

    /**
     * Меняет код на токен запросом с сервера и кладёт результат в слот user.
     * Код одноразовый и живёт около минуты, поэтому повтор требует нового.
     */
    public static function exchange( $raw_code ) {
        if ( ! self::configured() ) {
            return self::error( 'Укажите ID приложения и его защищённый ключ.' );
        }
        $code = self::parse_code( $raw_code );
        if ( '' === $code ) {
            return self::error( 'В строке нет кода авторизации. Вставьте адрес целиком — в нём должно быть code=…' );
        }
        $response = wp_remote_get( self::EXCHANGE . '?' . http_build_query( array(
            'client_id' => self::app_id(),
            'client_secret' => VKT_Tokens::token( 'app_secret' ),
            'redirect_uri' => self::REDIRECT,
            'code' => $code,
        ), '', '&', PHP_QUERY_RFC3986 ), array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536 ) );
        if ( is_wp_error( $response ) ) {
            VKT_Store::log( 'oauth.access_token', 'refresh', 'error', 0, 'Нет связи с oauth.vk.com', 0 );
            return self::error( 'Не удалось связаться с oauth.vk.com.', 502 );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return self::error( 'oauth.vk.com вернул нечитаемый ответ.', 502 );
        }
        if ( empty( $body['access_token'] ) ) {
            $reason = sanitize_text_field( (string) ( $body['error_description'] ?? $body['error'] ?? '' ) );
            VKT_Store::log( 'oauth.access_token', 'refresh', 'error', (int) wp_remote_retrieve_response_code( $response ), 'Обмен кода отклонён', 0 );
            // Виноват ключ или код — подсказки разные, и путать их нельзя.
            if ( str_contains( strtolower( $reason ), 'client_secret' ) || str_contains( strtolower( $reason ), 'client_id' ) ) {
                VKT_Tokens::note( 'app_secret', 'VK не принял защищённый ключ приложения ' . self::app_id() . '.' );
                return self::error( 'VK не принял защищённый ключ приложения ' . self::app_id() . '. Возьмите актуальный в dev.vk.ru → Разработка → Ключи доступа → «Защищённый ключ» и сохраните его в слоте «Защищённый ключ приложения». Код при этом не тратится — он всё ещё нужен новый.', 422 );
            }
            return self::error( 'VK отклонил обмен кода' . ( $reason ? ': ' . mb_substr( $reason, 0, 180 ) : '.' ) . ' Код одноразовый и живёт около минуты — получите новый.', 422 );
        }
        $saved = VKT_Tokens::save( 'user', (string) $body['access_token'], array(
            'expires_in' => absint( $body['expires_in'] ?? 0 ),
            'scope' => self::scope(),
        ) );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        VKT_Store::log( 'oauth.access_token', 'refresh', 'ok', 0, 'Пользовательский токен получен обменом кода', 0 );
        // Сразу показываем, что этот токен реально умеет.
        return VKT_API::probe_slot( 'user' );
    }
}

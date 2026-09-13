<?php
defined( 'ABSPATH' ) || exit;

final class VKT_API {
    public static function methods() {
        static $methods;
        if ( null === $methods ) {
            $methods = json_decode( file_get_contents( VKT_DIR . 'includes/methods.json' ), true );
        }
        return $methods;
    }

    // Пустые значения для полей, которых нет у сервисного ключа.
    private static function empty_payload() {
        return array( 'kind' => 'service', 'access_token' => '', 'refresh_token' => '', 'expires_at' => 0, 'device_id' => '', 'client_id' => '' );
    }

    // Расшифровывает и возвращает полную структуру токена. Понимает старый формат,
    // где в шифре лежала просто строка сервисного ключа — не JSON-структура.
    private static function token_data() {
        if ( defined( 'VKT_ACCESS_TOKEN' ) ) {
            return array_merge( self::empty_payload(), array( 'access_token' => trim( VKT_ACCESS_TOKEN ) ) );
        }
        $encrypted = get_option( 'vkt_token', '' );
        if ( ! $encrypted || ! function_exists( 'openssl_decrypt' ) ) {
            return array();
        }
        $data = json_decode( $encrypted, true );
        if ( ! is_array( $data ) || ! isset( $data['value'], $data['iv'], $data['tag'] ) ) {
            return array();
        }
        $decrypted = (string) openssl_decrypt( base64_decode( $data['value'] ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, base64_decode( $data['iv'] ), base64_decode( $data['tag'] ) );
        if ( '' === $decrypted ) {
            return array();
        }
        $payload = json_decode( $decrypted, true );
        if ( ! is_array( $payload ) || ! isset( $payload['access_token'] ) ) {
            // Обратная совместимость: раньше здесь хранился обычный текст сервисного ключа.
            return array_merge( self::empty_payload(), array( 'access_token' => $decrypted ) );
        }
        return wp_parse_args( $payload, self::empty_payload() );
    }

    public static function token() {
        $data = self::token_data();
        return (string) ( $data['access_token'] ?? '' );
    }

    // Тип действующего токена: 'service', 'user' либо '' — если токена нет вовсе.
    public static function mode() {
        $data = self::token_data();
        return '' === ( $data['access_token'] ?? '' ) ? '' : $data['kind'];
    }

    // Безопасный статус токена для интерфейса — без access/refresh_token и прочих секретов.
    public static function status() {
        $data = self::token_data();
        if ( '' === ( $data['access_token'] ?? '' ) ) {
            return array( 'has_token' => false, 'mode' => '', 'expires_in' => null, 'refreshable' => false );
        }
        $expires_in = null;
        if ( 'user' === $data['kind'] && $data['expires_at'] ) {
            $expires_in = max( 0, (int) $data['expires_at'] - time() );
        }
        return array(
            'has_token' => true,
            'mode' => $data['kind'],
            'expires_in' => $expires_in,
            'refreshable' => 'user' === $data['kind'] && '' !== $data['refresh_token'] && '' !== $data['device_id'] && '' !== $data['client_id'],
        );
    }

    /**
     * Положительно распознаёт ключ сообщества перед сохранением. Ошибка сети или
     * отказ метода считаются неопределённым типом и не блокируют пользовательский
     * токен: только успешный groups.getTokenPermissions доказывает group-token.
     */
    public static function detect_token_kind( $token ) {
        $token = is_string( $token ) ? trim( $token ) : '';
        if ( '' === $token ) {
            return 'unknown';
        }
        $response = wp_remote_post( 'https://api.vk.com/method/groups.getTokenPermissions', array(
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 65536,
            'body' => array(
                'access_token' => $token,
                'v' => VKT_Plugin::settings()['api_version'],
            ),
        ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return 'unknown';
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) && array_key_exists( 'response', $body ) ? 'community' : 'unknown';
    }

    private static function store_payload( $payload ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return new WP_Error( 'encryption', 'На сервере нужен OpenSSL для хранения токена.', array( 'status' => 500 ) );
        }
        $iv = random_bytes( 12 );
        $tag = '';
        $value = openssl_encrypt( wp_json_encode( $payload ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $value ) {
            return new WP_Error( 'encryption', 'Не удалось зашифровать токен.', array( 'status' => 500 ) );
        }
        update_option( 'vkt_token', wp_json_encode( array( 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'value' => base64_encode( $value ) ) ), false );
        return true;
    }

    // $kind: 'service' — обычный ключ доступа. 'user' — пользовательский токен;
    // для автообновления $extra должен целиком содержать refresh_token, device_id и client_id.
    public static function save_token( $token, $kind = 'service', $extra = array() ) {
        if ( defined( 'VKT_ACCESS_TOKEN' ) ) {
            return new WP_Error( 'constant_token', 'Токен задан в wp-config.php. Измените его там.', array( 'status' => 400 ) );
        }
        $kind = in_array( $kind, array( 'service', 'user' ), true ) ? $kind : 'service';
        $payload = self::empty_payload();
        $payload['kind'] = $kind;
        $payload['access_token'] = $token;
        if ( 'user' === $kind ) {
            $payload['refresh_token'] = (string) ( $extra['refresh_token'] ?? '' );
            $payload['device_id'] = (string) ( $extra['device_id'] ?? '' );
            $payload['client_id'] = (string) ( $extra['client_id'] ?? '' );
            $payload['expires_at'] = ! empty( $extra['expires_in'] ) ? time() + (int) $extra['expires_in'] : 0;
            $refresh_fields = array_filter( array( $payload['refresh_token'], $payload['device_id'], $payload['client_id'] ), static fn( $value ) => '' !== $value );
            if ( $refresh_fields && 3 !== count( $refresh_fields ) ) {
                return new WP_Error( 'user_token_incomplete', 'Данные автообновления указываются комплектом: refresh_token, device_id и client_id.', array( 'status' => 400 ) );
            }
        }
        return self::store_payload( $payload );
    }

    // Перед запросом обновляет пользовательскую пару токенов, если срок годности истекает
    // меньше чем через 5 минут. Сервисный ключ не истекает — обновлять нечего.
    public static function maybe_refresh() {
        $data = self::token_data();
        if ( '' === ( $data['access_token'] ?? '' ) || 'user' !== $data['kind'] ) {
            return true;
        }
        if ( ! $data['expires_at'] || $data['expires_at'] - time() > 300 ) {
            return true;
        }
        if ( ! VKT_Store::lock( 'token_refresh', 20 ) ) {
            // Токен уже обновляет другой запрос — работаем со старым значением, пока не истёк совсем.
            return true;
        }
        try {
            // Пока ждали блокировку, токен мог обновиться в другом потоке.
            $data = self::token_data();
            if ( '' === ( $data['access_token'] ?? '' ) || 'user' !== $data['kind'] || ! $data['expires_at'] || $data['expires_at'] - time() > 300 ) {
                return true;
            }
            if ( '' === $data['refresh_token'] || '' === $data['client_id'] || '' === $data['device_id'] ) {
                return new WP_Error( 'refresh_incomplete', 'Не хватает данных для автообновления пользовательского токена VK ID. Сохраните его заново.', array( 'status' => 400 ) );
            }
            $response = wp_remote_post( 'https://id.vk.ru/oauth2/auth', array(
                'timeout' => 15, 'redirection' => 0, 'sslverify' => true,
                'body' => array(
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $data['refresh_token'],
                    'client_id' => $data['client_id'],
                    'device_id' => $data['device_id'],
                ),
            ) );
            if ( is_wp_error( $response ) ) {
                VKT_Store::log( 'oauth2.auth', 'refresh', 'error', 0, 'Нет связи с id.vk.ru для обновления токена', 0 );
                return new WP_Error( 'refresh_transport', 'Не удалось обновить пользовательский токен: нет связи с id.vk.ru.', array( 'status' => 502, 'retryable' => true ) );
            }
            $http = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( 200 !== $http || ! is_array( $body ) || empty( $body['access_token'] ) ) {
                VKT_Store::log( 'oauth2.auth', 'refresh', 'error', (int) $http, 'VK ID отклонил обновление токена', 0 );
                return new WP_Error( 'refresh_failed', 'VK ID отклонил обновление токена. Сохраните пользовательский токен заново.', array( 'status' => 401 ) );
            }
            $saved = self::save_token( $body['access_token'], 'user', array(
                'refresh_token' => $body['refresh_token'] ?? $data['refresh_token'],
                'expires_in' => $body['expires_in'] ?? 3600,
                'device_id' => $body['device_id'] ?? $data['device_id'],
                'client_id' => $data['client_id'],
            ) );
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }
            VKT_Store::log( 'oauth2.auth', 'refresh', 'ok', 0, 'Пользовательский токен обновлён', 0 );
            return true;
        } finally {
            VKT_Store::unlock( 'token_refresh' );
        }
    }

    private static function message( $code ) {
        $messages = array(
            5 => 'VK: авторизация не прошла. Проверьте токен и срок его действия.',
            6 => 'VK: слишком много запросов. Повтор будет доступен позже.',
            7 => 'VK: токену не хватает разрешений для этого метода.',
            9 => 'VK: временное ограничение частоты запросов.',
            10 => 'VK: внутренняя ошибка. Попробуйте позже.',
            14 => 'VK требует CAPTCHA. Автоматический обход не выполняется.',
            17 => 'VK требует дополнительного подтверждения пользователя.',
            15 => 'VK: доступ к объекту запрещён.',
            27 => 'VK: этот метод недоступен с токеном сообщества.',
            28 => 'VK: этот метод недоступен с сервисным токеном. Нужен пользовательский.',
            29 => 'VK: исчерпан лимит запросов. Повтор будет выполнен позже.',
            100 => 'VK: неверные или недостающие параметры. Сверьте их со справкой метода.',
            113 => 'VK: неверный идентификатор пользователя.',
            210 => 'VK: видео недоступно или удалено.',
            214 => 'VK: доступ к публикации на этой стене запрещён. Проверьте роль в сообществе и право wall у токена.',
            219 => 'VK: рекламная запись была опубликована недавно.',
            220 => 'VK: слишком много адресатов публикации.',
            222 => 'VK: сообщество запретило ссылки в записи.',
            224 => 'VK: достигнут лимит рекламных записей.',
            225 => 'VK: для этой записи недоступен VK Donut.',
            260 => 'VK: доступ к списку сообществ ограничен настройками приватности.',
            1117 => 'VK: срок действия токена истёк.',
        );
        return $messages[ $code ] ?? 'VK вернул ошибку ' . $code . '. Проверьте доступ и параметры метода.';
    }

    private static function redact( $value, $token ) {
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $key => $item ) {
                if ( preg_match( '/token|secret|access_key|request_params|authorization/i', (string) $key ) ) {
                    continue;
                }
                $clean[ $key ] = self::redact( $item, $token );
            }
            return $clean;
        }
        return is_string( $value ) ? str_replace( $token, '[hidden]', $value ) : $value;
    }

    /**
     * Запросы, которые нужны модулю публикаций, но принципиально не выдаются в
     * открытый каталог «Тест API». Список закрытый: произвольный метод или
     * access_token из браузера сюда передать нельзя.
     */
    public static function publishing_request( $method, $params = array() ) {
        $allowed = array(
            'groups.get' => array( 'extended', 'filter', 'fields', 'count', 'offset' ),
            'wall.post'  => array( 'owner_id', 'from_group', 'message', 'attachments', 'signed', 'close_comments', 'guid' ),
        );
        if ( ! isset( $allowed[ $method ] ) ) {
            return new WP_Error( 'method', 'Метод не разрешён модулю публикаций.', array( 'status' => 400 ) );
        }
        if ( 'user' !== self::mode() ) {
            return new WP_Error( 'publishing_token', 'Для публикации нужен пользовательский токен VK ID с правами wall и groups.', array( 'status' => 400 ) );
        }
        if ( ! is_array( $params ) || count( $params ) > 12 ) {
            return new WP_Error( 'params', 'Неверные параметры публикации.', array( 'status' => 400 ) );
        }
        foreach ( $params as $key => &$value ) {
            $max_bytes = 'message' === $key ? 70000 : 20000;
            if ( ! in_array( $key, $allowed[ $method ], true ) || ! is_scalar( $value ) || strlen( (string) $value ) > $max_bytes ) {
                return new WP_Error( 'params', 'Неверный параметр запроса публикации.', array( 'status' => 400 ) );
            }
            if ( is_bool( $value ) ) {
                $value = $value ? 1 : 0;
            }
        }
        unset( $value );
        return self::dispatch( $method, $params, 'publisher' );
    }

    public static function request( $method, $params = array(), $context = 'test' ) {
        $methods = self::methods();
        if ( ! isset( $methods[ $method ] ) ) {
            return new WP_Error( 'method', 'Метод не входит в разрешённый список чтения.', array( 'status' => 400 ) );
        }
        if ( ! is_array( $params ) || count( $params ) > 40 ) {
            return new WP_Error( 'params', 'Параметры должны быть JSON-объектом.', array( 'status' => 400 ) );
        }
        $allowed = array_column( $methods[ $method ]['parameters'], 'name' );
        foreach ( $params as $key => &$value ) {
            if ( ! in_array( $key, $allowed, true ) || ! is_scalar( $value ) || strlen( (string) $value ) > 8000 ) {
                return new WP_Error( 'params', 'Неизвестный параметр или неверный формат. Списки передавайте строкой через запятую.', array( 'status' => 400 ) );
            }
            if ( is_bool( $value ) ) {
                $value = $value ? 1 : 0;
            }
        }
        unset( $value );
        foreach ( $methods[ $method ]['parameters'] as $definition ) {
            if ( ! empty( $definition['required'] ) && ( ! isset( $params[ $definition['name'] ] ) || '' === $params[ $definition['name'] ] ) ) {
                return new WP_Error( 'params', 'Обязательный параметр: ' . $definition['name'], array( 'status' => 400 ) );
            }
        }
        // Bound response size and forbid tokens/version/execute through custom parameters.
        if ( isset( $params['count'] ) ) {
            $params['count'] = max( 1, min( 100, (int) $params['count'] ) );
        }
        return self::dispatch( $method, $params, $context );
    }

    private static function dispatch( $method, $params, $context ) {
        $refreshed = self::maybe_refresh();
        if ( is_wp_error( $refreshed ) ) {
            return $refreshed;
        }
        $token = self::token();
        if ( '' === $token ) {
            return new WP_Error( 'no_token', 'Сначала сохраните Access token в настройках.', array( 'status' => 400 ) );
        }
        if ( ! VKT_Store::lock( 'api', 30 ) ) {
            return new WP_Error( 'rate_limit', 'Запрос уже выполняется. Подождите несколько секунд.', array( 'status' => 429, 'retryable' => true ) );
        }
        $started = microtime( true );
        try {
            $response = wp_remote_post( 'https://api.vk.com/method/' . $method, array(
                'timeout' => 15, 'redirection' => 0, 'sslverify' => true,
                'limit_response_size' => 2 * 1024 * 1024,
                'body' => array_merge( $params, array( 'access_token' => $token, 'v' => VKT_Plugin::settings()['api_version'] ) ),
            ) );
            $ms = (int) round( ( microtime( true ) - $started ) * 1000 );
            if ( is_wp_error( $response ) ) {
                $error = new WP_Error( 'transport', 'Не удалось подключиться к VK по HTTPS.', array( 'status' => 502, 'retryable' => true ) );
            } else {
                $http = wp_remote_retrieve_response_code( $response );
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( 200 !== $http ) {
                    $error = new WP_Error( 'http', 'VK вернул HTTP ' . $http . '.', array( 'status' => 502, 'retryable' => 429 === $http || $http >= 500 ) );
                } elseif ( ! is_array( $body ) || ( ! isset( $body['error'] ) && ! array_key_exists( 'response', $body ) ) ) {
                    $error = new WP_Error( 'json', 'Ответ VK не содержит корректный JSON или превышает 2 МБ. Уменьшите выборку.', array( 'status' => 502 ) );
                } elseif ( isset( $body['error'] ) ) {
                    $code = (int) ( $body['error']['error_code'] ?? 0 );
                    $error = new WP_Error( 'vk_' . $code, self::message( $code ), array( 'status' => 422, 'vk_code' => $code, 'retryable' => in_array( $code, array( 1, 6, 9, 10, 29, 32, 36 ), true ) ) );
                } else {
                    VKT_Store::log( $method, $context, 'ok', 0, 'Запрос выполнен', $ms );
                    return array( 'response' => self::redact( $body['response'], $token ), 'duration_ms' => $ms, 'method' => $method );
                }
            }
            $data = $error->get_error_data();
            $data['duration_ms'] = $ms;
            $error->add_data( $data );
            VKT_Store::log( $method, $context, 'error', $data['vk_code'] ?? 0, $error->get_error_message(), $ms );
            return $error;
        } finally {
            // Retain the lock briefly after completion: at most one request per second.
            update_option( 'vkt_lock_api', time() + 1, false );
        }
    }
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Хранилище нескольких ключей VK одновременно.
 *
 * У задач разные требования: сбор данных умеет сервисный ключ, публиковать текст
 * может ключ сообщества, а фото и видео принимает только пользовательский токен.
 * Раньше плагин держал один ключ, и подключение нового ломало предыдущую задачу.
 * Теперь каждый живёт в своём слоте, а вызов сам выбирает подходящий.
 */
final class VKT_Tokens {
    const OPTION = 'vkt_tokens';
    const ERRORS = 'vkt_token_errors';
    const LEGACY = 'vkt_token';

    /** Описание слотов для интерфейса и проверок. */
    public static function definitions() {
        return array(
            'service' => array(
                'title' => 'Сервисный ключ приложения',
                'hint' => 'Читает стены сообществ: посты, видео, счётчики. Публиковать и загружать файлы не умеет.',
                'constant' => 'VKT_ACCESS_TOKEN',
                'kind' => 'service',
                'probe' => 'wall.get',
            ),
            'user' => array(
                'title' => 'Пользовательский токен',
                'hint' => 'Единственный, кто может загружать фото и видео и получать список своих сообществ. Нужны права wall, photos, groups, video.',
                'constant' => '',
                'kind' => 'user',
                'probe' => 'users.get',
            ),
            'app_secret' => array(
                'title' => 'Защищённый ключ приложения',
                'hint' => 'Нужен, чтобы сервер сам менял код авторизации на токен. Берётся в dev.vk.ru → Разработка → Ключи доступа → «Защищённый ключ» — того же приложения, чей ID указан в блоке обмена кода. Ключ другого приложения VK не примет.',
                'constant' => 'VKT_APP_SECRET',
                'kind' => 'secret',
                'probe' => '',
            ),
            'community' => array(
                'title' => 'Ключ сообщества',
                'hint' => 'Публикует текст на стене своей группы. Медиа VK этому ключу запрещает.',
                'constant' => 'VKT_COMMUNITY_ACCESS_TOKEN',
                'kind' => 'community',
                'probe' => 'groups.getTokenPermissions',
            ),
        );
    }

    private static function blank() {
        return array( 'access_token' => '', 'refresh_token' => '', 'device_id' => '', 'client_id' => '', 'expires_at' => 0, 'scope' => '', 'saved_at' => 0 );
    }

    private static function key() {
        return hash( 'sha256', wp_salt( 'auth' ), true );
    }

    private static function decrypt( $encrypted ) {
        if ( ! $encrypted || ! function_exists( 'openssl_decrypt' ) ) {
            return null;
        }
        $data = json_decode( (string) $encrypted, true );
        if ( ! is_array( $data ) || ! isset( $data['value'], $data['iv'], $data['tag'] ) ) {
            return null;
        }
        $plain = openssl_decrypt( base64_decode( $data['value'] ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, base64_decode( $data['iv'] ), base64_decode( $data['tag'] ) );
        return is_string( $plain ) && '' !== $plain ? $plain : null;
    }

    private static function encrypt( $plain ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return new WP_Error( 'encryption', 'На сервере нужен OpenSSL для хранения ключей.', array( 'status' => 500 ) );
        }
        $iv = random_bytes( 12 );
        $tag = '';
        $value = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $value ) {
            return new WP_Error( 'encryption', 'Не удалось зашифровать ключ.', array( 'status' => 500 ) );
        }
        return wp_json_encode( array( 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'value' => base64_encode( $value ) ) );
    }

    /** Сохранённые слоты без учёта констант. */
    private static function stored() {
        $plain = self::decrypt( get_option( self::OPTION, '' ) );
        $map = null === $plain ? null : json_decode( $plain, true );
        if ( ! is_array( $map ) ) {
            $map = self::migrate_legacy();
        }
        $clean = array();
        foreach ( array_keys( self::definitions() ) as $slot ) {
            $clean[ $slot ] = wp_parse_args( is_array( $map[ $slot ] ?? null ) ? $map[ $slot ] : array(), self::blank() );
        }
        return $clean;
    }

    /** Разовый перенос единственного ключа старого формата в его слот. */
    private static function migrate_legacy() {
        $plain = self::decrypt( get_option( self::LEGACY, '' ) );
        if ( null === $plain ) {
            return array();
        }
        $payload = json_decode( $plain, true );
        if ( ! is_array( $payload ) || empty( $payload['access_token'] ) ) {
            // Совсем старый формат: в шифре лежала строка сервисного ключа.
            $payload = array( 'kind' => 'service', 'access_token' => $plain );
        }
        $slot = 'user' === ( $payload['kind'] ?? '' ) ? 'user' : 'service';
        $map = array( $slot => wp_parse_args( $payload, self::blank() ) );
        $encrypted = self::encrypt( wp_json_encode( $map ) );
        if ( ! is_wp_error( $encrypted ) ) {
            update_option( self::OPTION, $encrypted, false );
            delete_option( self::LEGACY );
        }
        return $map;
    }

    /** Значение слота с учётом константы: она всегда важнее сохранённого. */
    public static function get( $slot ) {
        $definitions = self::definitions();
        if ( ! isset( $definitions[ $slot ] ) ) {
            return self::blank();
        }
        $constant = $definitions[ $slot ]['constant'];
        if ( '' !== $constant && defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
            return array_merge( self::blank(), array( 'access_token' => trim( (string) constant( $constant ) ) ) );
        }
        $stored = self::stored();
        return $stored[ $slot ] ?? self::blank();
    }

    public static function token( $slot ) {
        return (string) self::get( $slot )['access_token'];
    }

    public static function has( $slot ) {
        return '' !== self::token( $slot );
    }

    public static function locked( $slot ) {
        $constant = self::definitions()[ $slot ]['constant'] ?? '';
        return '' !== $constant && defined( $constant ) && '' !== trim( (string) constant( $constant ) );
    }

    public static function save( $slot, $token, $extra = array() ) {
        if ( ! isset( self::definitions()[ $slot ] ) ) {
            return new WP_Error( 'slot', 'Неизвестный слот ключа.', array( 'status' => 400 ) );
        }
        if ( self::locked( $slot ) ) {
            return new WP_Error( 'constant_token', 'Этот ключ задан в wp-config.php — измените его там.', array( 'status' => 400 ) );
        }
        $token = trim( (string) $token );
        if ( ! preg_match( '/^[a-zA-Z0-9._\-]{20,2048}$/', $token ) ) {
            return new WP_Error( 'token', 'Ключ содержит недопустимые символы или слишком короткий.', array( 'status' => 400 ) );
        }
        $entry = array_merge( self::blank(), array(
            'access_token' => $token,
            'refresh_token' => (string) ( $extra['refresh_token'] ?? '' ),
            'device_id' => (string) ( $extra['device_id'] ?? '' ),
            'client_id' => (string) ( $extra['client_id'] ?? '' ),
            'scope' => preg_replace( '/[^a-z_, ]/', '', (string) ( $extra['scope'] ?? '' ) ),
            'expires_at' => ! empty( $extra['expires_in'] ) ? time() + max( 60, min( YEAR_IN_SECONDS, (int) $extra['expires_in'] ) ) : 0,
            'saved_at' => time(),
        ) );
        $refresh = array_filter( array( $entry['refresh_token'], $entry['device_id'], $entry['client_id'] ), static fn( $value ) => '' !== $value );
        if ( $refresh && 3 !== count( $refresh ) ) {
            return new WP_Error( 'incomplete', 'Данные автообновления указываются комплектом: refresh_token, device_id и client_id.', array( 'status' => 400 ) );
        }
        $map = self::stored();
        $map[ $slot ] = $entry;
        $encrypted = self::encrypt( wp_json_encode( $map ) );
        if ( is_wp_error( $encrypted ) ) {
            return $encrypted;
        }
        update_option( self::OPTION, $encrypted, false );
        self::note( $slot, '' );
        return true;
    }

    public static function forget( $slot ) {
        if ( self::locked( $slot ) ) {
            return new WP_Error( 'constant_token', 'Ключ задан в wp-config.php — удалите его там.', array( 'status' => 400 ) );
        }
        $map = self::stored();
        $map[ $slot ] = self::blank();
        $encrypted = self::encrypt( wp_json_encode( $map ) );
        if ( is_wp_error( $encrypted ) ) {
            return $encrypted;
        }
        update_option( self::OPTION, $encrypted, false );
        self::note( $slot, '' );
        return true;
    }

    /** Последняя ошибка слота остаётся на виду, пока её не сменит новая. */
    public static function note( $slot, $message ) {
        $errors = (array) get_option( self::ERRORS, array() );
        if ( '' === $message ) {
            unset( $errors[ $slot ] );
        } else {
            $errors[ $slot ] = array( 'message' => mb_substr( (string) $message, 0, 255 ), 'at' => gmdate( 'Y-m-d H:i:s' ) );
        }
        update_option( self::ERRORS, $errors, false );
    }

    public static function error( $slot ) {
        $errors = (array) get_option( self::ERRORS, array() );
        return is_array( $errors[ $slot ] ?? null ) ? $errors[ $slot ] : null;
    }

    private static function preview( $token ) {
        return mb_strlen( $token ) < 24 ? str_repeat( '•', 8 ) : mb_substr( $token, 0, 12 ) . '…' . mb_substr( $token, -6 );
    }

    /** Безопасная сводка по всем слотам: сами ключи наружу не уходят. */
    public static function status() {
        $out = array();
        foreach ( self::definitions() as $slot => $definition ) {
            $entry = self::get( $slot );
            $token = (string) $entry['access_token'];
            $out[] = array(
                'slot' => $slot,
                'title' => $definition['title'],
                'hint' => $definition['hint'],
                'constant' => $definition['constant'],
                'has_token' => '' !== $token,
                'locked' => self::locked( $slot ),
                'preview' => '' === $token ? '' : self::preview( $token ),
                'length' => mb_strlen( $token ),
                'scope' => (string) $entry['scope'],
                'expires_in' => $entry['expires_at'] ? max( 0, (int) $entry['expires_at'] - time() ) : null,
                'refreshable' => '' !== $entry['refresh_token'] && '' !== $entry['device_id'] && '' !== $entry['client_id'],
                'saved_at' => $entry['saved_at'] ? gmdate( 'Y-m-d H:i:s', (int) $entry['saved_at'] ) : null,
                'error' => self::error( $slot ),
            );
        }
        return $out;
    }
}

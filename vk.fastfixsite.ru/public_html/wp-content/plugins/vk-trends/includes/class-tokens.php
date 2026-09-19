<?php
defined( 'ABSPATH' ) || exit;

/**
 * Хранилище нескольких ключей VK одновременно.
 *
 * У задач разные требования: сбор данных умеет сервисный ключ, публиковать текст
 * может ключ сообщества, а фото и видео принимает только пользовательский токен.
 * Раньше плагин держал один ключ, и подключение нового ломало предыдущую задачу.
 * Теперь каждый живёт в своём слоте, а вызов сам выбирает подходящий.
 *
 * Слоты бывают общими и личными. Сервисный ключ и защищённый ключ приложения
 * принадлежат сайту — это «тот же ключ приложения» для всех кабинетов.
 * Пользовательский токен и ключ сообщества у каждого свои и лежат в его
 * usermeta: чужой кабинет не должен публиковать чужим ключом.
 */
final class VKT_Tokens {
    const OPTION = 'vkt_tokens';
    const ERRORS = 'vkt_token_errors';
    const LEGACY = 'vkt_token';
    const META = 'vkt_tokens';
    const META_ERRORS = 'vkt_token_errors';

    /** Описание слотов для интерфейса и проверок. */
    public static function definitions() {
        return array(
            'service' => array(
                'title' => 'Сервисный ключ приложения',
                'hint' => 'Читает стены сообществ: посты, видео, счётчики. Публиковать и загружать файлы не умеет.',
                'constant' => 'VKT_ACCESS_TOKEN',
                'kind' => 'service',
                'probe' => 'wall.get',
                'scope' => 'site',
            ),
            'user' => array(
                'title' => 'Пользовательский токен',
                'hint' => 'Единственный, кто может загружать фото и видео и получать список своих сообществ. Нужны права wall, photos, groups, video.',
                'constant' => '',
                'kind' => 'user',
                'probe' => 'users.get',
                'scope' => 'user',
            ),
            'app_secret' => array(
                'title' => 'Защищённый ключ приложения',
                'hint' => 'Нужен, чтобы сервер сам менял код авторизации на токен. Берётся в dev.vk.ru → Разработка → Ключи доступа → «Защищённый ключ» — того же приложения, чей ID указан в блоке обмена кода. Ключ другого приложения VK не примет.',
                'constant' => 'VKT_APP_SECRET',
                'kind' => 'secret',
                'probe' => '',
                'scope' => 'site',
            ),
            'community' => array(
                'title' => 'Ключ сообщества',
                'hint' => 'Публикует текст на стене своей группы. Медиа VK этому ключу запрещает.',
                'constant' => 'VKT_COMMUNITY_ACCESS_TOKEN',
                'kind' => 'community',
                'probe' => 'groups.getTokenPermissions',
                'scope' => 'user',
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

    public static function scope( $slot ) {
        return self::definitions()[ $slot ]['scope'] ?? 'site';
    }

    /** Общие слоты сайта из опции. */
    private static function read_site() {
        $plain = self::decrypt( get_option( self::OPTION, '' ) );
        $map = null === $plain ? null : json_decode( $plain, true );
        return is_array( $map ) ? $map : self::migrate_legacy();
    }

    /** Личные слоты пользователя из его usermeta. */
    private static function read_user( $user_id ) {
        if ( ! $user_id ) {
            return array();
        }
        $plain = self::decrypt( get_user_meta( $user_id, self::META, true ) );
        $map = null === $plain ? null : json_decode( $plain, true );
        return is_array( $map ) ? $map : array();
    }

    /** Сохранённые слоты без учёта констант: общие — сайта, личные — текущего пользователя. */
    private static function stored() {
        $site = self::read_site();
        $user = self::read_user( VKT_Account::id() );
        $clean = array();
        foreach ( array_keys( self::definitions() ) as $slot ) {
            $source = 'user' === self::scope( $slot ) ? $user : $site;
            $clean[ $slot ] = wp_parse_args( is_array( $source[ $slot ] ?? null ) ? $source[ $slot ] : array(), self::blank() );
        }
        return $clean;
    }

    /** Записывает слот туда, где он живёт. */
    private static function write( $slot, $entry ) {
        if ( 'user' === self::scope( $slot ) ) {
            $user_id = VKT_Account::id();
            if ( ! $user_id ) {
                return new WP_Error( 'no_user', 'Личный ключ сохраняется только вошедшему пользователю.', array( 'status' => 401 ) );
            }
            $map = self::read_user( $user_id );
            $map[ $slot ] = $entry;
            $encrypted = self::encrypt( wp_json_encode( $map ) );
            if ( is_wp_error( $encrypted ) ) {
                return $encrypted;
            }
            update_user_meta( $user_id, self::META, $encrypted );
            return true;
        }
        $map = self::read_site();
        $map[ $slot ] = $entry;
        $encrypted = self::encrypt( wp_json_encode( $map ) );
        if ( is_wp_error( $encrypted ) ) {
            return $encrypted;
        }
        update_option( self::OPTION, $encrypted, false );
        return true;
    }

    /**
     * 0.22.0: личные слоты из общей опции переезжают к хозяину сайта. До
     * личных кабинетов пользовательский токен и ключ сообщества были одни на
     * сайт — теперь это ключи хозяина, остальные подключают свои.
     */
    public static function migrate_to_owner( $owner ) {
        $owner = absint( $owner );
        $map = self::read_site();
        $personal = array();
        foreach ( self::definitions() as $slot => $definition ) {
            if ( 'user' !== $definition['scope'] || ! isset( $map[ $slot ] ) ) {
                continue;
            }
            if ( is_array( $map[ $slot ] ) && ! empty( $map[ $slot ]['access_token'] ) ) {
                $personal[ $slot ] = $map[ $slot ];
            }
            unset( $map[ $slot ] );
        }
        if ( $owner && $personal && ! self::read_user( $owner ) ) {
            $encrypted = self::encrypt( wp_json_encode( $personal ) );
            if ( is_wp_error( $encrypted ) ) {
                return;
            }
            update_user_meta( $owner, self::META, $encrypted );
        }
        $encrypted = self::encrypt( wp_json_encode( $map ) );
        if ( ! is_wp_error( $encrypted ) ) {
            update_option( self::OPTION, $encrypted, false );
        }
        $errors = (array) get_option( self::ERRORS, array() );
        $moved = array();
        foreach ( self::definitions() as $slot => $definition ) {
            if ( 'user' === $definition['scope'] && isset( $errors[ $slot ] ) ) {
                $moved[ $slot ] = $errors[ $slot ];
                unset( $errors[ $slot ] );
            }
        }
        if ( $moved ) {
            update_option( self::ERRORS, $errors, false );
            if ( $owner ) {
                update_user_meta( $owner, self::META_ERRORS, $moved );
            }
        }
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

    /**
     * Значение константы слота, если она действует для текущего пользователя.
     * Личные константы (ключ сообщества из wp-config.php) принадлежат хозяину
     * сайта: иначе запись любого кабинета ушла бы его ключом.
     */
    private static function constant_value( $slot ) {
        $definition = self::definitions()[ $slot ] ?? null;
        $constant = (string) ( $definition['constant'] ?? '' );
        if ( '' === $constant || ! defined( $constant ) || '' === trim( (string) constant( $constant ) ) ) {
            return '';
        }
        if ( 'user' === $definition['scope'] && ! VKT_Account::is_owner() ) {
            return '';
        }
        return trim( (string) constant( $constant ) );
    }

    /** Значение слота с учётом константы: она всегда важнее сохранённого. */
    public static function get( $slot ) {
        if ( ! isset( self::definitions()[ $slot ] ) ) {
            return self::blank();
        }
        $constant = self::constant_value( $slot );
        if ( '' !== $constant ) {
            return array_merge( self::blank(), array( 'access_token' => $constant ) );
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
        return '' !== self::constant_value( $slot );
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
        $written = self::write( $slot, $entry );
        if ( is_wp_error( $written ) ) {
            return $written;
        }
        self::note( $slot, '' );
        return true;
    }

    public static function forget( $slot ) {
        if ( ! isset( self::definitions()[ $slot ] ) ) {
            return new WP_Error( 'slot', 'Неизвестный слот ключа.', array( 'status' => 400 ) );
        }
        if ( self::locked( $slot ) ) {
            return new WP_Error( 'constant_token', 'Ключ задан в wp-config.php — удалите его там.', array( 'status' => 400 ) );
        }
        $written = self::write( $slot, self::blank() );
        if ( is_wp_error( $written ) ) {
            return $written;
        }
        self::note( $slot, '' );
        return true;
    }

    /** Ошибки общего слота видны администратору, личного — только владельцу ключа. */
    private static function errors( $slot ) {
        if ( 'user' === self::scope( $slot ) ) {
            $user_id = VKT_Account::id();
            return $user_id ? (array) get_user_meta( $user_id, self::META_ERRORS, true ) : array();
        }
        return (array) get_option( self::ERRORS, array() );
    }

    /** Последняя ошибка слота остаётся на виду, пока её не сменит новая. */
    public static function note( $slot, $message ) {
        $errors = self::errors( $slot );
        if ( '' === $message ) {
            if ( ! isset( $errors[ $slot ] ) ) {
                return;
            }
            unset( $errors[ $slot ] );
        } else {
            $errors[ $slot ] = array( 'message' => mb_substr( (string) $message, 0, 255 ), 'at' => gmdate( 'Y-m-d H:i:s' ) );
        }
        if ( 'user' === self::scope( $slot ) ) {
            $user_id = VKT_Account::id();
            if ( $user_id ) {
                update_user_meta( $user_id, self::META_ERRORS, $errors );
            }
            return;
        }
        update_option( self::ERRORS, $errors, false );
    }

    public static function error( $slot ) {
        $errors = self::errors( $slot );
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
                // Чей слот: site — общий ключ сайта, user — личный ключ кабинета.
                'area' => $definition['scope'],
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

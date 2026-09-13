<?php
defined( 'ABSPATH' ) || exit;

final class VKT_Plugin {
    public static function boot() {
        add_filter( 'cron_schedules', static function ( $schedules ) {
            $schedules['vkt_minute'] = array( 'interval' => 60, 'display' => 'VK Trends: каждую минуту' );
            return $schedules;
        } );
        add_action( 'vkt_collect', array( 'VKT_Collector', 'run' ) );
        add_action( 'vkt_publish', array( 'VKT_Publisher', 'run_due' ) );
        add_action( 'init', static function () {
            if ( get_option( 'vkt_db_version' ) !== VKT_VERSION ) { VKT_Store::install(); }
            if ( ! wp_next_scheduled( 'vkt_collect' ) ) { wp_schedule_event( time() + 60, 'vkt_minute', 'vkt_collect' ); }
            if ( ! wp_next_scheduled( 'vkt_publish' ) ) { wp_schedule_event( time() + 90, 'vkt_minute', 'vkt_publish' ); }
        } );
        add_filter( 'theme_page_templates', static function ( $templates ) {
            $templates['vkt-dashboard.php'] = 'VK Trends — Дашборд';
            $templates['vkt-api.php'] = 'VK Trends — Тест API';
            return $templates;
        } );
        add_action( 'template_redirect', static function () {
            if ( ! self::is_dashboard() ) { return; }
            if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
            nocache_headers();
            header( 'X-Robots-Tag: noindex, nofollow', true );
            if ( ! is_user_logged_in() ) { auth_redirect(); }
            if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Дашборд доступен только администратору.', 'VK Trends', array( 'response' => 403 ) ); }
        }, 1 );
        add_filter( 'template_include', static function ( $template ) {
            return self::is_dashboard() ? VKT_DIR . 'templates/dashboard.php' : $template;
        }, 999 );
        add_filter( 'show_admin_bar', static function ( $show ) { return self::is_dashboard() ? false : $show; } );
        add_action( 'wp_enqueue_scripts', static function () { if ( self::is_dashboard() ) { self::assets(); } }, 100 );
        add_action( 'admin_enqueue_scripts', static function ( $hook ) { if ( 'toplevel_page_vk-trends' === $hook ) { self::assets(); } } );
        add_action( 'admin_menu', static function () {
            add_menu_page( 'VK Trends', 'VK Trends', 'manage_options', 'vk-trends', static function () { require VKT_DIR . 'templates/app.php'; }, 'dashicons-chart-line', 3 );
        } );
        add_action( 'rest_api_init', array( self::class, 'routes' ) );
    }

    public static function activate() {
        VKT_Store::install();
        add_option( 'vkt_settings', self::defaults(), '', false );
        foreach ( array( 'dashboard' => array( 'VK Trends', 'vk-trends' ), 'api' => array( 'Тест API VK', 'vk-api' ) ) as $key => $page ) {
            if ( get_post( (int) get_option( 'vkt_page_' . $key ) ) ) { continue; }
            $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page[0], 'post_name' => $page[1], 'post_content' => '', 'meta_input' => array( '_wp_page_template' => 'vkt-' . $key . '.php' ) ), true );
            if ( ! is_wp_error( $id ) ) { update_option( 'vkt_page_' . $key, $id, false ); }
        }
        if ( ! wp_next_scheduled( 'vkt_collect' ) ) { wp_schedule_event( time() + 60, 'vkt_minute', 'vkt_collect' ); }
        if ( ! wp_next_scheduled( 'vkt_publish' ) ) { wp_schedule_event( time() + 90, 'vkt_minute', 'vkt_publish' ); }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'vkt_collect' );
        wp_clear_scheduled_hook( 'vkt_publish' );
        VKT_Store::unlock( 'collector' );
        VKT_Store::unlock( 'api' );
        VKT_Store::unlock( 'publisher' );
    }

    public static function defaults() {
        return array( 'api_version' => '5.199', 'source_hours' => 1, 'video_hours' => 6, 'paused' => true, 'homepage' => true, 'posts' => true, 'links' => true );
    }

    // Допустимые интервалы сбора. Промежуточные значения приводятся к ближайшему.
    const HOURS = array( 1, 2, 3, 4, 6, 12, 24 );

    public static function snap_hours( $hours ) {
        $hours = (float) $hours;
        $best = self::HOURS[0];
        foreach ( self::HOURS as $value ) {
            if ( abs( $value - $hours ) < abs( $best - $hours ) ) {
                $best = $value;
            }
        }
        return $best;
    }

    public static function settings() {
        $saved = (array) get_option( 'vkt_settings', array() );
        // Настройка прошлых версий хранила один интервал в минутах — переносим её в часы.
        if ( ! isset( $saved['source_hours'] ) && isset( $saved['interval'] ) ) {
            $saved['source_hours'] = self::snap_hours( (int) $saved['interval'] / 60 );
            $saved['video_hours'] = $saved['source_hours'];
        }
        return wp_parse_args( $saved, self::defaults() );
    }

    public static function public_settings() {
        // status() отдаёт только безопасные для интерфейса поля: без access/refresh_token.
        $status = VKT_API::status();
        return array_merge( self::settings(), array(
            'has_token' => $status['has_token'],
            'token_source' => defined( 'VKT_ACCESS_TOKEN' ) ? 'wp-config.php' : 'settings',
            'token_mode' => $status['mode'],
            'token_expires_in' => $status['expires_in'],
            'token_refreshable' => $status['refreshable'],
            'proxy_host' => VKT_Links::proxy_host(),
        ) );
    }

    public static function is_dashboard() {
        return ! is_admin() && ( ( is_front_page() && self::settings()['homepage'] ) || ( is_page() && in_array( get_page_template_slug(), array( 'vkt-dashboard.php', 'vkt-api.php' ), true ) ) );
    }

    public static function assets() {
        wp_enqueue_style( 'vk-trends', VKT_URL . 'assets/dashboard.css', array(), VKT_VERSION );
        wp_enqueue_script( 'vk-trends', VKT_URL . 'assets/dashboard.js', array(), VKT_VERSION, true );
        wp_add_inline_script( 'vk-trends', 'window.vktConfig=' . wp_json_encode( array(
            'rest' => esc_url_raw( rest_url( 'vk-trends/v1/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'initialView' => ! is_admin() && 'vkt-api.php' === get_page_template_slug() ? 'api' : 'overview',
            'apiUrl' => get_permalink( (int) get_option( 'vkt_page_api' ) ),
            'homeUrl' => home_url( '/' ), 'adminUrl' => admin_url(),
            'user' => wp_get_current_user()->display_name,
            'methods' => VKT_API::methods(),
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
    }

    public static function routes() {
        $permission = static function ( $request ) {
            return current_user_can( 'manage_options' ) && wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
        };
        register_rest_route( 'vk-trends/v1', '/state', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function ( $r ) {
            $response = new WP_REST_Response( VKT_Store::state( absint( $r['page'] ?? 1 ), sanitize_text_field( $r['search'] ?? '' ), sanitize_key( $r['sort'] ?? 'velocity' ) ) );
            $response->header( 'Cache-Control', 'no-store, private' );
            return $response;
        } ) );
        register_rest_route( 'vk-trends/v1', '/action', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => array( self::class, 'action' ) ) );
        // Посты живут отдельным маршрутом: витрина фильтруется и листается независимо от /state.
        register_rest_route( 'vk-trends/v1', '/posts', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function ( $r ) {
            $filters = json_decode( (string) ( $r['filters'] ?? '' ), true );
            $response = new WP_REST_Response( VKT_Posts::query( array(
                'page' => absint( $r['page'] ?? 1 ),
                'search' => sanitize_text_field( $r['search'] ?? '' ),
                'sort' => sanitize_key( $r['sort'] ?? 'velocity' ),
                'source' => absint( $r['source'] ?? 0 ),
                'filters' => is_array( $filters ) ? array_slice( $filters, 0, 30 ) : array(),
            ) ) );
            $response->header( 'Cache-Control', 'no-store, private' );
            return $response;
        } ) );
        register_rest_route( 'vk-trends/v1', '/communities', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function () {
            $response = new WP_REST_Response( array( 'communities' => VKT_Posts::communities() ) );
            $response->header( 'Cache-Control', 'no-store, private' );
            return $response;
        } ) );
        register_rest_route( 'vk-trends/v1', '/publishing', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function () {
            $response = new WP_REST_Response( VKT_Publisher::state() );
            $response->header( 'Cache-Control', 'no-store, private' );
            return $response;
        } ) );
        register_rest_route( 'vk-trends/v1', '/post-history/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function ( $r ) {
            $response = new WP_REST_Response( VKT_Posts::history( absint( $r['id'] ), absint( $r['limit'] ?? 300 ) ) );
            $response->header( 'Cache-Control', 'no-store, private' );
            return $response;
        } ) );
        register_rest_route( 'vk-trends/v1', '/history/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => static function ( $r ) {
            global $wpdb;
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . VKT_Store::table( 'snapshots' ) . ' WHERE video_id=%d ORDER BY measured_at DESC LIMIT 120', $r['id'] ), ARRAY_A );
            $response = new WP_REST_Response( array_reverse( $rows ) );
            $response->header( 'Cache-Control', 'no-store, private' );
            return $response;
        } ) );
    }

    // Из строки списка достаём то, что понимает groups.getById: короткое имя или числовой ID.
    private static function parse_community( $line ) {
        $line = trim( (string) $line );
        if ( '' === $line ) {
            return '';
        }
        if ( preg_match( '~^https?://~i', $line ) ) {
            $url = wp_parse_url( $line );
            $host = strtolower( (string) ( $url['host'] ?? '' ) );
            if ( ! in_array( $host, array( 'vk.com', 'www.vk.com', 'm.vk.com', 'vk.ru', 'www.vk.ru', 'm.vk.ru' ), true ) ) {
                return '';
            }
            $line = trim( (string) ( $url['path'] ?? '' ), '/' );
        } elseif ( preg_match( '~^(?:www\.|m\.)?vk\.(?:com|ru)/(.+)$~i', $line, $match ) ) {
            // Адрес без протокола: vk.com/team
            $line = $match[1];
        } elseif ( str_contains( $line, '/' ) ) {
            // Путь на чужом домене — не наш случай.
            return '';
        }
        $line = explode( '/', ltrim( $line, '@' ) )[0];
        // Минус в числовом ID для groups.getById не нужен: он различает club/public сам.
        if ( preg_match( '/^-?([1-9]\d{0,18})$/', $line, $match ) ) {
            return $match[1];
        }
        return preg_match( '/^[a-zA-Z0-9._]{1,64}$/', $line ) ? $line : '';
    }

    // Сообщество задаётся коротким именем или числовым ID владельца стены.
    private static function wall_target( $value ) {
        if ( '' === $value || mb_strlen( $value ) > 200 ) {
            return self::error( 'Укажите сообщество: короткое имя из адреса vk.com или числовой ID.' );
        }
        if ( preg_match( '/^-?[1-9]\d{0,18}$/', $value ) ) {
            return array( 'owner_id' => (int) $value, 'count' => 100 );
        }
        if ( ! preg_match( '/^[a-zA-Z0-9._]{1,64}$/', $value ) ) {
            return self::error( 'Короткое имя — латиница, цифры, точка и подчёркивание. Пример: team для vk.com/team.' );
        }
        return array( 'domain' => $value, 'count' => 100 );
    }

    private static function error( $message, $status = 400 ) { return new WP_Error( 'vkt_error', $message, array( 'status' => $status ) ); }

    public static function action( $request ) {
        global $wpdb;
        $data = $request->get_json_params();
        // 16 000 символов записи VK могут занимать до 64 КБ в UTF-8.
        if ( ! is_array( $data ) || strlen( $request->get_body() ) > 80000 ) { return self::error( 'Неверный формат или слишком большой запрос.' ); }
        $action = $data['action'] ?? '';
        switch ( $action ) {
            case 'settings':
                $settings = self::settings();
                $version = $data['api_version'] ?? $settings['api_version'];
                if ( ! is_string( $version ) || ! preg_match( '/^5\.\d{1,3}$/', $version ) ) { return self::error( 'Версия API должна иметь вид 5.199.' ); }
                if ( ! empty( $data['delete_token'] ) ) {
                    if ( defined( 'VKT_ACCESS_TOKEN' ) ) { return self::error( 'Токен задан в wp-config.php.' ); }
                    delete_option( 'vkt_token' );
                } elseif ( ! empty( $data['token'] ) ) {
                    if ( ! is_string( $data['token'] ) || ! preg_match( '/^[a-zA-Z0-9._\-]{20,2048}$/', trim( $data['token'] ) ) ) { return self::error( 'Введите только Access token, без URL, пробелов и HTML.' ); }
                    $kind = 'user' === ( $data['token_kind'] ?? '' ) ? 'user' : 'service';
                    if ( 'community' === VKT_API::detect_token_kind( $data['token'] ) ) {
                        return self::error( 'Это ключ сообщества. VK не разрешает использовать его для wall.post и загрузки фотографий на стену. Для автопостинга нужен пользовательский токен Standalone-приложения с правом wall.' );
                    }
                    $extra = array();
                    if ( 'user' === $kind ) {
                        foreach ( array( 'refresh_token', 'device_id', 'client_id' ) as $field ) {
                            $value = is_string( $data[ $field ] ?? null ) ? trim( $data[ $field ] ) : '';
                            if ( '' !== $value && ! preg_match( '/^[a-zA-Z0-9._\-]{3,2048}$/', $value ) ) { return self::error( 'Проверьте refresh_token, device_id и client_id.' ); }
                            if ( '' !== $value ) { $extra[ $field ] = $value; }
                        }
                        $refresh_count = count( array_intersect( array( 'refresh_token', 'device_id', 'client_id' ), array_keys( $extra ) ) );
                        if ( $refresh_count && 3 !== $refresh_count ) { return self::error( 'Данные автообновления указываются только полным комплектом: refresh_token, device_id и client_id.' ); }
                        if ( ! empty( $data['expires_in'] ) ) { $extra['expires_in'] = max( 60, min( DAY_IN_SECONDS, absint( $data['expires_in'] ) ) ); }
                    }
                    $saved = VKT_API::save_token( trim( $data['token'] ), $kind, $extra );
                    if ( is_wp_error( $saved ) ) { return $saved; }
                }
                // Пустое поле оставляет сохранённый адрес нетронутым, как и поле токена. Минус очищает.
                $proxy_input = is_string( $data['proxy'] ?? null ) ? trim( $data['proxy'] ) : '';
                if ( '' !== $proxy_input ) {
                    $saved_proxy = VKT_Links::save_proxy( '-' === $proxy_input ? '' : $proxy_input );
                    if ( is_wp_error( $saved_proxy ) ) { return $saved_proxy; }
                }
                $settings['api_version'] = $version;
                foreach ( array( 'source_hours', 'video_hours' ) as $key ) {
                    if ( isset( $data[ $key ] ) ) { $settings[ $key ] = self::snap_hours( (int) $data[ $key ] ); }
                }
                unset( $settings['interval'] );
                foreach ( array( 'paused', 'homepage', 'posts', 'links' ) as $key ) {
                    if ( isset( $data[ $key ] ) ) { $settings[ $key ] = (bool) $data[ $key ]; }
                }
                update_option( 'vkt_settings', $settings, false );
                return self::public_settings();
            case 'api':
                if ( ! is_string( $data['method'] ?? null ) ) { return self::error( 'Выберите метод.' ); }
                return VKT_API::request( $data['method'], $data['params'] ?? array(), 'test' );
            case 'search':
                $target = is_string( $data['q'] ?? null ) ? trim( sanitize_text_field( $data['q'] ) ) : '';
                $params = self::wall_target( $target );
                if ( is_wp_error( $params ) ) { return $params; }
                $params['offset'] = min( 3000, absint( $data['offset'] ?? 0 ) );
                $result = VKT_API::request( 'wall.get', $params, 'search' );
                if ( is_wp_error( $result ) ) { return $result; }
                $items = array_values( VKT_Store::videos_from_posts( $result['response']['items'] ?? array() ) );
                if ( ! empty( $data['short'] ) ) {
                    $items = array_values( array_filter( $items, static function ( $video ) { return 'short_video' === ( $video['type'] ?? '' ); } ) );
                }
                return array(
                    'response' => array( 'items' => $items, 'count' => (int) ( $result['response']['count'] ?? 0 ) ),
                    'duration_ms' => $result['duration_ms'], 'method' => 'wall.get',
                );
            case 'save_video':
                if ( ! is_string( $data['video'] ?? null ) ) { return self::error( 'Укажите ссылку или ID ролика.' ); }
                $id = VKT_Store::parse_video( $data['video'] );
                if ( is_wp_error( $id ) ) { return $id; }
                // video.get закрыт для сервисного токена: ищем ролик среди свежих постов стены владельца.
                $result = VKT_API::request( 'wall.get', array( 'owner_id' => (int) strtok( $id, '_' ), 'count' => 100 ), 'manual' );
                if ( is_wp_error( $result ) ) { return $result; }
                $items = VKT_Store::videos_from_posts( $result['response']['items'] ?? array() );
                if ( isset( $items[ $id ] ) ) {
                    $saved = VKT_Store::save_video( $items[ $id ] );
                    return is_wp_error( $saved ) ? $saved : array( 'id' => $saved );
                }
                return self::error( 'Ролика нет среди последних 100 постов этой стены. Добавьте сообщество источником — сборщик подхватит ролик при обходе.', 422 );
            case 'source':
                $kind = $data['kind'] ?? '';
                $value = is_string( $data['value'] ?? null ) ? trim( sanitize_text_field( $data['value'] ) ) : '';
                if ( ! in_array( $kind, array( 'domain', 'owner' ), true ) || ! $value || mb_strlen( $value ) > 200 ) { return self::error( 'Выберите тип и заполните источник (до 200 символов).' ); }
                if ( 'owner' === $kind && ! preg_match( '/^-?[1-9]\d{0,18}$/', $value ) ) { return self::error( 'Нужен числовой ID: положительный для автора, отрицательный для сообщества.' ); }
                if ( 'domain' === $kind && ! preg_match( '/^[a-zA-Z0-9._]{1,64}$/', $value ) ) { return self::error( 'Короткое имя — латиница, цифры, точка и подчёркивание. Пример: team для vk.com/team.' ); }
                $table = VKT_Store::table( 'sources' );
                if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) >= 500 ) { return self::error( 'В первой версии поддерживается до 500 источников.' ); }
                $ok = $wpdb->query( $wpdb->prepare( "INSERT INTO $table (kind,value,next_run) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", $kind, $value, gmdate( 'Y-m-d H:i:s' ) ) );
                return false === $ok ? self::error( 'Не удалось сохранить источник.', 500 ) : array( 'id' => (int) $wpdb->insert_id );
            case 'sources_import':
                $raw = is_string( $data['list'] ?? null ) ? $data['list'] : '';
                if ( '' === trim( $raw ) || strlen( $raw ) > 20000 ) {
                    return self::error( 'Вставьте список сообществ — по одному в строке, до 20 000 символов.' );
                }
                $wanted = array();
                foreach ( preg_split( '/[\r\n,;]+/', $raw ) as $line ) {
                    $value = self::parse_community( $line );
                    if ( '' !== $value ) {
                        $wanted[ strtolower( $value ) ] = $value;
                    }
                }
                if ( ! $wanted ) {
                    return self::error( 'Не распознали ни одного адреса. Подойдут ссылки vk.com/team, короткие имена и числовые ID.' );
                }
                $wanted = array_slice( array_values( $wanted ), 0, 200 );
                $table = VKT_Store::table( 'sources' );
                $free = 500 - (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
                if ( $free <= 0 ) {
                    return self::error( 'Достигнут предел в 500 источников.' );
                }
                // groups.getById принимает список разом: сотня сообществ — один запрос.
                $found = array();
                foreach ( array_chunk( $wanted, 100 ) as $chunk ) {
                    $result = VKT_API::request( 'groups.getById', array( 'group_ids' => implode( ',', $chunk ) ), 'manual' );
                    if ( is_wp_error( $result ) ) {
                        return $result;
                    }
                    foreach ( (array) ( $result['response']['groups'] ?? $result['response'] ?? array() ) as $group ) {
                        if ( ! empty( $group['id'] ) ) {
                            $found[ (int) $group['id'] ] = sanitize_text_field( (string) ( $group['name'] ?? '' ) );
                        }
                    }
                }
                $added = 0;
                $now = gmdate( 'Y-m-d H:i:s' );
                foreach ( $found as $group_id => $title ) {
                    if ( $added >= $free ) {
                        break;
                    }
                    $ok = $wpdb->query( $wpdb->prepare(
                        "INSERT INTO $table (kind,value,title,next_run) VALUES ('owner',%s,%s,%s) ON DUPLICATE KEY UPDATE title=VALUES(title)",
                        '-' . $group_id, $title, $now
                    ) );
                    // 1 — новая строка, 2 — обновили название уже существующей.
                    if ( 1 === $ok ) {
                        ++$added;
                    }
                }
                return array(
                    'requested' => count( $wanted ),
                    'resolved' => count( $found ),
                    'added' => $added,
                    'missing' => count( $wanted ) - count( $found ),
                );
            case 'source_toggle':
                return self::db_result( $wpdb->update( VKT_Store::table( 'sources' ), array( 'enabled' => empty( $data['enabled'] ) ? 0 : 1 ), array( 'id' => absint( $data['id'] ?? 0 ) ) ) );
            case 'publishing_sync':
                return VKT_Publisher::sync_groups();
            case 'publishing_group_toggle':
                return VKT_Publisher::toggle_group( $data['id'] ?? 0, ! empty( $data['enabled'] ) );
            case 'publishing_create':
                // Внешний REST-запрос всегда создаёт ручной черновик. Флаги
                // клиента не могут обойти проверку перед отправкой в VK.
                $data['approval_required'] = true;
                $data['origin'] = 'manual';
                return VKT_Publisher::create( $data );
            case 'publishing_run':
                return VKT_Publisher::run_due( 3 );
            case 'publishing_approve':
                return VKT_Publisher::approve( $data['id'] ?? 0 );
            case 'publishing_retry':
                return VKT_Publisher::retry( $data['id'] ?? 0 );
            case 'publishing_cancel':
                return VKT_Publisher::cancel( $data['id'] ?? 0 );
            case 'product':
                $title = is_string( $data['title'] ?? null ) ? sanitize_text_field( $data['title'] ) : '';
                $raw_url = is_string( $data['url'] ?? null ) ? trim( $data['url'] ) : '';
                $url = esc_url_raw( $raw_url, array( 'https', 'http' ) );
                if ( ! $title || mb_strlen( $title ) > 255 || ( $raw_url && ( ! $url || ! wp_http_validate_url( $url ) ) ) ) { return self::error( 'Введите название до 255 символов и корректную публичную ссылку на товар.' ); }
                if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VKT_Store::table( 'products' ) ) >= 500 ) { return self::error( 'В первой версии поддерживается до 500 товаров.' ); }
                return self::db_result( $wpdb->insert( VKT_Store::table( 'products' ), array( 'title' => $title, 'url' => $url, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ) );
            case 'link':
                $video = absint( $data['video_id'] ?? 0 );
                $product = absint( $data['product_id'] ?? 0 );
                if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'videos' ) . ' WHERE id=%d', $video ) ) || ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'products' ) . ' WHERE id=%d', $product ) ) ) { return self::error( 'Ролик или товар не найден.' ); }
                if ( ! empty( $data['remove'] ) ) { return self::db_result( $wpdb->delete( VKT_Store::table( 'links' ), array( 'video_id' => $video, 'product_id' => $product ) ) ); }
                return self::db_result( $wpdb->replace( VKT_Store::table( 'links' ), array( 'video_id' => $video, 'product_id' => $product ) ) );
            case 'enqueue':
                $id = absint( $data['id'] ?? 0 );
                if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'videos' ) . ' WHERE id=%d', $id ) ) ) { return self::error( 'Ролик не найден.', 404 ); }
                return self::db_result( VKT_Collector::enqueue( 'video', $id ) );
            case 'resolve_link':
                // Ручное чтение карточки: работает и для доменов вне списка магазинов.
                $post = $wpdb->get_row( $wpdb->prepare( 'SELECT link_id,cards FROM ' . VKT_Store::table( 'posts' ) . ' WHERE id=%d', absint( $data['id'] ?? 0 ) ), ARRAY_A );
                $link_id = absint( $data['link_id'] ?? 0 ) ?: (int) ( $post['link_id'] ?? 0 );
                $allowed = array( (int) ( $post['link_id'] ?? 0 ) );
                foreach ( json_decode( $post['cards'] ?? '', true ) ?: array() as $card ) { $allowed[] = (int) ( $card['link_id'] ?? 0 ); }
                if ( ! in_array( $link_id, $allowed, true ) ) { return self::error( 'Эта ссылка не принадлежит посту.', 404 ); }
                if ( ! $link_id ) { return self::error( 'У этого поста нет внешней ссылки.', 404 ); }
                if ( ! VKT_Store::lock( 'links', 60 ) ) { return self::error( 'Другая ссылка уже читается. Подождите несколько секунд.', 409 ); }
                try {
                    $resolved = VKT_Links::resolve( $link_id, 'manual' );
                } finally {
                    VKT_Store::unlock( 'links' );
                }
                if ( is_wp_error( $resolved ) ) { return $resolved; }
                $link = $wpdb->get_row( $wpdb->prepare( 'SELECT shop,domain,title,price,status,message FROM ' . VKT_Store::table( 'shop_links' ) . ' WHERE id=%d', $link_id ), ARRAY_A );
                return array( 'ok' => true, 'link' => $link );
            case 'collect':
                return VKT_Collector::run( true );
            case 'retry':
                return self::db_result( $wpdb->update( VKT_Store::table( 'jobs' ), array( 'status' => 'pending', 'attempts' => 0, 'available_at' => gmdate( 'Y-m-d H:i:s' ), 'message' => '' ), array( 'id' => absint( $data['id'] ?? 0 ), 'status' => 'failed' ) ) );
            case 'delete':
                $entity = $data['entity'] ?? '';
                $id = absint( $data['id'] ?? 0 );
                if ( ! in_array( $entity, array( 'sources', 'products', 'videos', 'posts' ), true ) || ! $id ) { return self::error( 'Объект не найден.' ); }
                if ( ! VKT_Store::lock( 'collector', 120 ) ) { return self::error( 'Дождитесь завершения сбора перед удалением.', 409 ); }
                try {
                    if ( 'videos' === $entity ) {
                        $wpdb->delete( VKT_Store::table( 'snapshots' ), array( 'video_id' => $id ) );
                        $wpdb->delete( VKT_Store::table( 'links' ), array( 'video_id' => $id ) );
                    }
                    if ( 'products' === $entity ) { $wpdb->delete( VKT_Store::table( 'links' ), array( 'product_id' => $id ) ); }
                    if ( 'posts' === $entity ) {
                        $wpdb->delete( VKT_Store::table( 'post_snapshots' ), array( 'post_id' => $id ) );
                        $wpdb->delete( VKT_Store::table( 'post_products' ), array( 'post_id' => $id ) );
                    }
                    if ( 'sources' === $entity ) {
                        // Ролики источника пользователь сохранял осознанно и остаются, автоматические посты — нет.
                        $post_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . VKT_Store::table( 'posts' ) . ' WHERE source_id=%d', $id ) );
                        foreach ( array_chunk( array_map( 'absint', $post_ids ), 500 ) as $chunk ) {
                            $list = implode( ',', $chunk );
                            $wpdb->query( 'DELETE FROM ' . VKT_Store::table( 'post_snapshots' ) . " WHERE post_id IN ($list)" );
                            $wpdb->query( 'DELETE FROM ' . VKT_Store::table( 'post_products' ) . " WHERE post_id IN ($list)" );
                            $wpdb->query( 'DELETE FROM ' . VKT_Store::table( 'posts' ) . " WHERE id IN ($list)" );
                        }
                    }
                    if ( ! in_array( $entity, array( 'products', 'posts' ), true ) ) { $wpdb->delete( VKT_Store::table( 'jobs' ), array( 'job_key' => ( 'videos' === $entity ? 'video:' : 'source:' ) . $id ) ); }
                    return self::db_result( $wpdb->delete( VKT_Store::table( $entity ), array( 'id' => $id ) ) );
                } finally { VKT_Store::unlock( 'collector' ); }
            default:
                return self::error( 'Неизвестное действие.' );
        }
    }

    private static function db_result( $result ) {
        return false === $result ? self::error( 'Не удалось записать данные.', 500 ) : array( 'ok' => true );
    }
}

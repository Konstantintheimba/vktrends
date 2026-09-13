<?php
defined( 'ABSPATH' ) || exit;

// Load the parsers even when an older plugin entry point is still in use.
require_once __DIR__ . '/class-commerce.php';
require_once __DIR__ . '/class-product-page.php';

/**
 * Ссылки из постов: хранение, распознавание магазина и чтение карточки товара со страницы.
 * Один адрес читается один раз независимо от того, в скольких постах он встретился.
 */
final class VKT_Links {
    // Домены, куда плагин ходит сам. Остальные ссылки ждут ручного запуска.
    const SHOPS = array(
        'ozon.ru' => 'Ozon', 'ozon.by' => 'Ozon', 'ozon.kz' => 'Ozon',
        'wildberries.ru' => 'Wildberries', 'global.wildberries.ru' => 'Wildberries',
        'market.yandex.ru' => 'Яндекс Маркет', 'pokupki.market.yandex.ru' => 'Яндекс Маркет',
        'megamarket.ru' => 'Мегамаркет', 'sbermegamarket.ru' => 'Мегамаркет',
        'magnitmarket.ru' => 'Магнит Маркет', 'kazanexpress.ru' => 'Магнит Маркет',
        'lamoda.ru' => 'Lamoda', 'dns-shop.ru' => 'DNS', 'citilink.ru' => 'Ситилинк',
        'mvideo.ru' => 'М.Видео', 'eldorado.ru' => 'Эльдорадо', 'sportmaster.ru' => 'Спортмастер',
        'detmir.ru' => 'Детский мир', 'goldapple.ru' => 'Золотое яблоко', 'letu.ru' => 'Лэтуаль',
        'eapteka.ru' => 'ЕАптека', 'apteka.ru' => 'Аптека.ру', 'vseinstrumenti.ru' => 'ВсеИнструменты',
        'leroymerlin.ru' => 'Лемана ПРО', 'petrovich.ru' => 'Петрович', 'aliexpress.ru' => 'AliExpress',
        'flowwow.com' => 'Flowwow', 'onlinetrade.ru' => 'OnlineTrade', 'technopark.ru' => 'Технопарк',
    );
    // Сокращатели: сам домен ничего не говорит, магазин выясняется после редиректов.
    const SHORTENERS = array( 'vk.cc', 'clck.ru', 'bit.ly', 'goo.su', 'u.to', 'cutt.ly', 'clc.to', 'go.tds', 'tinyurl.com' );
    // Заголовки антибот-заглушек: магазин ответил 200, но товара на странице нет.
    const GUARD_TITLES = array( 'доступ огранич', 'доступ запрещ', 'вы не робот', 'подтвердите', 'проверка браузера', 'captcha', 'access denied', 'attention required', 'just a moment', 'are you a robot', 'security check', 'blocked' );

    public static function schema() {
        return array(
            'shop_links' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                url_hash char(32) NOT NULL,
                url text NOT NULL,
                final_url text NOT NULL,
                domain varchar(120) NOT NULL DEFAULT '',
                shop varchar(60) NOT NULL DEFAULT '',
                sku varchar(40) NOT NULL DEFAULT '',
                title varchar(255) NOT NULL DEFAULT '',
                price double DEFAULT NULL,
                image text NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                message varchar(255) NOT NULL DEFAULT '',
                attempts int unsigned NOT NULL DEFAULT 0,
                parser_version int unsigned NOT NULL DEFAULT 0,
                checked_at datetime DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY url_hash (url_hash),
                KEY queue (status,attempts)",
        );
    }

    public static function shop_for( $domain ) {
        $domain = strtolower( (string) $domain );
        if ( isset( self::SHOPS[ $domain ] ) ) {
            return self::SHOPS[ $domain ];
        }
        // Поддомены магазинов: www.ozon.ru, m.lamoda.ru и подобные.
        foreach ( self::SHOPS as $host => $name ) {
            if ( str_ends_with( $domain, '.' . $host ) ) {
                return $name;
            }
        }
        return '';
    }

    /**
     * Идентификатор товара из адреса. Работает без обращения к магазину,
     * поэтому остаётся единственной опорой, когда сайт закрыт антиботом.
     */
    public static function sku_from_url( $url ) {
        $host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
        if ( '' === self::shop_for( $host ) ) { return ''; }
        // Match only the real host and path, never a merchant-looking string in a query.
        $url = $host . (string) wp_parse_url( (string) $url, PHP_URL_PATH );
        $patterns = array(
            '~ozon\.(?:ru|by|kz)/product/(?:[^/?#]*?-)?(\d{6,12})(?:/|$)~i',
            '~wildberries\.ru/catalog/(\d{5,12})/~i',
            '~market\.yandex\.ru/(?:product|offer|card)[^/]*/(?:[^/]+/)?(\d{6,})(?:/|$)~i',
            '~megamarket\.ru/catalog/details/[^/?#]*?-(\d{6,})~i',
            '~dns-shop\.ru/product/[a-f0-9]+/([a-z0-9\-]+)/~i',
        );
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, (string) $url, $match ) ) {
                return substr( $match[1], 0, 40 );
            }
        }
        return '';
    }

    private static function is_shortener( $domain ) {
        $domain = strtolower( (string) $domain );
        foreach ( self::SHORTENERS as $host ) {
            if ( $domain === $host || str_ends_with( $domain, '.' . $host ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Регистрирует ссылку поста и возвращает её id.
     * Статус pending означает автоматический обход, manual — ожидание ручного запуска.
     */
    public static function register( $url ) {
        global $wpdb;
        $url = VKT_Commerce::url( (string) $url );
        if ( '' === $url || strlen( $url ) > 2000 ) {
            return 0;
        }
        $table = VKT_Store::table( 'shop_links' );
        $hash = md5( $url );
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE url_hash=%s", $hash ) );
        if ( $existing ) {
            return (int) $existing;
        }
        $domain = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
        // Внутренние адреса VK читать незачем: товар и клип приходят прямо в ответе API.
        if ( preg_match( '/(^|\.)(vk\.com|vk\.ru|vkvideo\.ru|vk\.me)$/', $domain ) ) {
            return 0;
        }
        $shop = self::shop_for( $domain );
        $auto = ( '' !== $shop || self::is_shortener( $domain ) ) && VKT_Plugin::settings()['links'];
        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (url_hash,url,final_url,domain,shop,sku,image,status,created_at) VALUES (%s,%s,'',%s,%s,%s,'',%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            $hash, $url, substr( $domain, 0, 120 ), $shop, self::sku_from_url( $url ), $auto ? 'pending' : 'manual', gmdate( 'Y-m-d H:i:s' )
        ) );
        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    public static function parse( $html, $url = '' ) {
        return VKT_Product_Page::parse( $html, $url );
    }

    // Адрес сервиса рендеринга хранится отдельной опцией: внутри лежит ключ доступа.
    public static function proxy() {
        return (string) get_option( 'vkt_proxy', '' );
    }

    public static function save_proxy( $template ) {
        $template = trim( (string) $template );
        if ( '' === $template ) {
            delete_option( 'vkt_proxy' );
            return true;
        }
        if ( ! str_contains( $template, '{url}' ) || ! preg_match( '~^https://~i', $template ) || strlen( $template ) > 500 ) {
            return new WP_Error( 'proxy_template', 'Нужен https-адрес сервиса с меткой {url} — туда подставится ссылка на товар.', array( 'status' => 400 ) );
        }
        if ( ! wp_http_validate_url( str_replace( '{url}', 'https://example.com/', $template ) ) ) {
            return new WP_Error( 'proxy_template', 'Адрес сервиса не прошёл проверку WordPress.', array( 'status' => 400 ) );
        }
        update_option( 'vkt_proxy', $template, false );
        return true;
    }

    // Хост сервиса для интерфейса: сам адрес с ключом наружу не отдаём.
    public static function proxy_host() {
        $proxy = self::proxy();
        return '' === $proxy ? '' : (string) wp_parse_url( $proxy, PHP_URL_HOST );
    }

    /**
     * Читает страницу товара. wp_safe_remote_get не пускает запрос на внутренние адреса
     * и проверяет каждый редирект, поэтому ссылка из поста не может увести на localhost.
     * Если задан сервис рендеринга, запрос уходит через него: часть магазинов
     * (Ozon в их числе) отвечает серверным адресам только проверкой на робота.
     */
    public static function resolve( $id, $context = 'collector' ) {
        global $wpdb;
        $table = VKT_Store::table( 'shop_links' );
        $link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ), ARRAY_A );
        if ( ! $link ) {
            return new WP_Error( 'link_missing', 'Ссылка не найдена.', array( 'status' => 404 ) );
        }
        $started = microtime( true );
        $proxy = self::proxy();
        $target = '' === $proxy ? $link['url'] : str_replace( '{url}', rawurlencode( $link['url'] ), $proxy );
        $response = wp_safe_remote_get( $target, array(
            // Рендеринг страницы сервисом занимает больше времени, чем обычная загрузка.
            'timeout' => '' === $proxy ? 15 : 45,
            'redirection' => 5,
            'sslverify' => true,
            'limit_response_size' => 1024 * 1024,
            'user-agent' => 'Mozilla/5.0 (compatible; VK Trends/' . VKT_VERSION . '; +' . home_url( '/' ) . ')',
            'headers' => array( 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'ru-RU,ru;q=0.9' ),
        ) );
        $ms = (int) round( ( microtime( true ) - $started ) * 1000 );
        $changes = array( 'attempts' => (int) $link['attempts'] + 1, 'parser_version' => VKT_Commerce::VERSION, 'checked_at' => gmdate( 'Y-m-d H:i:s' ) );
        if ( is_wp_error( $response ) ) {
            $changes['status'] = 'error';
            $changes['message'] = 'Сайт недоступен или закрыл соединение.';
            $wpdb->update( $table, $changes, array( 'id' => $id ) );
            VKT_Store::log( 'link.' . $link['domain'], $context, 'error', 0, $changes['message'], $ms );
            return new WP_Error( 'link_transport', $changes['message'], array( 'retryable' => true ) );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        // Финальный адрес после редиректов: у сокращателей магазин виден только здесь.
        $final = $link['url'];
        $object = $response['http_response'] ?? null;
        if ( '' === $proxy && $object && method_exists( $object, 'get_response_object' ) ) {
            $final = (string) ( $object->get_response_object()->url ?? $final );
        }
        $domain = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $final, PHP_URL_HOST ) ) );
        $changes['final_url'] = esc_url_raw( $final, array( 'https', 'http' ) );
        $changes['domain'] = substr( $domain, 0, 120 );
        $changes['shop'] = self::shop_for( $domain );
        $sku = self::sku_from_url( $final );
        if ( '' !== $sku ) {
            $changes['sku'] = $sku;
        }
        if ( 200 !== $code ) {
            // 403 и 429 у магазинов означают антибот, а не отсутствие товара.
            $changes['status'] = in_array( $code, array( 401, 403, 429 ), true ) ? 'blocked' : 'error';
            $note = '' === $proxy ? ' Помогает сервис рендеринга в настройках.' : ' Сервис рендеринга тоже не пробился.';
            $changes['message'] = 'blocked' === $changes['status'] ? 'Магазин закрыл доступ роботу (HTTP ' . $code . ').' . $note : 'Страница ответила HTTP ' . $code . '.';
            $wpdb->update( $table, $changes, array( 'id' => $id ) );
            VKT_Store::log( 'link.' . $changes['domain'], $context, 'error', $code, $changes['message'], $ms );
            return new WP_Error( 'link_http', $changes['message'], array( 'retryable' => 429 === $code || $code >= 500 ) );
        }
        $parsed = self::parse( wp_remote_retrieve_body( $response ), $final );
        if ( self::is_guard_page( $parsed ) ) {
            // Страница отдала 200, но это защита от роботов: название товара сохранять нельзя.
            $changes['status'] = 'blocked';
            $changes['message'] = 'Магазин показал проверку на робота вместо карточки товара.';
            $wpdb->update( $table, $changes, array( 'id' => $id ) );
            VKT_Store::log( 'link.' . $changes['domain'], $context, 'error', 200, $changes['message'], $ms );
            return new WP_Error( 'link_guard', $changes['message'] );
        }
        $changes['title'] = $parsed['recognized'] ? $parsed['title'] : '';
        $changes['price'] = $parsed['recognized'] ? $parsed['price'] : null;
        $changes['image'] = $parsed['recognized'] ? $parsed['image'] : '';
        $changes['status'] = $parsed['recognized'] ? 'ok' : 'empty';
        $changes['message'] = $parsed['recognized'] ? '' : 'Страница прочитана, но данные конкретного товара в разметке не нашлись.';
        $wpdb->update( $table, $changes, array( 'id' => $id ) );
        VKT_Store::log( 'link.' . $changes['domain'], $context, 'ok', 200, 'ok' === $changes['status'] ? 'Товар распознан' : 'Товар не распознан', $ms );
        return true;
    }

    // Заглушка антибота: короткий заголовок из стоп-листа и ни цены, ни картинки товара.
    public static function is_guard_page( $parsed ) {
        $title = mb_strtolower( (string) $parsed['title'] );
        if ( '' === $title || null !== $parsed['price'] ) {
            return false;
        }
        foreach ( self::GUARD_TITLES as $marker ) {
            if ( str_contains( $title, $marker ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Разовая привязка ссылок к уже собранным постам: при обновлении плагина
     * у них заполнен link_url, но справочника ссылок ещё не существовало.
     */
    public static function backfill( $limit = 5000 ) {
        global $wpdb;
        $posts = VKT_Store::table( 'posts' );
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id,link_url FROM $posts WHERE link_id IS NULL AND link_url<>'' LIMIT %d", max( 1, (int) $limit ) ), ARRAY_A );
        $linked = 0;
        foreach ( $rows as $row ) {
            $link_id = self::register( $row['link_url'] );
            if ( $link_id ) {
                $wpdb->update( $posts, array( 'link_id' => $link_id ), array( 'id' => (int) $row['id'] ) );
                ++$linked;
            }
        }
        return $linked;
    }

    // Ссылки, ожидающие автоматического обхода. Ошибки повторяются не более трёх раз.
    public static function due( $limit = 30 ) {
        global $wpdb;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            'SELECT id FROM ' . VKT_Store::table( 'shop_links' ) . " WHERE (status IN ('pending','error') AND attempts<3) OR (status='empty' AND parser_version<%d AND shop<>'') ORDER BY attempts,id LIMIT %d",
            VKT_Commerce::VERSION, max( 1, (int) $limit )
        ) ) );
    }
}

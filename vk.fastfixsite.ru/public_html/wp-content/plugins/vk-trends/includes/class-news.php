<?php
defined( 'ABSPATH' ) || exit;

/**
 * Новостная группа: источники в интернете, из которых собираются посты.
 *
 * В интернет ходит плагин, а не модель: он читает RSS-ленты источников
 * группы, отдаёт модели заголовки и анонсы, а ссылку на источник
 * приписывает сам. Так работает любая подключённая модель, и источник
 * нельзя выдумать — он берётся из того, что реально скачано.
 */
final class VKT_News {
    const COUNTS = array( 5, 10, 15, 20, 30 );
    const DAYS = array( 1, 3, 7 );
    const MODES = array( 'posts', 'digest' );
    // Откуда берутся новости: поиск в интернете или RSS-ленты источников.
    const METHODS = array( 'search', 'rss' );
    // Сколько ссылок, не названных поиском в источниках, проверяем живым запросом.
    const MAX_CHECKS = 12;
    const MAX_SOURCES = 15;
    const TOPIC_MAX = 1500;
    // Модели уходит не больше стольких свежих новостей: выбрать из сотен она всё равно не сможет.
    const MAX_ITEMS = 60;
    // Сколько уже использованных новостей помним, чтобы не предлагать их снова.
    const USED_MAX = 600;

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_news', $message, array( 'status' => $status ) );
    }

    /** Сохранённые настройки в рабочий вид. Пусто или мусор — выключенная новостная группа. */
    public static function settings( $raw ) {
        $data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        $data = is_array( $data ) ? $data : array();
        $sources = array_values( array_filter( array_map( 'strval', (array) ( $data['sources'] ?? array() ) ) ) );
        return array(
            'enabled' => ! empty( $data['enabled'] ),
            'method' => in_array( $data['method'] ?? '', self::METHODS, true ) ? $data['method'] : 'search',
            'sources' => array_slice( $sources, 0, self::MAX_SOURCES ),
            'topic' => (string) ( $data['topic'] ?? '' ),
            'count' => in_array( (int) ( $data['count'] ?? 0 ), self::COUNTS, true ) ? (int) $data['count'] : 10,
            'days' => in_array( (int) ( $data['days'] ?? 0 ), self::DAYS, true ) ? (int) $data['days'] : 1,
            'mode' => in_array( $data['mode'] ?? '', self::MODES, true ) ? $data['mode'] : 'posts',
            'used' => array_slice( array_values( array_filter( (array) ( $data['used'] ?? array() ), 'is_string' ) ), -self::USED_MAX ),
        );
    }

    /** Настройки из формы. Источники приходят текстом — по адресу в строке. */
    public static function clean( $data, $current ) {
        $data = is_array( $data ) ? $data : array();
        $lines = $data['sources'] ?? array();
        $lines = is_array( $lines ) ? $lines : preg_split( '/[\s,;]+/u', (string) $lines );
        $sources = array();
        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }
            $url = esc_url_raw( preg_match( '~^https?://~i', $line ) ? $line : 'https://' . $line, array( 'http', 'https' ) );
            if ( '' === $url || ! wp_http_validate_url( $url ) ) {
                // WordPress заодно проверяет, что домен существует: опечатка в адресе ловится здесь же.
                return self::error( 'Адрес не открывается или это не адрес сайта: ' . mb_substr( $line, 0, 80 ) . '. Проверьте, нет ли опечатки.' );
            }
            $sources[ $url ] = $url;
        }
        if ( count( $sources ) > self::MAX_SOURCES ) {
            return self::error( 'Источников — не больше ' . self::MAX_SOURCES . ': сбор идёт, пока вы ждёте ответа.' );
        }
        $topic = VKT_Materials::plain( $data['topic'] ?? '' );
        if ( mb_strlen( $topic ) > self::TOPIC_MAX ) {
            return self::error( 'Описание выборки — не длиннее ' . self::TOPIC_MAX . ' символов.' );
        }
        $enabled = ! empty( $data['enabled'] );
        $method = in_array( $data['method'] ?? '', self::METHODS, true ) ? $data['method'] : 'search';
        if ( $enabled && 'rss' === $method && ! $sources ) {
            return self::error( 'Добавьте хотя бы один источник: адрес сайта или его RSS-ленты.' );
        }
        if ( $enabled && 'search' === $method && '' === $topic ) {
            return self::error( 'Опишите, какие новости искать: без этого поиску нечего спрашивать.' );
        }
        return self::settings( array(
            'enabled' => $enabled,
            'method' => $method,
            'sources' => array_values( $sources ),
            'topic' => $topic,
            'count' => $data['count'] ?? 0,
            'days' => $data['days'] ?? 0,
            'mode' => $data['mode'] ?? '',
            'used' => $current['used'] ?? array(),
        ) );
    }

    public static function key( $link ) {
        return substr( md5( (string) $link ), 0, 12 );
    }

    /** Относительный адрес ленты со страницы сайта — в полный. */
    private static function absolute( $href, $base ) {
        $href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) );
        if ( preg_match( '~^https?://~i', $href ) ) {
            return $href;
        }
        $parts = wp_parse_url( $base );
        if ( '' === $href || empty( $parts['host'] ) ) {
            return '';
        }
        $scheme = ( $parts['scheme'] ?? 'https' ) . ':';
        if ( str_starts_with( $href, '//' ) ) {
            return $scheme . $href;
        }
        $root = $scheme . '//' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
        return str_starts_with( $href, '/' ) ? $root . $href : $root . rtrim( dirname( ( $parts['path'] ?? '/' ) . 'x' ), '/' ) . '/' . $href;
    }

    /** Адрес RSS- или Atom-ленты, которую сайт объявляет в <head>. Пусто — не объявляет. */
    public static function discover( $body, $url ) {
        if ( ! preg_match_all( '~<link\b[^>]*>~i', (string) $body, $tags ) ) {
            return '';
        }
        foreach ( $tags[0] as $tag ) {
            if ( preg_match( '~type\s*=\s*["\']application/(?:rss|atom)\+xml~i', $tag ) && preg_match( '~href\s*=\s*["\']([^"\']+)~i', $tag, $href ) ) {
                return self::absolute( $href[1], $url );
            }
        }
        return '';
    }

    /**
     * Лента RSS 2.0 или Atom в список новостей. Возвращает null, если это
     * не лента: тогда вызывающий ищет её адрес на странице.
     */
    public static function parse( $body, $url ) {
        $body = ltrim( (string) $body, "\xEF\xBB\xBF \t\r\n" );
        if ( '' === $body || ! preg_match( '~<(rss|feed|rdf:RDF)\b~i', substr( $body, 0, 2000 ) ) ) {
            return null;
        }
        $previous = libxml_use_internal_errors( true );
        // LIBXML_NONET: чужой XML не должен тянуть внешние сущности с нашего сервера.
        $xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( false === $xml ) {
            return null;
        }
        $host = preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $text = static fn( $value, $limit ) => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ), 0, $limit );
        $entries = array();
        if ( 'feed' === $xml->getName() ) {
            foreach ( $xml->entry as $entry ) {
                $link = '';
                foreach ( $entry->link as $candidate ) {
                    $rel = (string) $candidate['rel'];
                    if ( '' === $link || 'alternate' === $rel || '' === $rel ) {
                        $link = (string) $candidate['href'];
                    }
                }
                $entries[] = array( $entry->title, $link, (string) ( $entry->published ?: $entry->updated ), $entry->summary ?: $entry->content );
            }
        } else {
            // RSS 2.0 держит записи в channel, RSS 1.0 (RDF) — рядом с ним.
            $nodes = isset( $xml->channel->item ) ? $xml->channel->item : $xml->item;
            foreach ( $nodes as $item ) {
                $dc = $item->children( 'http://purl.org/dc/elements/1.1/' );
                $entries[] = array( $item->title, (string) $item->link, (string) ( $item->pubDate ?: $dc->date ), $item->description );
            }
        }
        $items = array();
        foreach ( $entries as list( $title, $link, $date, $summary ) ) {
            $title = $text( $title, 300 );
            // Метки utm_* сайт ставит для своей статистики: в посте они только удлиняют ссылку.
            $link = rtrim( (string) preg_replace( '~([?&])utm_[a-z_]+=[^&#]*&?~i', '$1', self::absolute( $link, $url ) ), '?&' );
            $link = esc_url_raw( $link, array( 'http', 'https' ) );
            if ( '' === $title || '' === $link ) {
                continue;
            }
            $stamp = '' === trim( $date ) ? false : strtotime( $date );
            $items[] = array( 'title' => $title, 'link' => $link, 'date' => false === $stamp ? null : $stamp, 'summary' => $text( $summary, 600 ), 'source' => $host );
        }
        return $items;
    }

    private static function fetch( $url ) {
        $response = wp_safe_remote_get( $url, array(
            // Источников до полутора десятков, и человек ждёт ответа: медленный сайт пропускаем.
            'timeout' => 8,
            'redirection' => 3,
            'sslverify' => true,
            'limit_response_size' => 1024 * 1024,
            'user-agent' => 'Mozilla/5.0 (compatible; VK Trends/' . VKT_VERSION . '; +' . home_url( '/' ) . ')',
            'headers' => array( 'Accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml,text/html;q=0.8', 'Accept-Language' => 'ru-RU,ru;q=0.9' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return self::error( 'сайт не ответил: ' . $response->get_error_message(), 502 );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        return 200 === $code ? (string) wp_remote_retrieve_body( $response ) : self::error( 'сайт ответил кодом ' . $code . ( in_array( $code, array( 403, 429 ), true ) ? ' — закрылся от роботов' : '' ), 502 );
    }

    /** Новости одного источника. Адрес сайта без ленты — ищем ленту, объявленную на странице. */
    private static function read( $url ) {
        $body = self::fetch( $url );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $items = self::parse( $body, $url );
        if ( null === $items ) {
            $feed = self::discover( $body, $url );
            if ( '' === $feed || ! wp_http_validate_url( $feed ) ) {
                return self::error( 'на странице нет RSS-ленты. Найдите на сайте ссылку «RSS» и впишите её адрес.' );
            }
            $body = self::fetch( $feed );
            if ( is_wp_error( $body ) ) {
                return $body;
            }
            $items = self::parse( $body, $feed );
            if ( null === $items ) {
                return self::error( 'лента сайта не читается как RSS или Atom.' );
            }
        }
        return $items;
    }

    /**
     * Свежие новости всех источников: без повторов, без уже использованных,
     * новые сверху. По каждому источнику — что с ним вышло, словами.
     */
    public static function collect( $settings ) {
        $since = time() - $settings['days'] * DAY_IN_SECONDS;
        $used = array_flip( $settings['used'] );
        $seen = array();
        $items = array();
        $report = array();
        foreach ( $settings['sources'] as $url ) {
            $started = microtime( true );
            $found = self::read( $url );
            $ms = (int) round( ( microtime( true ) - $started ) * 1000 );
            if ( is_wp_error( $found ) ) {
                VKT_Store::log( 'news.read', 'news', 'error', 0, mb_substr( $url . ': ' . $found->get_error_message(), 0, 250 ), $ms );
                $report[] = array( 'url' => $url, 'ok' => false, 'total' => 0, 'fresh' => 0, 'message' => $found->get_error_message() );
                continue;
            }
            $fresh = 0;
            foreach ( $found as $item ) {
                $key = self::key( $item['link'] );
                // Новость без даты оставляем: иначе лента без дат была бы пустой всегда.
                if ( isset( $seen[ $key ] ) || isset( $used[ $key ] ) || ( null !== $item['date'] && $item['date'] < $since ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $items[] = $item;
                ++$fresh;
            }
            VKT_Store::log( 'news.read', 'news', 'ok', 200, mb_substr( $url . ': свежих ' . $fresh . ' из ' . count( $found ), 0, 250 ), $ms );
            $report[] = array( 'url' => $url, 'ok' => true, 'total' => count( $found ), 'fresh' => $fresh, 'message' => '' );
        }
        usort( $items, static fn( $a, $b ) => (int) $b['date'] <=> (int) $a['date'] );
        return array( 'items' => array_slice( $items, 0, self::MAX_ITEMS ), 'sources' => $report );
    }

    /** Адрес для сравнения: без протокола, www, меток и хвостового слеша. */
    private static function canonical( $url ) {
        $url = (string) preg_replace( '~([?&])utm_[a-z_]+=[^&#]*&?~i', '$1', (string) $url );
        return rtrim( strtolower( (string) preg_replace( '~^https?://(?:www\.)?~i', '', (string) preg_replace( '/#.*$/', '', $url ) ) ), '/?&' );
    }

    /** Страница по адресу действительно открывается. */
    private static function alive( $url ) {
        $args = array( 'timeout' => 5, 'redirection' => 3, 'sslverify' => true, 'limit_response_size' => 2048, 'user-agent' => 'Mozilla/5.0 (compatible; VK Trends/' . VKT_VERSION . '; +' . home_url( '/' ) . ')' );
        $response = wp_safe_remote_head( $url, $args );
        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        // Часть сайтов не отвечает на HEAD — спрашиваем обычным запросом.
        if ( $code < 200 || $code >= 400 ) {
            $response = wp_safe_remote_get( $url, $args );
            $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        }
        return $code >= 200 && $code < 400;
    }

    /**
     * Свежие новости поиском в интернете. Ссылку берём, только если она
     * подтверждена: поиск сам назвал её источником или страница открывается.
     * Модель с поиском иногда сочиняет адрес — такой новости в посте не место.
     */
    public static function search( $settings, $group_name = '' ) {
        $domains = array();
        foreach ( $settings['sources'] as $source ) {
            $domains[] = preg_replace( '/^www\./', '', (string) wp_parse_url( $source, PHP_URL_HOST ) );
        }
        $domains = array_values( array_unique( array_filter( $domains ) ) );
        // Фильтр по сайтам поиск принимает до пяти; больше — ищем везде, сайты остаются пожеланием.
        $found = VKT_AI::search_news( $settings['topic'], min( 30, $settings['count'] * 2 ), $settings['days'], count( $domains ) <= 5 ? $domains : array(), $group_name );
        if ( is_wp_error( $found ) ) {
            return $found;
        }
        $cited = array_flip( array_map( array( __CLASS__, 'canonical' ), $found['urls'] ) );
        $used = array_flip( $settings['used'] );
        $seen = array();
        $items = array();
        $checks = 0;
        $unconfirmed = 0;
        foreach ( $found['items'] as $item ) {
            $title = mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $item['title'] ) ) ), 0, 300 );
            $link = esc_url_raw( rtrim( (string) preg_replace( '~([?&])utm_[a-z_]+=[^&#]*&?~i', '$1', trim( $item['url'] ) ), '?&' ), array( 'http', 'https' ) );
            if ( '' === $title || '' === $link || ! wp_http_validate_url( $link ) ) {
                continue;
            }
            $key = self::key( $link );
            if ( isset( $seen[ $key ] ) || isset( $used[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            if ( ! isset( $cited[ self::canonical( $link ) ] ) ) {
                // Считаем каждую попытку, удачную и нет: иначе десяток мёртвых ссылок растянет сбор на минуты.
                if ( $checks >= self::MAX_CHECKS || ! ( ++$checks && self::alive( $link ) ) ) {
                    ++$unconfirmed;
                    continue;
                }
            }
            $stamp = '' === trim( $item['date'] ) ? false : strtotime( $item['date'] );
            $items[] = array(
                'title' => $title,
                'link' => $link,
                'date' => false === $stamp ? null : $stamp,
                'summary' => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $item['summary'] ) ) ), 0, 900 ),
                'source' => preg_replace( '/^www\./', '', (string) wp_parse_url( $link, PHP_URL_HOST ) ),
            );
        }
        $message = $unconfirmed ? 'отброшено без подтверждённой ссылки: ' . $unconfirmed : '';
        return array( 'items' => $items, 'sources' => array( array( 'url' => 'Поиск в интернете · ' . $found['provider'], 'ok' => true, 'total' => count( $found['items'] ), 'fresh' => count( $items ), 'message' => $message ) ) );
    }

    /**
     * Ответ модели в готовые записи. Ссылку на источник приписывает плагин:
     * модель её не видит и подменить не может.
     */
    public static function compose( $items, $answer, $mode ) {
        $picked = array();
        foreach ( (array) ( $answer['picks'] ?? array() ) as $pick ) {
            $item = $items[ (int) $pick['id'] - 1 ] ?? null;
            if ( $item && '' !== trim( (string) $pick['text'] ) ) {
                $picked[] = array( 'text' => trim( (string) $pick['text'] ), 'title' => $item['title'], 'link' => $item['link'], 'source' => $item['source'] );
            }
        }
        if ( ! $picked ) {
            return array();
        }
        if ( 'digest' !== $mode ) {
            return array_map( static fn( $post ) => array_merge( $post, array( 'text' => $post['text'] . "\n\nИсточник: " . $post['link'], 'links' => array( $post['link'] ) ) ), $picked );
        }
        $intro = trim( (string) ( $answer['intro'] ?? '' ) );
        $body = implode( "\n\n", array_map( static fn( $post ) => '— ' . $post['text'] . "\nИсточник: " . $post['link'], $picked ) );
        return array( array(
            'text' => ( '' === $intro ? '' : $intro . "\n\n" ) . $body,
            'title' => 'Дайджест: ' . count( $picked ) . ' новостей',
            'link' => '',
            'source' => implode( ', ', array_unique( array_column( $picked, 'source' ) ) ),
            'links' => array_column( $picked, 'link' ),
        ) );
    }
}

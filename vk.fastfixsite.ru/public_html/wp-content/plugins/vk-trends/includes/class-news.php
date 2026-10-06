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
    // Автосбор: как часто, что брать и сколько записей ставить за один заход.
    const AUTO_HOURS = array( 1, 2, 3, 4, 6, 12, 24 );
    // mentions — о чём пишут чаще всего, model — самое интересное на взгляд модели, all — всё найденное.
    const AUTO_PICKS = array( 'mentions', 'model', 'all' );
    const AUTO_PHOTOS = array( 'article', 'cover', 'none' );
    const AUTO_MAX = 10;
    // Упоминания считаются по всему найденному, а не по шестидесяти самым свежим.
    const POOL_ITEMS = 200;
    const RECENT_MAX = 80;

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
            // Кто ищет: ID из VKT_AI::search_providers(). Пусто или уже отключённый — первый доступный.
            'engine' => is_string( $data['engine'] ?? null ) && preg_match( '/^[a-z0-9_-]{2,60}$/', $data['engine'] ) ? $data['engine'] : '',
            'sources' => array_slice( $sources, 0, self::MAX_SOURCES ),
            'topic' => (string) ( $data['topic'] ?? '' ),
            'count' => in_array( (int) ( $data['count'] ?? 0 ), self::COUNTS, true ) ? (int) $data['count'] : 10,
            'days' => in_array( (int) ( $data['days'] ?? 0 ), self::DAYS, true ) ? (int) $data['days'] : 1,
            'mode' => in_array( $data['mode'] ?? '', self::MODES, true ) ? $data['mode'] : 'posts',
            'used' => array_slice( array_values( array_filter( (array) ( $data['used'] ?? array() ), 'is_string' ) ), -self::USED_MAX ),
            // Заголовки событий, уже взятых автосбором: о том же событии завтра выйдут новые статьи с новыми ссылками.
            'recent' => array_slice( array_values( array_filter( (array) ( $data['recent'] ?? array() ), 'is_string' ) ), -self::RECENT_MAX ),
            // Макет обложки сообщества: название, цвет градиента, метка, формат.
            'cover' => VKT_Cover::settings( $data['cover'] ?? array() ),
            'auto' => self::auto( $data['auto'] ?? array() ),
        );
    }

    /**
     * Автосбор: настройки из формы и то, что плагин запоминает сам —
     * когда и чем закончился последний заход и в какую серию он кладёт записи.
     */
    public static function auto( $raw ) {
        $raw = is_array( $raw ) ? $raw : array();
        return array(
            'enabled' => ! empty( $raw['enabled'] ),
            'hours' => in_array( (int) ( $raw['hours'] ?? 0 ), self::AUTO_HOURS, true ) ? (int) $raw['hours'] : 3,
            'pick' => in_array( $raw['pick'] ?? '', self::AUTO_PICKS, true ) ? $raw['pick'] : 'mentions',
            'limit' => max( 1, min( self::AUTO_MAX, (int) ( $raw['limit'] ?? 3 ) ) ),
            // Полный пост по тексту статьи вместо короткого по анонсу: каждая статья — ещё один текст лимита.
            'rewrite' => ! empty( $raw['rewrite'] ),
            'photo' => in_array( $raw['photo'] ?? '', self::AUTO_PHOTOS, true ) ? $raw['photo'] : 'article',
            'model' => is_string( $raw['model'] ?? null ) && preg_match( '/^[a-z0-9_-]{2,40}$/', $raw['model'] ) ? $raw['model'] : '',
            'last_at' => max( 0, (int) ( $raw['last_at'] ?? 0 ) ),
            'last_ok' => ! empty( $raw['last_ok'] ),
            'last' => mb_substr( (string) ( $raw['last'] ?? '' ), 0, 400 ),
            'series' => is_string( $raw['series'] ?? null ) && preg_match( '/^s[0-9a-z]{6,39}$/', $raw['series'] ) ? $raw['series'] : '',
            'series_day' => is_string( $raw['series_day'] ?? null ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw['series_day'] ) ? $raw['series_day'] : '',
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
        // Форма присылает только то, что человек выбрал; чем кончился прошлый заход, помнит плагин.
        $auto = $current['auto'] ?? array();
        if ( is_array( $data['auto'] ?? null ) ) {
            $auto = array_merge( $auto, array_intersect_key( $data['auto'], array_flip( array( 'enabled', 'hours', 'pick', 'limit', 'rewrite', 'photo', 'model' ) ) ) );
        }
        if ( ! $enabled ) {
            $auto['enabled'] = false;
        }
        return self::settings( array(
            'enabled' => $enabled,
            'method' => $method,
            'engine' => $data['engine'] ?? '',
            'sources' => array_values( $sources ),
            'topic' => $topic,
            'count' => $data['count'] ?? 0,
            'days' => $data['days'] ?? 0,
            'mode' => $data['mode'] ?? '',
            'used' => $current['used'] ?? array(),
            'recent' => $current['recent'] ?? array(),
            'cover' => $data['cover'] ?? ( $current['cover'] ?? array() ),
            'auto' => $auto,
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
        return array( 'items' => array_slice( $items, 0, (int) ( $settings['pool'] ?? self::MAX_ITEMS ) ), 'sources' => $report );
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
    public static function search( $settings, $group_name = '', $model = '' ) {
        $domains = array();
        foreach ( $settings['sources'] as $source ) {
            $domains[] = preg_replace( '/^www\./', '', (string) wp_parse_url( $source, PHP_URL_HOST ) );
        }
        $domains = array_values( array_unique( array_filter( $domains ) ) );
        if ( 'feed' === VKT_AI::search_kind( $settings['engine'] ) ) {
            return self::search_feed( $settings, $domains, $group_name, $model );
        }
        // Фильтр по сайтам поиск принимает до пяти; больше — ищем везде, сайты остаются пожеланием.
        $found = VKT_AI::search_news( $settings['topic'], min( 30, $settings['count'] * 2 ), $settings['days'], count( $domains ) <= 5 ? $domains : array(), $group_name, $settings['engine'] );
        if ( is_wp_error( $found ) ) {
            // Выбранный поиск не ответил (ключ, страна сервера, сбой) — сбор не должен вставать: ищет плагин.
            $spare = self::search_feed( $settings, $domains, $group_name, $model );
            if ( is_wp_error( $spare ) ) {
                return $found;
            }
            $spare['sources'][0]['message'] = trim( 'выбранный поиск не ответил, искал плагин. ' . $spare['sources'][0]['message'] );
            return $spare;
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

    const MAX_PHOTOS = 6;
    // Статья уходит в рерайт целиком: обрезанная дала бы обрезанный пост.
    const ARTICLE_MAX = 12000;

    /**
     * Текст статьи со страницы: абзацы из <article>, без меню, подписей и
     * рекламы. По нему модель делает рерайт — в анонсе из выдачи для этого
     * слишком мало фактов.
     */
    public static function article( $link ) {
        $link = esc_url_raw( is_string( $link ) ? trim( $link ) : '', array( 'http', 'https' ) );
        if ( '' === $link || ! wp_http_validate_url( $link ) ) {
            return self::error( 'Неверный адрес статьи.' );
        }
        $body = self::fetch( $link );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $scope = preg_match( '~<article\b.*?</article>~is', $body, $article ) ? $article[0] : $body;
        $scope = (string) preg_replace( '~<(script|style|noscript|figure|aside|nav|header|footer|form)\b.*?</\1>~is', ' ', $scope );
        preg_match_all( '~<p\b[^>]*>(.*?)</p>~is', $scope, $paragraphs );
        $parts = array();
        foreach ( $paragraphs[1] as $paragraph ) {
            $line = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $paragraph ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
            // Короткие строки — подписи, даты и «читайте также», а не текст статьи.
            if ( mb_strlen( $line ) >= 40 ) {
                $parts[] = $line;
            }
        }
        $text = mb_substr( implode( "\n", $parts ), 0, self::ARTICLE_MAX );
        return mb_strlen( $text ) < 300 ? self::error( 'на странице не нашлось текста статьи.', 404 ) : $text;
    }

    /**
     * Фотографии со страницы статьи: главная картинка, которую сайт сам
     * объявляет для соцсетей, и снимки из текста. Адреса только https —
     * другие медиатека не скачает.
     */
    public static function photos( $link ) {
        $link = esc_url_raw( is_string( $link ) ? trim( $link ) : '', array( 'http', 'https' ) );
        if ( '' === $link || ! wp_http_validate_url( $link ) ) {
            return self::error( 'Неверный адрес статьи.' );
        }
        $body = self::fetch( $link );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $found = array();
        $add = static function ( $src ) use ( &$found, $link ) {
            $url = esc_url_raw( self::absolute( $src, $link ), array( 'https' ) );
            // Один снимок сайты отдают в нескольких размерах — различаем по адресу без параметров.
            $key = strtolower( (string) preg_replace( '/[?#].*$/', '', $url ) );
            // Размер в имени файла вида 140x100 — миниатюра: в записи она будет мылом.
            if ( preg_match( '~(?<!\d)(\d{2,4})x(\d{2,4})(?!\d)~', $key, $size ) && max( (int) $size[1], (int) $size[2] ) < 400 ) {
                return;
            }
            if ( str_starts_with( $url, 'https://' ) && ! isset( $found[ $key ] ) && wp_http_validate_url( $url ) && ! preg_match( '~logo|icon|sprite|avatar|pixel|counter|banner|button|emoji|placeholder|\.svg|\.gif~i', $key ) ) {
                $found[ $key ] = $url;
            }
        };
        preg_match_all( '~<meta\b[^>]*>~i', $body, $metas );
        foreach ( $metas[0] as $tag ) {
            if ( preg_match( '~(?:property|name)\s*=\s*["\'](?:og:image(?::secure_url|:url)?|twitter:image(?::src)?)["\']~i', $tag ) && preg_match( '~content\s*=\s*["\']([^"\']+)~i', $tag, $content ) ) {
                $add( $content[1] );
            }
        }
        // Вне <article> на странице анонсы чужих новостей: их снимки к этой записи не относятся.
        if ( preg_match( '~<article\b.*?</article>~is', $body, $article ) ) {
            preg_match_all( '~<img\b[^>]*>~i', $article[0], $images );
            foreach ( $images[0] as $tag ) {
                if ( preg_match( '~\bwidth\s*=\s*["\']?(\d+)~i', $tag, $width ) && (int) $width[1] < 400 ) {
                    continue;
                }
                if ( preg_match( '~\b(?:data-src|data-original|src)\s*=\s*["\']([^"\']+)~i', $tag, $src ) ) {
                    $add( $src[1] );
                }
            }
        }
        return array( 'photos' => array_slice( array_values( $found ), 0, self::MAX_PHOTOS ) );
    }

    /**
     * Картинки по запросу — с Викисклада: там у каждого файла открытая
     * лицензия и назван автор. Нужны, когда к записи надо приложить сам
     * предмет новости (машину, здание, человека), а в статье его снимка нет.
     */
    public static function image_search( $query ) {
        $query = mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( is_string( $query ) ? $query : '' ) ) ), 0, 120 );
        if ( mb_strlen( $query ) < 2 ) {
            return self::error( 'Впишите, что искать: название предмета, модели или места.' );
        }
        $response = wp_safe_remote_get( add_query_arg( array(
            'action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrnamespace' => 6, 'gsrlimit' => 12,
            'gsrsearch' => rawurlencode( $query . ' filetype:bitmap' ),
            'prop' => 'imageinfo', 'iiprop' => rawurlencode( 'url|size|extmetadata' ), 'iiurlwidth' => 1280,
            'iiextmetadatafilter' => rawurlencode( 'LicenseShortName|Artist' ),
        ), 'https://commons.wikimedia.org/w/api.php' ), array( 'timeout' => 15, 'user-agent' => 'VK Trends/' . VKT_VERSION . ' (' . home_url( '/' ) . ')' ) );
        $data = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return self::error( 'Поиск картинок не ответил. Попробуйте позже.', 502 );
        }
        $pages = array_values( (array) ( $data['query']['pages'] ?? array() ) );
        usort( $pages, static fn( $a, $b ) => (int) ( $a['index'] ?? 0 ) <=> (int) ( $b['index'] ?? 0 ) );
        $items = array();
        foreach ( $pages as $page ) {
            $info = $page['imageinfo'][0] ?? array();
            $url = esc_url_raw( (string) ( $info['thumburl'] ?? $info['url'] ?? '' ), array( 'https' ) );
            // Мелкие картинки — значки и схемы, в запись они не годятся.
            if ( '' === $url || (int) ( $info['width'] ?? 0 ) < 600 ) {
                continue;
            }
            $author = mb_substr( trim( wp_strip_all_tags( (string) ( $info['extmetadata']['Artist']['value'] ?? '' ) ) ), 0, 60 );
            $license = sanitize_text_field( (string) ( $info['extmetadata']['LicenseShortName']['value'] ?? '' ) );
            $items[] = array( 'url' => $url, 'credit' => trim( $author . ( '' !== $author && '' !== $license ? ' · ' : '' ) . $license ), 'page' => esc_url_raw( (string) ( $info['descriptionurl'] ?? '' ), array( 'https' ) ) );
        }
        return array( 'images' => array_slice( $items, 0, 8 ), 'query' => $query );
    }

    /** Выбранное фото — в медиатеку сайта: в VK файл уходит уже оттуда. */
    public static function photo_save( $url ) {
        $media = VKT_Media::sideload( is_string( $url ) ? $url : '', 'image' );
        return is_wp_error( $media ) ? self::error( 'Фото с сайта источника не скачалось: сайт не отдал файл или это не изображение.', 502 ) : $media;
    }

    // Сколько раз за сбор спрашиваем выдачу: человек ждёт ответа.
    const FEED_REQUESTS = 8;

    /**
     * Поиск плагина: модель для текста составляет запросы, новостную выдачу
     * Bing читает сам плагин. Так ищет любая модель, в том числе без своего
     * поиска (DeepSeek), а адрес статьи приходит из выдачи — выдумать его некому.
     */
    private static function search_feed( $settings, $domains, $group_name, $model ) {
        $queries = VKT_AI::search_queries( $settings['topic'], $group_name, $model );
        // Несколько сайтов через OR выдача не понимает — на каждый сайт свой запрос.
        $lines = array();
        foreach ( $queries as $query ) {
            foreach ( $domains ? array_slice( $domains, 0, 5 ) : array( '' ) as $domain ) {
                $lines[] = '' === $domain ? $query : $query . ' site:' . $domain;
            }
        }
        $lines = array_slice( $lines, 0, self::FEED_REQUESTS );
        $since = time() - $settings['days'] * DAY_IN_SECONDS;
        $used = array_flip( $settings['used'] );
        $seen = array();
        $items = array();
        $total = 0;
        $failed = array();
        foreach ( $lines as $line ) {
            $started = microtime( true );
            // interval: 7 — за сутки, 8 — за неделю; точный срок отсекаем сами по дате.
            $body = self::fetch( add_query_arg( array( 'q' => rawurlencode( $line ), 'qft' => rawurlencode( 'interval="' . ( $settings['days'] > 1 ? 8 : 7 ) . '"' ), 'format' => 'rss', 'setlang' => 'ru', 'cc' => 'RU' ), 'https://www.bing.com/news/search' ) );
            $found = is_wp_error( $body ) ? $body : self::parse( $body, 'https://www.bing.com/' );
            $ms = (int) round( ( microtime( true ) - $started ) * 1000 );
            if ( ! is_array( $found ) ) {
                $failed[] = is_wp_error( $found ) ? $found->get_error_message() : 'выдача не читается';
                VKT_Store::log( 'search.feed', 'news', 'error', 0, mb_substr( $line . ': ' . end( $failed ), 0, 250 ), $ms );
                continue;
            }
            VKT_Store::log( 'search.feed', 'news', 'ok', 200, mb_substr( $line . ': найдено ' . count( $found ), 0, 250 ), $ms );
            $total += count( $found );
            foreach ( $found as $item ) {
                // В выдаче ссылка идёт через счётчик поисковика — адрес статьи лежит в параметре url.
                wp_parse_str( (string) wp_parse_url( $item['link'], PHP_URL_QUERY ), $query );
                $link = esc_url_raw( rtrim( (string) preg_replace( '~([?&])utm_[a-z_]+=[^&#]*&?~i', '$1', (string) ( $query['url'] ?? $item['link'] ) ), '?&' ), array( 'http', 'https' ) );
                $key = self::key( $link );
                if ( '' === $link || ! wp_http_validate_url( $link ) || isset( $seen[ $key ] ) || isset( $used[ $key ] ) || ( null !== $item['date'] && $item['date'] < $since ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $items[] = array_merge( $item, array( 'link' => $link, 'source' => preg_replace( '/^www\./', '', (string) wp_parse_url( $link, PHP_URL_HOST ) ) ) );
            }
        }
        if ( $failed && count( $failed ) === count( $lines ) ) {
            return self::error( 'Поиск плагина не получил выдачу: ' . $failed[0] . '. Попробуйте позже или выберите другой поиск в поле «Кто ищет».', 502 );
        }
        usort( $items, static fn( $a, $b ) => (int) $b['date'] <=> (int) $a['date'] );
        return array( 'items' => array_slice( $items, 0, (int) ( $settings['pool'] ?? self::MAX_ITEMS ) ), 'sources' => array( array( 'url' => 'Поиск плагина · запросы: ' . implode( '; ', $queries ), 'ok' => true, 'total' => $total, 'fresh' => count( $items ), 'message' => $failed ? 'не ответило запросов: ' . count( $failed ) : '' ) ) );
    }

    /** Слова заголовка для сравнения: без коротких, обрезанные до четырёх букв — «матч», «матча» и «матче» совпадают. */
    private static function stems( $title ) {
        preg_match_all( '/[\p{L}\p{N}]{4,}/u', mb_strtolower( (string) $title ), $found );
        $stems = array();
        foreach ( $found[0] as $word ) {
            $stems[ mb_substr( $word, 0, 4 ) ] = true;
        }
        return $stems;
    }

    /**
     * Одно событие из разных источников — одна новость со счётчиком
     * упоминаний. Сходство считается по словам заголовка: общих основ не
     * меньше трёх и не меньше половины более короткого заголовка. Сверху то,
     * о чём пишут больше изданий; при равенстве — более свежее. $recent —
     * заголовки уже опубликованных событий: похожее на них отбрасывается.
     */
    /** Насколько два заголовка об одном: доля общих основ от более короткого. Меньше трёх общих — не об одном. */
    private static function likeness( $a, $b ) {
        $common = count( array_intersect_key( $a, $b ) );
        $smaller = min( count( $a ), count( $b ) );
        return $common >= 3 && $smaller ? $common / $smaller : 0;
    }

    public static function rank( $items, $recent = array() ) {
        $taken = array_map( array( __CLASS__, 'stems' ), (array) $recent );
        $clusters = array();
        foreach ( array_values( (array) $items ) as $item ) {
            $stems = self::stems( $item['title'] );
            foreach ( $taken as $old ) {
                // Событие уже публиковалось: свежая статья о нём — не новость для подписчика.
                if ( self::likeness( $stems, $old ) >= 0.5 ) {
                    continue 2;
                }
            }
            $best = -1;
            $best_score = 0;
            foreach ( $clusters as $index => $cluster ) {
                $score = self::likeness( $stems, $cluster['stems'] );
                if ( $score >= 0.5 && $score > $best_score ) {
                    $best = $index;
                    $best_score = $score;
                }
            }
            if ( $best < 0 ) {
                // Сравниваем всегда с первым заголовком группы: иначе цепочка похожих увела бы её к другой теме.
                $clusters[] = array( 'stems' => $stems, 'lead' => $item, 'sources' => array( (string) $item['source'] => true ), 'date' => (int) $item['date'], 'links' => array( $item['link'] ) );
                continue;
            }
            $clusters[ $best ]['links'][] = $item['link'];
            $clusters[ $best ]['sources'][ (string) $item['source'] ] = true;
            $clusters[ $best ]['date'] = max( $clusters[ $best ]['date'], (int) $item['date'] );
            // Пересказывать лучше по самому подробному анонсу.
            if ( mb_strlen( (string) $item['summary'] ) > mb_strlen( (string) $clusters[ $best ]['lead']['summary'] ) ) {
                $clusters[ $best ]['lead'] = $item;
            }
        }
        // Две статьи одного сайта — одно упоминание: иначе издание накручивало бы событие само себе.
        usort( $clusters, static fn( $a, $b ) => array( count( $b['sources'] ), count( $b['links'] ), $b['date'] ) <=> array( count( $a['sources'] ), count( $a['links'] ), $a['date'] ) );
        // also — остальные статьи о том же: использованными отмечаются все, иначе следующий заход взял бы событие снова.
        return array_map( static fn( $cluster ) => array_merge( $cluster['lead'], array( 'mentions' => count( $cluster['sources'] ), 'also' => array_values( array_diff( $cluster['links'], array( $cluster['lead']['link'] ) ) ) ) ), $clusters );
    }

    /**
     * Что из найденного автосбор отдаёт модели, сколько записей просит и с
     * каким напутствием. Повторы одного события убраны при любом выборе:
     * дважды одну новость сообщество публиковать не должно.
     */
    public static function shortlist( $items, $auto, $recent = array() ) {
        $ranked = self::rank( $items, $recent );
        $limit = (int) $auto['limit'];
        if ( 'mentions' === $auto['pick'] ) {
            // С запасом: часть самых упоминаемых может не подойти сообществу по выборке.
            return array( array_slice( $ranked, 0, max( 10, $limit * 3 ) ), $limit, 'Список отсортирован по числу изданий, написавших о событии (поле mentions): чем выше новость, тем она заметнее. Бери сверху вниз и пропускай только то, что сообществу не подходит.' );
        }
        usort( $ranked, static fn( $a, $b ) => (int) $b['date'] <=> (int) $a['date'] );
        if ( 'all' === $auto['pick'] ) {
            $ranked = array_slice( $ranked, 0, $limit );
            return array( $ranked, count( $ranked ), 'Владелец просил публиковать всё найденное: напиши запись по каждой новости списка, пропусти только рекламу и повтор одного события.' );
        }
        return array( array_slice( $ranked, 0, self::MAX_ITEMS ), $limit, 'Выбирай самое интересное и значимое для подписчиков: то, что захочется обсудить или переслать.' );
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
                $picked[] = array( 'text' => trim( (string) $pick['text'] ), 'title' => $item['title'], 'link' => $item['link'], 'source' => $item['source'], 'mentions' => (int) ( $item['mentions'] ?? 1 ), 'also' => (array) ( $item['also'] ?? array() ) );
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
            'mentions' => 1,
            'also' => array_merge( array(), ...array_column( $picked, 'also' ) ),
            'titles' => array_column( $picked, 'title' ),
        ) );
    }
}

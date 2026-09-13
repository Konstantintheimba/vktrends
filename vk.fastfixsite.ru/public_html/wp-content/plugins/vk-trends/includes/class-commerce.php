<?php
defined( 'ABSPATH' ) || exit;

/** Pure extraction: keep each title, price and destination together. No HTTP calls. */
final class VKT_Commerce {
    const VERSION = 1;

    public static function price( $value ) {
        if ( is_array( $value ) ) {
            if ( isset( $value['amount'] ) && is_numeric( $value['amount'] ) ) {
                return max( 0, (float) $value['amount'] / 100 );
            }
            $value = $value['text'] ?? '';
        }
        $digits = str_replace( ',', '.', preg_replace( '/[^\d,.]/u', '', (string) $value ) );
        return is_numeric( $digits ) ? max( 0, (float) $digits ) : null;
    }

    public static function title( $value ) {
        if ( ! is_scalar( $value ) ) { return ''; }
        $value = sanitize_text_field( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $value = trim( preg_replace( '/\s+/u', ' ', $value ) );
        if ( preg_match( '~^(?:ozon|озон|wildberries|яндекс\s*маркет)\s*[—–:|-]\s*(?:интернет|маркетплейс|купить)~ui', $value ) ) { return ''; }
        $value = trim( preg_replace( '~\s+[—–|]\s*(?:купить\b.*|ozon|wildberries|яндекс\s*маркет)\s*$~ui', '', $value ) );
        // A store name or a call to action is not a product name.
        if ( preg_match( '~^(?:ozon|озон|wildberries|вайлдберриз|яндекс\s*маркет|интернет[ -]магазин|купить|подробнее|перейти|товар|ссылка)(?:[.!\s]*)$~ui', $value ) || VKT_Links::is_guard_page( array( 'title' => $value, 'price' => null ) ) ) {
            return '';
        }
        return mb_substr( $value, 0, 255 );
    }

    public static function url( $value ) {
        if ( ! is_string( $value ) ) { return ''; }
        $value = html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( preg_match( '~^(?:market-?\d+_\d+)$~', $value ) ) { $value = 'https://vk.ru/' . $value; }
        if ( preg_match( '~^(?:(?:www\.|m\.)?(?:vk\.(?:ru|com)|ozon\.(?:ru|by|kz)|wildberries\.ru|market\.yandex\.ru)|vk\.cc|clck\.ru)/~i', $value ) ) {
            $value = 'https://' . $value;
        }
        $value = esc_url_raw( $value, array( 'http', 'https' ) );
        if ( ! preg_match( '~^https?://~i', $value ) || strlen( $value ) > 2000 ) { return ''; }
        // VK wraps external links in away.php; do not enqueue VK itself as a shop.
        for ( $i = 0; $i < 3; ++$i ) {
            $host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
            if ( ! preg_match( '~(^|\.)vk\.(com|ru)$~', $host ) || '/away.php' !== wp_parse_url( $value, PHP_URL_PATH ) ) { break; }
            parse_str( (string) wp_parse_url( $value, PHP_URL_QUERY ), $query );
            $next = $query['to'] ?? $query['url'] ?? '';
            if ( ! is_string( $next ) || ! preg_match( '~^https?://~i', $next ) ) { return ''; }
            $value = esc_url_raw( $next, array( 'http', 'https' ) );
        }
        return $value;
    }

    public static function internal( $url ) {
        return (bool) preg_match( '~(^|\.)(vk\.com|vk\.ru|vkvideo\.ru|vk\.me)$~i', (string) wp_parse_url( $url, PHP_URL_HOST ) );
    }

    public static function market_id( $url ) {
        if ( self::internal( $url ) && preg_match( '~(?:/market|[?&]w=product|[?&]z=market)(-?\d+_\d+)~', rawurldecode( $url ), $m ) ) { return $m[1]; }
        return '';
    }

    /** A conservative text hint, never counted as a recognized product. */
    public static function from_text( $text ) {
        $result = array( 'text_product' => '', 'text_price' => null );
        $segment = preg_split( '~https?://|(?:ozon\.ru|vk\.cc|vk\.ru)/~ui', (string) $text )[0];
        // A new line / dash after a previous link starts the next item in a roundup.
        $segment = trim( preg_replace( '~(?:^|\n)\s*(?:ссылк[ауи][^\n]*|заказать|купить|артикул)\s*[:：]?\s*$~ui', '', $segment ) );
        $parts = preg_split( '~\s+[—–]\s+~u', $segment );
        $candidate = trim( preg_replace( '/\s+/u', ' ', (string) end( $parts ) ) );
        if ( preg_match( '~(\d[\d\s\x{00A0}]{0,9}(?:[,.]\d{1,2})?)\s*(?:₽|руб(?:лей|ля|ль|\.)?|р\.)(?!\p{L})~ui', $candidate, $m ) ) {
            $result['text_price'] = self::price( $m[1] );
        }
        $candidate = preg_replace( '~(?:\s+за)?\s*\d[\d\s\x{00A0}]*(?:[,.]\d{1,2})?\s*(?:₽|руб(?:лей|ля|ль|\.)?|р\.)(?!\p{L}).*$~ui', '', $candidate );
        // Find the actual noun even after a conversational introduction.
        $nouns = 'кроссовк\p{L}*|кед[ыа]?|шорты|шортик\p{L}*|футболк\p{L}*|плать\p{L}*|дождевик\p{L}*|джинс\p{L}*|брюк\p{L}*|рубашк\p{L}*|куртк\p{L}*|костюм\p{L}*|обогревател\p{L}*|пылесос\p{L}*|сумк\p{L}*|рюкзак\p{L}*|крем\p{L}*|сыворотк\p{L}*|шампун\p{L}*|маск[аиуы]|помад\p{L}*|тушь|ламп\p{L}*|светильник\p{L}*|наушник\p{L}*|телефон\p{L}*|чехол|чехлы|сковород\p{L}*|кастрюл\p{L}*|контейнер\p{L}*|органайзер\p{L}*|подушк\p{L}*|одеял\p{L}*|полотенц\p{L}*|игрушк\p{L}*|конструктор\p{L}*';
        if ( preg_match( '~\b(?:' . $nouns . ')\b.*~ui', $candidate, $m ) ) {
            $candidate = $m[0];
            $candidate = preg_split( '~[,!?:;\n]|\.(?:\s|$)|\s+(?:котор\p{L}*|они|она|он|это|очень|тоже|особенно|даже|девочки|мне|меня|мои|я|мы|чтобы|потому)\b|\s+и\s+(?:не|с|для|в|на)\b~ui', $candidate )[0];
        } elseif ( mb_strlen( $candidate ) > 100 || preg_match( '~\b(?:я|мы|она|он|мне|меня|сегодня|счастлив\p{L}*|путешеств\p{L}*|ссылк\p{L}*)\b~ui', $candidate ) ) {
            $candidate = '';
        }
        $candidate = trim( preg_replace( '~[^\p{L}\p{N}.,+&/()«»"\s-]~u', '', $candidate ), " \t\n\r\0\x0B.,:;—–-" );
        if ( mb_strlen( $candidate ) > 120 ) { $candidate = ''; }
        $result['text_product'] = mb_strlen( $candidate ) >= 3 ? self::title( $candidate ) : '';
        return $result;
    }

    private static function candidate( $url, $title = '', $price = null, $source = 'text', $extra = array() ) {
        $url = self::url( $url );
        if ( ! $url || ( self::internal( $url ) && ! self::market_id( $url ) ) ) { return null; }
        $title = self::title( $title );
        $shop = VKT_Links::shop_for( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $recognized = '' !== $title && in_array( $source, array( 'market', 'carousel', 'link_product', 'link' ), true );
        // A generic link preview can be a blog/article, even if it has a title.
        if ( 'link' === $source && '' === $shop && ! self::market_id( $url ) ) { $recognized = false; }
        if ( 'link' === $source && '' !== $shop && preg_match( '~^/(?:$|(?:search|category|seller|brand)(?:/|$))~i', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) { $recognized = false; }
        return array_merge( array(
            'url' => $url, 'title' => $title, 'price' => $price, 'old_price' => null,
            'sku' => VKT_Links::sku_from_url( $url ), 'market_id' => self::market_id( $url ),
            'source' => $source, 'recognized' => $recognized, 'shop' => $shop,
            'price_source' => null === $price ? '' : $source,
        ), $extra );
    }

    private static function text_links( $text ) {
        $cards = array();
        $text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        // VK wiki labels are stronger than surrounding prose, but remain a text hint.
        $labels = array();
        $text = preg_replace_callback( '~\[((?:https?://|market-?\d+_\d+)[^\s|\]]*)\|([^\]]+)\]~u', static function ( $m ) use ( &$labels ) {
            $url = self::url( $m[1] );
            $labels[ $url ] = $m[2];
            return $m[2] . ' ' . $url;
        }, $text );
        preg_match_all( '~https?://[^\s<>"\x27\[\]|]+|(?<![\w./])(?:ozon\.(?:ru|by|kz)|wildberries\.ru|market\.yandex\.ru|vk\.cc|clck\.ru)/[^\s<>"\x27\[\]|]+~ui', $text, $matches, PREG_OFFSET_CAPTURE );
        $previous_end = 0;
        foreach ( array_slice( $matches[0], 0, 40 ) as $match ) {
            $raw = rtrim( $match[0], '.,;!?)»' );
            $url = self::url( $raw );
            $hint = self::from_text( substr( $text, $previous_end, $match[1] - $previous_end ) );
            $previous_end = $match[1] + strlen( $match[0] );
            $card = self::candidate( $url, $labels[ $url ] ?? $hint['text_product'], $hint['text_price'] );
            if ( $card ) { $cards[] = $card; }
        }
        return $cards;
    }

    private static function carousel( $value ) {
        $cards = array();
        foreach ( (array) ( $value['cards'] ?? $value ) as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $card = self::candidate( $item['link_url'] ?? $item['url'] ?? '', $item['title'] ?? '', self::price( $item['price'] ?? null ), 'carousel', array( 'old_price' => self::price( $item['old_price'] ?? null ) ) );
            if ( $card ) { $cards[] = $card; }
        }
        return $cards;
    }

    private static function collect( $item, $depth = 0 ) {
        if ( $depth > 4 || ! is_array( $item ) ) { return array(); }
        $cards = self::text_links( $item['text'] ?? '' );
        if ( isset( $item['pretty_cards'] ) ) { $cards = array_merge( $cards, self::carousel( $item['pretty_cards'] ) ); }
        foreach ( array_slice( (array) ( $item['attachments'] ?? array() ), 0, 30 ) as $attachment ) {
            $type = $attachment['type'] ?? '';
            $value = (array) ( $attachment[ $type ] ?? array() );
            $card = null;
            if ( 'market' === $type ) {
                $id = isset( $value['owner_id'], $value['id'] ) ? (int) $value['owner_id'] . '_' . (int) $value['id'] : '';
                $card = self::candidate( ( $value['url'] ?? '' ) ?: ( $id ? 'https://vk.ru/market' . $id : '' ), $value['title'] ?? '', self::price( $value['price'] ?? null ), 'market', array(
                    'market_id' => $id, 'sku' => mb_substr( sanitize_text_field( (string) ( $value['sku'] ?? '' ) ), 0, 64 ),
                    'old_price' => isset( $value['price']['old_amount'] ) ? self::price( array( 'amount' => $value['price']['old_amount'] ) ) : null,
                ) );
            } elseif ( 'link' === $type ) {
                $card = self::candidate( $value['url'] ?? $value['button']['action']['url'] ?? '', $value['title'] ?? '', self::price( $value['product']['price'] ?? null ), isset( $value['product'] ) ? 'link_product' : 'link' );
            } elseif ( 'pretty_cards' === $type ) {
                $cards = array_merge( $cards, self::carousel( $value ) );
            } elseif ( 'video' === $type || 'photo' === $type ) {
                $cards = array_merge( $cards, self::text_links( $value['description'] ?? $value['text'] ?? '' ) );
                if ( ! empty( $value['link'] ) && is_array( $value['link'] ) ) {
                    $cards = array_merge( $cards, self::collect( array( 'attachments' => array( array( 'type' => 'link', 'link' => $value['link'] ) ) ), $depth + 1 ) );
                }
            }
            if ( $card ) { $cards[] = $card; }
        }
        foreach ( array_slice( (array) ( $item['copy_history'] ?? array() ), 0, 10 ) as $origin ) {
            $cards = array_merge( $cards, self::collect( $origin, $depth + 1 ) );
        }
        return $cards;
    }

    public static function extract( $item ) {
        $result = array(
            'product_title' => '', 'product_price' => null, 'product_old_price' => null, 'product_sku' => '', 'market_id' => '',
            'link_url' => '', 'link_title' => '', 'link_domain' => '', 'cards' => '', 'text_product' => '', 'text_price' => null,
            'product_source' => '', 'commerce_version' => self::VERSION,
        );
        $cards = self::collect( $item );
        $rank = static function ( $card ) {
            $attachment = array( 'market' => 50, 'carousel' => 40, 'link_product' => 30, 'link' => 20, 'text' => 0 );
            return ( $card['recognized'] ? 100 + ( $attachment[ $card['source'] ] ?? 0 ) : 0 ) + ( $card['shop'] ? 10 : 0 ) + ( $card['title'] ? 5 : 0 );
        };
        usort( $cards, static function ( $a, $b ) use ( $rank ) { return $rank( $b ) <=> $rank( $a ); } );
        $unique = array();
        foreach ( $cards as $card ) {
            // The original URL (including attribution) is preserved; deduplicate exact destinations only.
            $key = $card['url'];
            if ( isset( $unique[ $key ] ) ) {
                if ( '' === $unique[ $key ]['title'] && '' !== $card['title'] ) {
                    $unique[ $key ]['source'] = $card['source'];
                    $unique[ $key ]['recognized'] = $card['recognized'];
                }
                foreach ( array( 'title', 'sku', 'market_id' ) as $field ) {
                    if ( '' === $unique[ $key ][ $field ] ) { $unique[ $key ][ $field ] = $card[ $field ]; }
                }
                // Text prices never overwrite a VK attachment's price.
                if ( null === $unique[ $key ]['price'] ) {
                    $unique[ $key ]['price'] = $card['price'];
                    $unique[ $key ]['price_source'] = $card['price_source'];
                }
                continue;
            }
            $unique[ $key ] = $card;
        }
        $cards = array_slice( array_values( $unique ), 0, 20 );
        if ( ! $cards ) { return $result; }
        $primary = $cards[0];
        $result['cards'] = wp_json_encode( $cards );
        $result['product_source'] = $primary['recognized'] ? $primary['source'] : '';
        $result['product_title'] = $primary['recognized'] ? $primary['title'] : '';
        $result['product_price'] = $primary['recognized'] ? $primary['price'] : null;
        $result['product_old_price'] = $primary['old_price'];
        $result['product_sku'] = $primary['sku'];
        $result['market_id'] = $primary['market_id'];
        $result['text_product'] = $primary['recognized'] ? '' : $primary['title'];
        $result['text_price'] = $primary['recognized'] ? null : $primary['price'];
        if ( ! self::internal( $primary['url'] ) ) {
            $result['link_url'] = $primary['url'];
            $result['link_domain'] = substr( preg_replace( '/^www\./', '', (string) wp_parse_url( $primary['url'], PHP_URL_HOST ) ), 0, 120 );
        }
        return $result;
    }
}

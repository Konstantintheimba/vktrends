<?php
defined( 'ABSPATH' ) || exit;

/** Parses HTML/JSON already returned by a store; does not execute JavaScript. */
final class VKT_Product_Page {
    private static function image( $value ) {
        if ( is_array( $value ) ) { $value = $value['url'] ?? $value['contentUrl'] ?? $value[0] ?? ''; }
        if ( is_array( $value ) ) { $value = $value['url'] ?? $value['contentUrl'] ?? ''; }
        return is_string( $value ) ? esc_url_raw( $value, array( 'https', 'http' ) ) : '';
    }

    private static function offer_price( $offers ) {
        if ( ! is_array( $offers ) ) { return null; }
        if ( isset( $offers[0] ) ) { $offers = $offers[0]; }
        $price = $offers['price'] ?? $offers['priceSpecification']['price'] ?? $offers['lowPrice'] ?? null;
        return is_scalar( $price ) ? VKT_Commerce::price( $price ) : null;
    }

    private static function walk( $data, &$products, &$meta, $sku, &$budget, $depth = 0, $schema = false ) {
        if ( --$budget < 0 || $depth > 18 || ! is_array( $data ) ) { return; }
        $types = (array) ( $data['@type'] ?? array() );
        $is_product = in_array( 'Product', $types, true ) || in_array( 'https://schema.org/Product', $types, true );
        $id = $data['sku'] ?? $data['productId'] ?? $data['product_id'] ?? $data['id'] ?? '';
        $matches = $sku && ( ( is_scalar( $id ) && (string) $id === $sku ) || ( is_string( $data['url'] ?? null ) && VKT_Links::sku_from_url( $data['url'] ) === $sku ) );
        // Hydration data may contain recommendations: accept only the requested product ID.
        if ( ( $schema && $is_product ) || ( $matches && ( isset( $data['name'] ) || isset( $data['title'] ) ) ) ) {
            $price = self::offer_price( $data['offers'] ?? array() );
            if ( null === $price && $matches && is_scalar( $data['price'] ?? null ) ) { $price = VKT_Commerce::price( $data['price'] ); }
            $products[] = array( 'title' => VKT_Commerce::title( $data['name'] ?? $data['title'] ?? '' ), 'price' => $price, 'image' => self::image( $data['image'] ?? $data['picture'] ?? '' ), 'match' => (bool) $matches );
        }
        $property = $data['property'] ?? $data['name'] ?? '';
        if ( is_string( $property ) && in_array( $property, array( 'og:title', 'og:image', 'og:type', 'product:price:amount' ), true ) && is_string( $data['content'] ?? null ) ) {
            $meta[ $property ] = $data['content'];
        }
        foreach ( $data as $key => $value ) {
            // ItemList/recommendation products are not the product being viewed.
            if ( in_array( $key, array( 'itemListElement', 'recommendations', 'relatedProducts', 'isRelatedTo' ), true ) ) { continue; }
            if ( is_array( $value ) ) { self::walk( $value, $products, $meta, $sku, $budget, $depth + 1, $schema ); }
        }
    }

    public static function parse( $html, $url = '' ) {
        $result = array( 'title' => '', 'price' => null, 'image' => '', 'recognized' => false );
        if ( '' === trim( (string) $html ) ) { return $result; }
        $previous = libxml_use_internal_errors( true );
        $document = new DOMDocument();
        $document->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        $xpath = new DOMXPath( $document );
        $meta = array();
        foreach ( $xpath->query( '//meta[@content]' ) as $node ) {
            $key = $node->getAttribute( 'property' ) ?: $node->getAttribute( 'name' ) ?: $node->getAttribute( 'itemprop' );
            if ( $key && ! isset( $meta[ $key ] ) ) { $meta[ $key ] = $node->getAttribute( 'content' ); }
        }
        $products = array();
        $sku = VKT_Links::sku_from_url( $url );
        $budget = 12000;
        foreach ( $xpath->query( '//script' ) as $node ) {
            $script = trim( $node->textContent );
            $data = json_decode( $script, true );
            $schema = 'application/ld+json' === strtolower( trim( $node->getAttribute( 'type' ) ) );
            if ( is_array( $data ) ) { self::walk( $data, $products, $meta, $sku, $budget, 0, $schema ); }
            // Some SPA responses put escaped meta objects in a JS string or assignment.
            // Decode JSON escapes (including Unicode), never eval the script.
            foreach ( array( $script, str_replace( '\\"', '"', $script ) ) as $serialized ) {
                preg_match_all( '~\{[^{}]{0,2000}"(?:og:title|og:image|og:type|product:price:amount)"[^{}]{0,2000}\}~u', $serialized, $matches );
                foreach ( array_slice( $matches[0], 0, 30 ) as $fragment ) {
                    $object = json_decode( $fragment, true );
                    if ( is_array( $object ) ) { self::walk( $object, $products, $meta, $sku, $budget ); }
                }
            }
        }
        usort( $products, static function ( $a, $b ) { return $b['match'] <=> $a['match']; } );
        foreach ( $products as $product ) {
            if ( $product['title'] ) { $result = array_merge( $result, $product, array( 'recognized' => true ) ); break; }
        }
        // Microdata is scoped to a Product container, not the first unrelated itemprop=name.
        if ( ! $result['recognized'] ) {
            foreach ( $xpath->query( '//*[@itemscope and contains(@itemtype,"schema.org/Product")]' ) as $scope ) {
                $fields = array();
                foreach ( array( 'name', 'price', 'image' ) as $field ) {
                    $node = $xpath->query( './/*[@itemprop="' . $field . '"]', $scope )->item( 0 );
                    $fields[ $field ] = $node ? ( $node->getAttribute( 'content' ) ?: $node->getAttribute( 'src' ) ?: $node->textContent ) : '';
                }
                $title = VKT_Commerce::title( $fields['name'] );
                if ( $title ) {
                    $result = array( 'title' => $title, 'price' => VKT_Commerce::price( $fields['price'] ), 'image' => self::image( $fields['image'] ), 'recognized' => true );
                    break;
                }
            }
        }
        if ( ! $result['title'] ) { $result['title'] = $meta['og:title'] ?? $meta['twitter:title'] ?? ''; }
        if ( null === $result['price'] ) {
            $result['price'] = VKT_Commerce::price( $meta['product:price:amount'] ?? $meta['og:price:amount'] ?? $meta['price'] ?? '' );
        }
        if ( ! $result['image'] ) { $result['image'] = self::image( $meta['og:image'] ?? $meta['twitter:image'] ?? '' ); }
        if ( ! $result['title'] ) {
            foreach ( array( '//h1', '//title' ) as $query ) {
                $node = $xpath->query( $query )->item( 0 );
                if ( $node && trim( $node->textContent ) ) { $result['title'] = trim( $node->textContent ); break; }
            }
        }
        // Keep guard titles for the caller to classify the response as blocked.
        if ( VKT_Links::is_guard_page( array( 'title' => $result['title'], 'price' => null ) ) ) {
            $result['price'] = null;
            $result['recognized'] = false;
            return $result;
        }
        $result['title'] = VKT_Commerce::title( $result['title'] );
        $result['recognized'] = '' !== $result['title'] && ( $result['recognized'] || $sku || null !== $result['price'] || in_array( $meta['og:type'] ?? '', array( 'product', 'og:product' ), true ) );
        unset( $result['match'] );
        return $result;
    }
}

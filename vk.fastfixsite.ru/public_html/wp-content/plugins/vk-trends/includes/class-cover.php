<?php
defined( 'ABSPATH' ) || exit;

/**
 * Обложка записи в оформлении сообщества: фото, поверх — градиент цвета
 * паблика, его название, заголовок новости и метка рубрики.
 *
 * Рисует сам плагин (GD), а не нейросеть: модель картинок не умеет ровно
 * набирать кириллицу и каждый раз меняла бы макет, а паблик узнают в ленте
 * как раз по одинаковому. Шрифт — PT Sans Bold (открытая лицензия OFL,
 * assets/fonts), он идёт с плагином: системных шрифтов на хостинге может не быть.
 */
final class VKT_Cover {
    const FONT = 'assets/fonts/PT_Sans-Web-Bold.ttf';
    const COLOR = '#1f6fe5';
    const TITLE_MAX = 160;
    const SOURCE_BYTES = 20971520;

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_cover', $message, array( 'status' => $status ) );
    }

    /** Макет сообщества в рабочий вид. Пустое название — подставится имя группы. */
    public static function settings( $data ) {
        $data = is_array( $data ) ? $data : array();
        $clean = static fn( $value, $limit ) => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( is_string( $value ) ? $value : '' ) ) ), 0, $limit );
        $color = is_string( $data['color'] ?? null ) && preg_match( '/^#[0-9a-f]{6}$/i', $data['color'] ) ? strtolower( $data['color'] ) : self::COLOR;
        return array(
            'name' => $clean( $data['name'] ?? '', 60 ),
            // Метку можно стереть совсем — тогда плашки на обложке не будет.
            'label' => array_key_exists( 'label', $data ) ? $clean( $data['label'], 30 ) : 'Новость',
            'color' => $color,
            'ratio' => isset( VKT_Images::SIZES[ $data['ratio'] ?? '' ] ) ? $data['ratio'] : 'landscape',
        );
    }

    public static function available() {
        return function_exists( 'imagettftext' ) && function_exists( 'imagecreatetruecolor' ) && is_file( VKT_DIR . self::FONT );
    }

    /** Заголовок для обложки — первая строка записи. */
    public static function title( $text ) {
        $line = (string) strtok( trim( wp_strip_all_tags( (string) $text ) ), "\r\n" );
        $line = trim( (string) preg_replace( '/\s+/u', ' ', $line ) );
        return mb_strlen( $line ) > self::TITLE_MAX ? rtrim( mb_substr( $line, 0, self::TITLE_MAX - 1 ) ) . '…' : $line;
    }

    private static function width( $text, $size, $font ) {
        $box = imagettfbbox( $size, 0, $font, $text );
        return abs( $box[2] - $box[0] );
    }

    /** Текст по строкам, чтобы каждая помещалась в ширину. Слово длиннее строки остаётся целым. */
    public static function wrap( $text, $size, $max_width, $font = null ) {
        $font = $font ?: VKT_DIR . self::FONT;
        $lines = array();
        $line = '';
        foreach ( preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
            $try = '' === $line ? $word : $line . ' ' . $word;
            if ( '' !== $line && self::width( $try, $size, $font ) > $max_width ) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ( '' !== $line ) {
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Сама картинка. $photo — готовое изображение GD или null: тогда фон —
     * чистый градиент цвета сообщества. Отдельно от сохранения, чтобы макет
     * проверялся без WordPress.
     */
    public static function render( $settings, $title, $photo = null ) {
        list( $w, $h ) = VKT_Images::SIZES[ $settings['ratio'] ];
        $font = VKT_DIR . self::FONT;
        list( $r, $g, $b ) = array_map( 'hexdec', str_split( ltrim( $settings['color'], '#' ), 2 ) );
        $canvas = imagecreatetruecolor( $w, $h );
        imagealphablending( $canvas, true );
        if ( $photo ) {
            // Фото заполняет кадр целиком, лишнее по краям обрезается.
            $sw = imagesx( $photo );
            $sh = imagesy( $photo );
            $scale = max( $w / $sw, $h / $sh );
            $cw = (int) round( $w / $scale );
            $ch = (int) round( $h / $scale );
            imagecopyresampled( $canvas, $photo, 0, 0, (int) ( ( $sw - $cw ) / 2 ), (int) ( ( $sh - $ch ) / 2 ), $w, $h, $cw, $ch );
        } else {
            imagefilledrectangle( $canvas, 0, 0, $w, $h, imagecolorallocate( $canvas, (int) ( $r * 0.22 ), (int) ( $g * 0.22 ), (int) ( $b * 0.22 ) ) );
        }
        $alpha = static fn( $opacity ) => 127 - (int) round( 127 * max( 0, min( 1, $opacity ) ) );
        // Затемнение: белый текст должен читаться на любом фото.
        imagefilledrectangle( $canvas, 0, 0, $w, $h, imagecolorallocatealpha( $canvas, 8, 14, 26, $alpha( $photo ? 0.5 : 0.15 ) ) );
        $from = (int) ( $h * 0.4 );
        for ( $y = $from; $y < $h; ++$y ) {
            $t = ( $y - $from ) / ( $h - $from );
            imageline( $canvas, 0, $y, $w, $y, imagecolorallocatealpha( $canvas, $r, $g, $b, $alpha( 0.95 * $t ** 1.4 ) ) );
        }
        $white = imagecolorallocate( $canvas, 255, 255, 255 );
        $shadow = imagecolorallocatealpha( $canvas, 0, 0, 0, $alpha( 0.35 ) );
        $text = static function ( $size, $x, $y, $string, $color = null ) use ( $canvas, $font, $white, $shadow ) {
            imagettftext( $canvas, $size, 0, $x + 2, $y + 2, $shadow, $font, $string );
            imagettftext( $canvas, $size, 0, $x, $y, $color ?? $white, $font, $string );
        };
        $pad = (int) round( $w * 0.065 );
        $inner = $w - 2 * $pad;

        // Название сообщества: кружок с первой буквой вместо логотипа и имя заглавными.
        $name = mb_strtoupper( $settings['name'] );
        $name_size = max( 14, (int) round( min( $w, $h ) * 0.034 ) );
        $top = $pad;
        if ( '' !== $name ) {
            $dot = (int) round( $name_size * 2.5 );
            imagefilledellipse( $canvas, $pad + (int) ( $dot / 2 ), $top + (int) ( $dot / 2 ), $dot, $dot, $white );
            $initial = mb_substr( $name, 0, 1 );
            $brand = imagecolorallocate( $canvas, $r, $g, $b );
            imagettftext( $canvas, $name_size, 0, $pad + (int) ( ( $dot - self::width( $initial, $name_size, $font ) ) / 2 ), $top + (int) ( $dot / 2 + $name_size / 2 ), $brand, $font, $initial );
            $room = $inner - $dot - (int) ( $name_size * 0.9 );
            while ( mb_strlen( $name ) > 4 && self::width( $name, $name_size, $font ) > $room ) {
                $name = rtrim( mb_substr( $name, 0, -2 ) ) . '…';
            }
            $text( $name_size, $pad + $dot + (int) ( $name_size * 0.9 ), $top + (int) ( $dot / 2 + $name_size / 2 ), $name );
            $top += $dot;
        }

        // Метка рубрики внизу: полупрозрачная плашка.
        $bottom = $h - $pad;
        if ( '' !== $settings['label'] ) {
            $label_size = max( 13, (int) round( min( $w, $h ) * 0.03 ) );
            $pill_h = (int) round( $label_size * 2.6 );
            $pill_w = self::width( $settings['label'], $label_size, $font ) + (int) ( $label_size * 2.6 );
            $pill = imagecolorallocatealpha( $canvas, 255, 255, 255, $alpha( 0.22 ) );
            $y1 = $bottom - $pill_h;
            // Прямоугольник не доходит до центров дуг на пиксель: иначе полупрозрачные слои дают шов.
            imagefilledrectangle( $canvas, $pad + (int) ( $pill_h / 2 ) + 1, $y1, $pad + $pill_w - (int) ( $pill_h / 2 ) - 1, $y1 + $pill_h - 1, $pill );
            imagefilledarc( $canvas, $pad + (int) ( $pill_h / 2 ), $y1 + (int) ( $pill_h / 2 ), $pill_h, $pill_h, 90, 270, $pill, IMG_ARC_PIE );
            imagefilledarc( $canvas, $pad + $pill_w - (int) ( $pill_h / 2 ), $y1 + (int) ( $pill_h / 2 ), $pill_h, $pill_h, 270, 90, $pill, IMG_ARC_PIE );
            imagettftext( $canvas, $label_size, 0, $pad + (int) ( $label_size * 1.3 ), $y1 + (int) ( $pill_h / 2 + $label_size / 2 ), $white, $font, $settings['label'] );
            $bottom = $y1;
        }

        // Заголовок: самый крупный кегль, при котором он укладывается в отведённое место.
        $gap = (int) round( $h * 0.05 );
        $room = max( 1, $bottom - $top - 2 * $gap );
        $size = (int) round( min( $w, $h ) * 0.085 );
        $floor = (int) round( min( $w, $h ) * 0.04 );
        do {
            $lines = self::wrap( $title, $size, $inner, $font );
            $line_h = (int) round( $size * 1.55 );
            if ( count( $lines ) * $line_h <= $room || $size <= $floor ) {
                break;
            }
            $size -= 2;
        } while ( true );
        // Даже мелким кеглем не влезло — обрезаем по строкам, а не уводим текст под плашку.
        $fit = max( 1, (int) floor( $room / $line_h ) );
        if ( count( $lines ) > $fit ) {
            $lines = array_slice( $lines, 0, $fit );
            $lines[ $fit - 1 ] = rtrim( $lines[ $fit - 1 ], ' ,.:;—-' ) . '…';
        }
        $y = $top + $gap + (int) ( ( $room - count( $lines ) * $line_h ) / 2 ) + $size + (int) ( ( $line_h - $size ) / 2 );
        foreach ( $lines as $line ) {
            $text( $size, $pad, $y, $line );
            $y += $line_h;
        }
        return $canvas;
    }

    /** Фон обложки: файл своей медиатеки или фото по адресу (снимок статьи). */
    private static function photo( $media_id, $url ) {
        $raw = '';
        if ( absint( $media_id ) ) {
            $item = VKT_Media::item( $media_id );
            if ( is_wp_error( $item ) || 'image' !== $item['type'] || ! VKT_Media::owned( $media_id ) ) {
                return self::error( 'Фото для обложки не найдено в вашей медиатеке.', 404 );
            }
            if ( $item['size'] > self::SOURCE_BYTES ) {
                return self::error( 'Фото для обложки больше 20 МБ.' );
            }
            $raw = (string) file_get_contents( $item['path'] );
        } elseif ( is_string( $url ) && '' !== trim( $url ) ) {
            $url = esc_url_raw( trim( $url ), array( 'https' ) );
            if ( ! $url || ! wp_http_validate_url( $url ) ) {
                return self::error( 'Неверный адрес фото для обложки.' );
            }
            $response = wp_safe_remote_get( $url, array( 'timeout' => 30, 'redirection' => 3, 'limit_response_size' => self::SOURCE_BYTES ) );
            if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                return self::error( 'Фото для обложки не скачалось.', 502 );
            }
            $raw = (string) wp_remote_retrieve_body( $response );
        } else {
            return null;
        }
        $image = '' === $raw ? false : @imagecreatefromstring( $raw );
        return $image ? $image : self::error( 'Фото для обложки не читается как изображение.', 422 );
    }

    /**
     * Обложка под запись. $preview — отдать картинку в ответе, не сохраняя:
     * для примера в настройках. Иначе файл кладётся в медиатеку.
     */
    public static function make( $settings, $title, $media_id = 0, $url = '', $preview = false ) {
        if ( ! self::available() ) {
            return self::error( 'На сервере нет графической библиотеки GD с поддержкой шрифтов (FreeType) — обложку нарисовать нечем. Её включает хостинг в настройках PHP.', 501 );
        }
        $title = self::title( $title );
        if ( '' === $title ) {
            return self::error( 'Для обложки нужен заголовок — первая строка текста записи.' );
        }
        $photo = self::photo( $media_id, $url );
        if ( is_wp_error( $photo ) ) {
            return $photo;
        }
        $canvas = self::render( $settings, $title, $photo );
        ob_start();
        imagejpeg( $canvas, null, 88 );
        $jpeg = (string) ob_get_clean();
        if ( $preview ) {
            return array( 'preview' => 'data:image/jpeg;base64,' . base64_encode( $jpeg ) );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $name = 'vkt-cover-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.jpg';
        $tmp = wp_tempnam( $name );
        if ( ! $tmp || false === file_put_contents( $tmp, $jpeg ) ) {
            return self::error( 'Не удалось сохранить обложку во временный файл.', 500 );
        }
        $attachment_id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp, 'size' => strlen( $jpeg ), 'error' => 0 ), 0, 'Обложка: ' . $title );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return self::error( 'WordPress не сохранил обложку: ' . $attachment_id->get_error_message(), 500 );
        }
        // Метка остаётся с файлом: запись из очереди, открытая заново, тоже узнаёт свою обложку.
        update_post_meta( $attachment_id, '_vkt_cover', 1 );
        return VKT_Media::public_item( $attachment_id );
    }
}

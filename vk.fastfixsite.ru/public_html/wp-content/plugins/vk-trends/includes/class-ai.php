<?php
defined( 'ABSPATH' ) || exit;

/** Закрытый серверный клиент генерации текста и медиа через xAI. */
final class VKT_AI {
    const TEXT_MODEL = 'grok-4.6';
    const IMAGE_MODEL = 'grok-imagine-image-2.0';
    const VIDEO_MODEL = 'grok-imagine-video-1.5';
    // Соотношения сторон, которые принимает xAI, под привычные подписи VK.
    const IMAGE_RATIOS = array( 'portrait' => '3:4', 'square' => '1:1', 'landscape' => '16:9', 'story' => '9:16' );
    const VIDEO_RATIOS = array( 'story' => '9:16', 'square' => '1:1', 'landscape' => '16:9' );

    public static function configured() {
        return defined( 'VKT_XAI_API_KEY' ) && '' !== trim( (string) VKT_XAI_API_KEY );
    }

    public static function public_status() {
        return array(
            'configured' => self::configured(),
            'text_model' => self::TEXT_MODEL,
            'image_model' => self::IMAGE_MODEL,
            'video_model' => self::VIDEO_MODEL,
            'image_ratios' => array_keys( self::IMAGE_RATIOS ),
            'video_ratios' => array_keys( self::VIDEO_RATIOS ),
            // Остаток на сегодня; null — без ограничений (администратор).
            'quota' => VKT_Account::ai_quota(),
        );
    }

    private static function error( $message, $status = 400, $retryable = false ) {
        return new WP_Error( 'vkt_ai', $message, array( 'status' => $status, 'retryable' => $retryable ) );
    }

    private static function prompt( $value ) {
        $value = is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : '';
        return mb_strlen( $value ) >= 3 && mb_strlen( $value ) <= 5000 ? $value : self::error( 'Опишите задачу для генерации — от 3 до 5000 символов.' );
    }

    private static function request( $method, $path, $body = null, $timeout = 90 ) {
        if ( ! self::configured() ) {
            return self::error( 'Ключ xAI не настроен на сервере.' );
        }
        $args = array(
            'method' => $method,
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 2097152,
            'headers' => array(
                'Authorization' => 'Bearer ' . trim( (string) VKT_XAI_API_KEY ),
                'Content-Type' => 'application/json',
            ),
        );
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }
        $started = microtime( true );
        $response = wp_remote_request( 'https://api.x.ai' . $path, $args );
        $duration = (int) round( ( microtime( true ) - $started ) * 1000 );
        if ( is_wp_error( $response ) ) {
            VKT_Store::log( 'xai.' . basename( $path ), 'ai', 'error', 0, 'Нет связи с xAI', $duration );
            return self::error( 'Не удалось подключиться к xAI.', 502, true );
        }
        $http = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $http < 200 || $http >= 300 || ! is_array( $data ) ) {
            $message = is_array( $data ) ? sanitize_text_field( (string) ( $data['error']['message'] ?? $data['error'] ?? '' ) ) : '';
            VKT_Store::log( 'xai.' . basename( $path ), 'ai', 'error', $http, 'xAI отклонил запрос', $duration );
            return self::error( 'xAI отклонил запрос' . ( $message ? ': ' . mb_substr( $message, 0, 180 ) : '.' ), in_array( $http, array( 401, 403 ), true ) ? 401 : 422, 429 === $http || $http >= 500 );
        }
        VKT_Store::log( 'xai.' . basename( $path ), 'ai', 'ok', $http, 'Генерация xAI выполнена', $duration );
        return $data;
    }

    public static function generate_text( $prompt, $current = '' ) {
        $prompt = self::prompt( $prompt );
        if ( is_wp_error( $prompt ) ) {
            return $prompt;
        }
        $current = is_string( $current ) ? mb_substr( trim( wp_strip_all_tags( $current ) ), 0, 16000 ) : '';
        $input = "Подготовь готовый текст поста для сообщества VK на русском языке. Верни только финальный текст без пояснений, кавычек и Markdown-заголовков. Не выдумывай факты, цены, ссылки и обещания. Максимум 16000 символов.\n\nЗадача: " . $prompt;
        if ( '' !== $current ) {
            $input .= "\n\nТекущий черновик, который можно улучшить:\n" . $current;
        }
        $result = self::request( 'POST', '/v1/responses', array(
            'model' => self::TEXT_MODEL,
            'input' => $input,
            'store' => false,
            'reasoning' => array( 'effort' => 'low' ),
        ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $text = trim( wp_strip_all_tags( self::output_text( $result ) ) );
        return '' === $text ? self::error( 'xAI не вернул текст.', 502 ) : array( 'text' => mb_substr( $text, 0, 16000 ) );
    }

    /** Текст ответа: xAI кладёт его либо в output_text, либо по частям в output[].content[]. */
    private static function output_text( $result ) {
        $text = is_string( $result['output_text'] ?? null ) ? trim( $result['output_text'] ) : '';
        if ( '' !== $text ) {
            return $text;
        }
        foreach ( (array) ( $result['output'] ?? array() ) as $output ) {
            foreach ( (array) ( $output['content'] ?? array() ) as $content ) {
                if ( 'output_text' === ( $content['type'] ?? '' ) && is_string( $content['text'] ?? null ) ) {
                    $text .= ( $text ? "\n" : '' ) . trim( $content['text'] );
                }
            }
        }
        return $text;
    }

    /**
     * Серия текстов одним запросом. По одному было бы и дороже, и хуже: модель
     * не знает, о чём уже написала, и посты повторяли бы друг друга. Поэтому
     * просим сразу список и разбираем ответ.
     */
    public static function generate_series( $prompt, $count ) {
        $prompt = self::prompt( $prompt );
        if ( is_wp_error( $prompt ) ) {
            return $prompt;
        }
        $count = max( 1, min( VKT_Publisher::MAX_SERIES_SLOTS, (int) $count ) );
        $input = 'Подготовь ' . $count . ' готовых текстов для постов сообщества VK на русском языке. Это серия на период: посты не должны повторять друг друга и должны раскрывать тему с разных сторон.'
            . ' Верни строго JSON вида {"posts":["текст 1","текст 2"]} — без пояснений, без Markdown и без тройных кавычек.'
            . ' Ровно ' . $count . ' элементов, каждый не длиннее 3000 символов. Не выдумывай факты, цены, ссылки и обещания.'
            . "\n\nТема серии: " . $prompt;
        // Серия из десятков текстов пишется дольше одиночного поста.
        $result = self::request( 'POST', '/v1/responses', array(
            'model' => self::TEXT_MODEL,
            'input' => $input,
            'store' => false,
            'reasoning' => array( 'effort' => 'low' ),
        ), 180 );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $posts = self::parse_series( self::output_text( $result ) );
        if ( ! $posts ) {
            return self::error( 'xAI ответил, но собрать из ответа список текстов не удалось. Повторите запрос или уточните тему.', 502, true );
        }
        return array( 'posts' => array_slice( $posts, 0, $count ), 'requested' => $count );
    }

    /**
     * Ответ модели в список текстов. Просьбу вернуть чистый JSON она выполняет
     * не всегда: бывает обёртка в тройные кавычки, массив без ключа posts и
     * обычный пронумерованный список. Разбираем все три случая.
     */
    private static function parse_series( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return array();
        }
        if ( preg_match( '/```(?:json)?\s*(.+?)```/s', $raw, $fenced ) ) {
            $raw = trim( $fenced[1] );
        }
        $decoded = json_decode( $raw, true );
        $items = array();
        if ( is_array( $decoded ) ) {
            $items = isset( $decoded['posts'] ) && is_array( $decoded['posts'] ) ? $decoded['posts'] : $decoded;
        }
        if ( ! $items && preg_match_all( '/^\s*\d{1,2}[.)]\s*(.+?)(?=\n\s*\d{1,2}[.)]|\z)/ms', $raw, $matches ) ) {
            $items = $matches[1];
        }
        $texts = array();
        foreach ( (array) $items as $item ) {
            if ( is_string( $item ) ) {
                $text = $item;
            } elseif ( is_array( $item ) ) {
                $text = (string) ( $item['text'] ?? $item['message'] ?? $item['post'] ?? '' );
            } else {
                $text = '';
            }
            $text = trim( wp_strip_all_tags( $text ) );
            if ( '' !== $text ) {
                $texts[] = mb_substr( $text, 0, 16000 );
            }
        }
        return $texts;
    }

    public static function generate_image( $prompt, $ratio = 'portrait' ) {
        $prompt = self::prompt( $prompt );
        if ( is_wp_error( $prompt ) ) {
            return $prompt;
        }
        $result = self::request( 'POST', '/v1/images/generations', array(
            'model' => self::IMAGE_MODEL,
            'prompt' => $prompt,
            'n' => 1,
            'response_format' => 'url',
            'aspect_ratio' => self::IMAGE_RATIOS[ is_string( $ratio ) ? $ratio : '' ] ?? self::IMAGE_RATIOS['portrait'],
            'resolution' => '1k',
            'quality' => 'low',
        ), 150 );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $url = (string) ( $result['data'][0]['url'] ?? '' );
        return VKT_Media::sideload( $url, 'image' );
    }

    public static function start_video( $prompt, $ratio = 'story' ) {
        $prompt = self::prompt( $prompt );
        if ( is_wp_error( $prompt ) ) {
            return $prompt;
        }
        $result = self::request( 'POST', '/v1/videos/generations', array(
            'model' => self::VIDEO_MODEL,
            'prompt' => $prompt,
            'duration' => 6,
            'aspect_ratio' => self::VIDEO_RATIOS[ is_string( $ratio ) ? $ratio : '' ] ?? self::VIDEO_RATIOS['story'],
            'resolution' => '720p',
        ), 60 );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $request_id = sanitize_text_field( (string) ( $result['request_id'] ?? '' ) );
        if ( ! preg_match( '/^[a-zA-Z0-9_-]{8,100}$/', $request_id ) ) {
            return self::error( 'xAI не вернул ID задачи генерации видео.', 502 );
        }
        return array( 'status' => 'pending', 'request_id' => $request_id );
    }

    public static function video_status( $request_id ) {
        $request_id = is_string( $request_id ) ? trim( $request_id ) : '';
        if ( ! preg_match( '/^[a-zA-Z0-9_-]{8,100}$/', $request_id ) ) {
            return self::error( 'Неверный ID задачи генерации видео.' );
        }
        $cache_key = 'vkt_xai_video_' . hash( 'sha256', $request_id );
        $attachment_id = absint( get_transient( $cache_key ) );
        if ( $attachment_id ) {
            $item = VKT_Media::public_item( $attachment_id );
            if ( ! is_wp_error( $item ) ) {
                return array( 'status' => 'done', 'media' => $item );
            }
            // Готовый ролик удалили из медиатеки: забываем привязку и спрашиваем xAI заново.
            delete_transient( $cache_key );
        }
        $result = self::request( 'GET', '/v1/videos/' . rawurlencode( $request_id ), null, 30 );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $status = sanitize_key( (string) ( $result['status'] ?? 'pending' ) );
        if ( 'done' !== $status ) {
            if ( in_array( $status, array( 'failed', 'expired' ), true ) ) {
                return self::error( 'Генерация видео завершилась со статусом: ' . $status . '.', 422 );
            }
            return array( 'status' => 'pending', 'progress' => min( 100, absint( $result['progress'] ?? 0 ) ) );
        }
        $media = VKT_Media::sideload( (string) ( $result['video']['url'] ?? '' ), 'video' );
        if ( is_wp_error( $media ) ) {
            return $media;
        }
        set_transient( $cache_key, $media['id'], 7 * DAY_IN_SECONDS );
        return array( 'status' => 'done', 'media' => $media );
    }
}

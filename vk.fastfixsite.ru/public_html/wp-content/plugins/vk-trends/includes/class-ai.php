<?php
defined( 'ABSPATH' ) || exit;

/**
 * Серверный клиент генерации. Тексты пишет выбранная модель из списка —
 * любой поставщик с OpenAI-совместимым /chat/completions (xAI, DeepSeek,
 * Qwen, Gemini, OpenRouter, свой). Картинки и видео — только xAI.
 */
final class VKT_AI {
    const TEXT_MODEL = 'grok-4.6';
    const MODELS_OPTION = 'vkt_text_models';
    // Готовые настройки поставщиков: адрес и модель подставляются в форму, их можно поправить.
    const PRESETS = array(
        'xai' => array( 'title' => 'xAI Grok', 'base' => 'https://api.x.ai/v1', 'model' => 'grok-4.6' ),
        'deepseek' => array( 'title' => 'DeepSeek', 'base' => 'https://api.deepseek.com', 'model' => 'deepseek-chat' ),
        'qwen' => array( 'title' => 'Qwen (Alibaba Cloud)', 'base' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'model' => 'qwen-plus' ),
        'gemini' => array( 'title' => 'Google Gemini', 'base' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-2.5-flash' ),
        'openrouter' => array( 'title' => 'OpenRouter', 'base' => 'https://openrouter.ai/api/v1', 'model' => 'deepseek/deepseek-chat' ),
        'custom' => array( 'title' => 'Свой, OpenAI-совместимый', 'base' => '', 'model' => '' ),
    );
    // Модель xAI, которая умеет инструмент web_search в /v1/responses.
    const SEARCH_MODEL = 'grok-4.7';
    const IMAGE_MODEL = 'grok-imagine-image-2.0';
    const VIDEO_MODEL = 'grok-imagine-video-1.5';
    // Соотношения сторон, которые принимает xAI, под привычные подписи VK.
    const IMAGE_RATIOS = array( 'portrait' => '3:4', 'square' => '1:1', 'landscape' => '16:9', 'story' => '9:16' );
    const VIDEO_RATIOS = array( 'story' => '9:16', 'square' => '1:1', 'landscape' => '16:9' );

    public static function configured() {
        return defined( 'VKT_XAI_API_KEY' ) && '' !== trim( (string) VKT_XAI_API_KEY );
    }

    /** Для текстов годится любая сохранённая модель, а не только xAI. */
    public static function text_configured() {
        return (bool) self::models();
    }

    /**
     * Модели для текстов: сохранённые администратором плюс xAI из
     * VKT_XAI_API_KEY, если константа задана. Ключи здесь уже расшифрованы —
     * наружу отдаётся только text_models().
     */
    private static function models() {
        $stored = get_option( self::MODELS_OPTION, array() );
        $models = array();
        if ( self::configured() ) {
            $models['xai'] = array( 'title' => 'xAI Grok (wp-config.php)', 'preset' => 'xai', 'base' => self::PRESETS['xai']['base'], 'model' => self::TEXT_MODEL, 'key' => trim( (string) VKT_XAI_API_KEY ), 'builtin' => true );
        }
        foreach ( (array) ( $stored['models'] ?? array() ) as $id => $entry ) {
            if ( ! is_array( $entry ) || ! preg_match( '/^[a-z0-9_-]{2,40}$/', (string) $id ) ) {
                continue;
            }
            $key = VKT_Tokens::unseal( (string) ( $entry['key'] ?? '' ) );
            if ( '' === $key ) {
                continue;
            }
            $models[ $id ] = array(
                'title' => (string) ( $entry['title'] ?? $id ),
                'preset' => (string) ( $entry['preset'] ?? 'custom' ),
                'base' => (string) ( $entry['base'] ?? '' ),
                'model' => (string) ( $entry['model'] ?? '' ),
                'key' => $key,
                'builtin' => false,
            );
        }
        return $models;
    }

    public static function default_model() {
        $models = self::models();
        $wanted = (string) ( get_option( self::MODELS_OPTION, array() )['default'] ?? '' );
        return isset( $models[ $wanted ] ) ? $wanted : (string) ( array_key_first( $models ) ?? '' );
    }

    /** Список для интерфейса — без ключей. */
    public static function text_models() {
        $out = array();
        foreach ( self::models() as $id => $model ) {
            $out[] = array(
                'id' => $id,
                'title' => $model['title'],
                'preset' => $model['preset'],
                'model' => $model['model'],
                'host' => (string) wp_parse_url( $model['base'], PHP_URL_HOST ),
                'builtin' => $model['builtin'],
                'preview' => mb_substr( $model['key'], 0, 6 ) . '…' . mb_substr( $model['key'], -4 ),
            );
        }
        return $out;
    }

    /**
     * Сохраняет модель. Перед сохранением делает короткий пробный запрос:
     * неверный адрес, ключ или недоступность из страны сервера видны сразу,
     * а не в момент генерации серии.
     */
    public static function save_model( $data ) {
        $preset = sanitize_key( (string) ( $data['preset'] ?? 'custom' ) );
        if ( ! isset( self::PRESETS[ $preset ] ) ) {
            return self::error( 'Неизвестный поставщик.' );
        }
        $base = untrailingslashit( esc_url_raw( trim( (string) ( $data['base'] ?? '' ) ) ?: self::PRESETS[ $preset ]['base'], array( 'https' ) ) );
        $model = trim( (string) ( $data['model'] ?? '' ) ) ?: self::PRESETS[ $preset ]['model'];
        $title = sanitize_text_field( (string) ( $data['title'] ?? '' ) ) ?: self::PRESETS[ $preset ]['title'] . ' · ' . $model;
        $key = trim( (string) ( $data['key'] ?? '' ) );
        if ( '' === $base || ! wp_http_validate_url( $base ) ) {
            return self::error( 'Нужен адрес API по https — например, https://api.deepseek.com.' );
        }
        if ( ! preg_match( '~^[A-Za-z0-9._:/@-]{1,120}$~', $model ) ) {
            return self::error( 'Название модели — латиница, цифры и знаки . _ : / @ -.' );
        }
        if ( ! preg_match( '/^[!-~]{8,512}$/', $key ) ) {
            return self::error( 'Вставьте ключ API целиком.' );
        }
        $probe = self::chat( 'Ответь одним словом: готово', 30, array( 'title' => $title, 'preset' => $preset, 'base' => $base, 'model' => $model, 'key' => $key ) );
        if ( is_wp_error( $probe ) ) {
            return $probe;
        }
        $sealed = VKT_Tokens::seal( $key );
        if ( is_wp_error( $sealed ) ) {
            return $sealed;
        }
        $stored = (array) get_option( self::MODELS_OPTION, array() );
        $id = $preset . '-' . substr( md5( $base . '|' . $model ), 0, 8 );
        $stored['models'][ $id ] = array( 'title' => $title, 'preset' => $preset, 'base' => $base, 'model' => $model, 'key' => $sealed, 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
        if ( empty( $stored['default'] ) || ! empty( $data['default'] ) ) {
            $stored['default'] = $id;
        }
        update_option( self::MODELS_OPTION, $stored, false );
        return array( 'id' => $id, 'title' => $title, 'answer' => mb_substr( $probe, 0, 60 ) );
    }

    public static function delete_model( $id ) {
        $stored = (array) get_option( self::MODELS_OPTION, array() );
        if ( ! isset( $stored['models'][ $id ] ) ) {
            return self::error( 'xai' === $id ? 'Модель из wp-config.php убирается там же — константой VKT_XAI_API_KEY.' : 'Модель не найдена.', 404 );
        }
        unset( $stored['models'][ $id ] );
        if ( ( $stored['default'] ?? '' ) === $id ) {
            $stored['default'] = '';
        }
        update_option( self::MODELS_OPTION, $stored, false );
        return array( 'ok' => true );
    }

    public static function set_default_model( $id ) {
        if ( ! isset( self::models()[ $id ] ) ) {
            return self::error( 'Модель не найдена.', 404 );
        }
        $stored = (array) get_option( self::MODELS_OPTION, array() );
        $stored['default'] = $id;
        update_option( self::MODELS_OPTION, $stored, false );
        return array( 'ok' => true );
    }

    /** Проверка сохранённой модели тем же пробным запросом. */
    public static function check_model( $id ) {
        $model = self::models()[ $id ] ?? null;
        if ( ! $model ) {
            return self::error( 'Модель не найдена.', 404 );
        }
        $answer = self::chat( 'Ответь одним словом: готово', 30, $model );
        return is_wp_error( $answer ) ? $answer : array( 'answer' => mb_substr( $answer, 0, 60 ) );
    }

    /**
     * Один запрос к модели для текстов. $model — ID из списка или сама
     * запись модели (при проверке перед сохранением). Пустой ID — модель по умолчанию.
     */
    private static function chat( $input, $timeout = 90, $model = '' ) {
        if ( ! is_array( $model ) ) {
            $models = self::models();
            $id = is_string( $model ) && isset( $models[ $model ] ) ? $model : self::default_model();
            if ( '' === $id ) {
                return self::error( 'Не подключена ни одна модель для текстов. Добавьте её в «Настройках».', 400 );
            }
            $model = $models[ $id ];
        }
        $name = $model['title'];
        $method = 'llm.' . $model['preset'];
        $started = microtime( true );
        $response = wp_remote_post( $model['base'] . '/chat/completions', array(
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 2097152,
            'headers' => array( 'Authorization' => 'Bearer ' . $model['key'], 'Content-Type' => 'application/json' ),
            'body' => wp_json_encode( array( 'model' => $model['model'], 'messages' => array( array( 'role' => 'user', 'content' => $input ) ) ) ),
        ) );
        $duration = (int) round( ( microtime( true ) - $started ) * 1000 );
        if ( is_wp_error( $response ) ) {
            VKT_Store::log( $method, 'ai', 'error', 0, 'Нет связи: ' . $name, $duration );
            return self::error( 'Не удалось подключиться к ' . $name . ': ' . $response->get_error_message(), 502, true );
        }
        $http = (int) wp_remote_retrieve_response_code( $response );
        $raw = (string) wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );
        if ( $http < 200 || $http >= 300 || ! is_array( $data ) ) {
            $reason = self::reason( $data, $raw, $http, $model['key'] );
            VKT_Store::log( $method, 'ai', 'error', $http, mb_substr( $name . ': ' . $reason, 0, 250 ), $duration );
            return new WP_Error( 'vkt_ai', $name . ' отклонил запрос (HTTP ' . $http . '): ' . $reason, array(
                'status' => 422,
                'retryable' => 429 === $http || $http >= 500,
                'fix' => VKT_Account::is_admin() ? array( 'view' => 'settings', 'label' => 'Выбрать другую модель — «Настройки»' ) : null,
            ) );
        }
        $text = self::choice_text( $data );
        VKT_Store::log( $method, 'ai', 'ok', $http, 'Текст получен: ' . $name, $duration );
        return '' === $text ? self::error( $name . ' не вернул текст.', 502, true ) : $text;
    }

    /** Текст ответа chat/completions: строка или массив частей. */
    private static function choice_text( $data ) {
        $content = $data['choices'][0]['message']['content'] ?? '';
        if ( is_array( $content ) ) {
            $content = implode( "\n", array_map( static fn( $part ) => is_array( $part ) ? (string) ( $part['text'] ?? '' ) : (string) $part, $content ) );
        }
        return trim( (string) $content );
    }

    /**
     * Причина отказа словами. Поставщики кладут её по-разному: error строкой,
     * error.message, message. Если JSON нет вовсе — запрос завернули до API
     * (чаще всего по стране сервера), тогда показываем начало ответа.
     */
    private static function reason( $data, $raw, $http, $key ) {
        $message = '';
        if ( is_array( $data ) ) {
            $error = $data['error'] ?? null;
            $message = is_array( $error ) ? (string) ( $error['message'] ?? $error['code'] ?? '' ) : (string) ( $error ?? $data['message'] ?? '' );
            if ( is_array( $data[0] ?? null ) && '' === $message ) {
                $message = (string) ( $data[0]['error']['message'] ?? '' );
            }
        }
        if ( '' === trim( $message ) ) {
            $message = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
            $message = '' === $message ? 'пустой ответ' : 'ответ не от API: «' . mb_substr( $message, 0, 120 ) . '»';
            if ( in_array( $http, array( 403, 451 ), true ) ) {
                $message .= '. Похоже, поставщик не обслуживает страну сервера — выберите другую модель (DeepSeek или Qwen обычно доступны).';
            }
        }
        return str_replace( $key, '[hidden]', sanitize_text_field( $message ) );
    }

    public static function public_status() {
        return array(
            // Тексты пишет любая подключённая модель, картинки и видео — только xAI.
            'configured' => self::text_configured(),
            'media_configured' => self::configured(),
            'models' => self::text_models(),
            'default_model' => self::default_model(),
            'presets' => self::PRESETS,
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
            $message = self::reason( $data, (string) wp_remote_retrieve_body( $response ), $http, trim( (string) VKT_XAI_API_KEY ) );
            VKT_Store::log( 'xai.' . basename( $path ), 'ai', 'error', $http, mb_substr( 'xAI: ' . $message, 0, 250 ), $duration );
            return self::error( 'xAI отклонил запрос (HTTP ' . $http . '): ' . mb_substr( $message, 0, 220 ), in_array( $http, array( 401, 403 ), true ) ? 401 : 422, 429 === $http || $http >= 500 );
        }
        VKT_Store::log( 'xai.' . basename( $path ), 'ai', 'ok', $http, 'Генерация xAI выполнена', $duration );
        return $data;
    }

    /** Блок о группе в конце запроса: паспорт владельца и история постов. */
    private static function with_group( $input, $context ) {
        $context = is_string( $context ) ? trim( $context ) : '';
        return '' === $context ? $input : $input . "\n\nО сообществе, для которого пишешь:\n" . $context;
    }

    public static function generate_text( $prompt, $current = '', $model = '', $context = '' ) {
        $prompt = self::prompt( $prompt );
        if ( is_wp_error( $prompt ) ) {
            return $prompt;
        }
        $current = is_string( $current ) ? mb_substr( trim( wp_strip_all_tags( $current ) ), 0, 16000 ) : '';
        $input = "Подготовь готовый текст поста для сообщества VK на русском языке. Верни только финальный текст без пояснений, кавычек и Markdown-заголовков. Не выдумывай факты, цены, ссылки и обещания. Максимум 16000 символов.\n\nЗадача: " . $prompt;
        if ( '' !== $current ) {
            $input .= "\n\nТекущий черновик, который можно улучшить:\n" . $current;
        }
        $result = self::chat( self::with_group( $input, $context ), 90, $model );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $text = trim( wp_strip_all_tags( $result ) );
        return '' === $text ? self::error( 'Модель не вернула текст.', 502 ) : array( 'text' => mb_substr( $text, 0, 16000 ) );
    }

    /**
     * Серия текстов одним запросом. По одному было бы и дороже, и хуже: модель
     * не знает, о чём уже написала, и посты повторяли бы друг друга. Поэтому
     * просим сразу список и разбираем ответ.
     */
    public static function generate_series( $prompt, $count, $model = '', $context = '' ) {
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
        $result = self::chat( self::with_group( $input, $context ), 180, $model );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $posts = self::parse_series( $result );
        if ( ! $posts ) {
            return self::error( 'Модель ответила, но собрать из ответа список текстов не удалось. Повторите запрос или уточните тему.', 502, true );
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

    /**
     * Ответы на комментарии одним запросом. Каждый комментарий уходит со
     * своим номером и текстом записи: без записи модель не понимает, о чём
     * спрашивают, а по номерам ответ сверяется с комментарием, даже если
     * модель пропустит один или переставит их.
     */
    public static function generate_replies( $instruction, $items, $model = '', $context = '' ) {
        $instruction = is_string( $instruction ) ? trim( wp_strip_all_tags( $instruction ) ) : '';
        if ( mb_strlen( $instruction ) > 2000 ) {
            return self::error( 'Указания для ответов — не длиннее 2000 символов.' );
        }
        $list = array();
        foreach ( array_slice( is_array( $items ) ? array_values( $items ) : array(), 0, VKT_Replies::BATCH_CEILING ) as $index => $item ) {
            $comment = is_array( $item ) ? trim( wp_strip_all_tags( (string) ( $item['comment'] ?? '' ) ) ) : '';
            if ( '' === $comment ) {
                continue;
            }
            $list[] = array(
                'id' => $index + 1,
                'author' => mb_substr( sanitize_text_field( (string) ( $item['author'] ?? '' ) ), 0, 80 ),
                'post' => mb_substr( trim( wp_strip_all_tags( (string) ( $item['post'] ?? '' ) ) ), 0, 600 ),
                'comment' => mb_substr( $comment, 0, 1000 ),
            );
        }
        if ( ! $list ) {
            return self::error( 'Нет комментариев с текстом: отвечать не на что.' );
        }
        $input = 'Ты отвечаешь от имени сообщества VK на комментарии подписчиков. На каждый комментарий напиши отдельный ответ на русском языке:'
            . ' вежливо, по существу, коротко — одно-три предложения, без Markdown и хэштегов. Если имя автора известно, можно обратиться по имени.'
            . ' Не выдумывай факты, цены, сроки, ссылки и обещания; если ответить по существу нечем — поблагодари и предложи написать в сообщения сообщества.'
            . ' Верни строго JSON вида {"replies":[{"id":1,"text":"ответ"}]} — по одному элементу на каждый id, без пояснений.'
            . ( '' !== $instruction ? "\n\nУказания владельца сообщества: " . $instruction : '' )
            . "\n\nКомментарии (post — текст записи, под которой оставлен комментарий):\n" . wp_json_encode( $list, JSON_UNESCAPED_UNICODE );
        $result = self::chat( self::with_group( $input, $context ), 180, $model );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $replies = self::parse_replies( $result, count( $list ) );
        if ( ! $replies ) {
            return self::error( 'Модель ответила, но собрать из ответа тексты не удалось. Повторите запрос.', 502, true );
        }
        // Номер модели — позиция в присланном списке, а не в нашем: пустые комментарии мы пропускали.
        $out = array();
        foreach ( $list as $position => $entry ) {
            $out[] = array( 'index' => $entry['id'] - 1, 'text' => $replies[ $position + 1 ] ?? '' );
        }
        return array( 'replies' => $out );
    }

    /**
     * Кто умеет искать в интернете. Новый поставщик — запись здесь и ветка
     * в search(): остальной код знает только ID и название.
     * xAI ищет инструментом web_search, OpenRouter — плагином web поверх
     * любой своей модели (так в интернет ходит и DeepSeek), Qwen — своим
     * поиском по флагу enable_search. У прямого DeepSeek поиска нет: для него
     * и любой другой модели есть «поиск плагина» — запросы составляет модель
     * для текста, выдачу читает сам плагин (VKT_News::search_feed()).
     */
    const SEARCH_PRESETS = array( 'openrouter' => 'поиск OpenRouter', 'qwen' => 'поиск Qwen' );

    private static function search_registry() {
        $list = array();
        $models = self::models();
        if ( $models ) {
            // Первым, то есть по умолчанию: ему не нужен ключ поиска и он не зависит от страны сервера.
            $list['feed'] = array( 'title' => 'Поиск плагина · работает с любой моделью для текста (DeepSeek и другие)', 'kind' => 'feed' );
        }
        $xai = self::configured() ? trim( (string) VKT_XAI_API_KEY ) : '';
        foreach ( $models as $model ) {
            if ( '' === $xai && 'xai' === $model['preset'] ) {
                $xai = $model['key'];
            }
        }
        if ( '' !== $xai ) {
            $list['xai'] = array( 'title' => 'xAI Grok · web_search', 'kind' => 'xai', 'key' => $xai );
        }
        foreach ( $models as $id => $model ) {
            if ( isset( self::SEARCH_PRESETS[ $model['preset'] ] ) ) {
                $list[ $model['preset'] . '-' . $id ] = array( 'title' => $model['title'] . ' · ' . self::SEARCH_PRESETS[ $model['preset'] ], 'kind' => $model['preset'], 'model' => $model );
            }
        }
        return $list;
    }

    /** Список поставщиков поиска для интерфейса — без ключей. */
    public static function search_providers() {
        $out = array();
        foreach ( self::search_registry() as $id => $provider ) {
            $out[] = array( 'id' => $id, 'title' => $provider['title'] );
        }
        return $out;
    }

    /** Каким способом пойдёт поиск: выбранный поставщик, а если его уже нет — первый доступный. */
    public static function search_kind( $provider ) {
        $registry = self::search_registry();
        $id = is_string( $provider ) && isset( $registry[ $provider ] ) ? $provider : (string) ( array_key_first( $registry ) ?? '' );
        return '' === $id ? '' : $registry[ $id ]['kind'];
    }

    /**
     * Поисковые запросы по описанию выборки. Описание пишут для редактора
     * («только НБА, без слухов и ставок») — в строку поиска оно не годится.
     * Модель не ответила — ищем по самому описанию: это хуже, но не тупик.
     */
    public static function search_queries( $topic, $group_name = '', $model = '' ) {
        $topic = mb_substr( trim( (string) $topic ), 0, VKT_News::TOPIC_MAX );
        $fallback = array( mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $topic ) ), 0, 100 ) );
        $result = self::chat( 'Составь поисковые запросы для поиска свежих новостей в новостном поисковике. Какие новости нужны: ' . $topic
            . ( '' !== trim( (string) $group_name ) ? "\nДля сообщества VK «" . sanitize_text_field( (string) $group_name ) . '».' : '' )
            . "\n\nОт одного до трёх коротких запросов на русском, по 2–5 слов, как их набирают в поиске: только о чём искать, без исключений, кавычек и операторов. Разные запросы — разные стороны темы."
            . ' Верни строго JSON вида {"queries":["запрос"]} без пояснений.', 60, $model );
        if ( is_wp_error( $result ) ) {
            return $fallback;
        }
        $raw = trim( (string) $result );
        if ( false !== ( $from = strpos( $raw, '{' ) ) && false !== ( $to = strrpos( $raw, '}' ) ) && $to > $from ) {
            $raw = substr( $raw, $from, $to - $from + 1 );
        }
        $queries = array();
        foreach ( (array) ( json_decode( $raw, true )['queries'] ?? array() ) as $query ) {
            $query = is_string( $query ) ? mb_substr( trim( (string) preg_replace( '/[\s"()]+/u', ' ', wp_strip_all_tags( $query ) ) ), 0, 100 ) : '';
            if ( '' !== $query && ! isset( $queries[ mb_strtolower( $query ) ] ) ) {
                $queries[ mb_strtolower( $query ) ] = $query;
            }
        }
        return $queries ? array_slice( array_values( $queries ), 0, 3 ) : $fallback;
    }

    /**
     * Запрос с поиском в интернете. Возвращает текст ответа и адреса,
     * которые поставщик сам назвал источниками: по ним потом сверяются
     * ссылки из текста. $domains — где искать в первую очередь.
     */
    public static function search( $input, $domains = array(), $provider = '' ) {
        $registry = self::search_registry();
        $id = isset( $registry[ $provider ] ) ? $provider : (string) ( array_key_first( $registry ) ?? '' );
        if ( '' === $id ) {
            return new WP_Error( 'vkt_ai', 'Искать в интернете нечем: не подключена ни одна модель для текстов.', array(
                'status' => 400,
                'fix' => VKT_Account::is_admin() ? array( 'view' => 'settings', 'label' => 'Подключить модель — «Настройки»' ) : null,
            ) );
        }
        $entry = $registry[ $id ];
        if ( 'feed' === $entry['kind'] ) {
            return self::error( 'Поиск плагина идёт без модели с поиском — через VKT_News::search().', 400 );
        }
        $domains = array_slice( array_values( array_unique( array_filter( array_map( 'strval', (array) $domains ) ) ) ), 0, 5 );
        if ( 'xai' === $entry['kind'] ) {
            $tool = array( 'type' => 'web_search' );
            if ( $domains ) {
                $tool['filters'] = array( 'allowed_domains' => $domains );
            }
            $url = 'https://api.x.ai/v1/responses';
            $key = $entry['key'];
            $body = array( 'model' => self::SEARCH_MODEL, 'input' => array( array( 'role' => 'user', 'content' => $input ) ), 'tools' => array( $tool ) );
        } else {
            $url = $entry['model']['base'] . '/chat/completions';
            $key = $entry['model']['key'];
            $body = array( 'model' => $entry['model']['model'], 'messages' => array( array( 'role' => 'user', 'content' => $input ) ) );
            if ( 'qwen' === $entry['kind'] ) {
                // Фильтра по сайтам у поиска Qwen нет: сайты остаются пожеланием в тексте запроса.
                $body['enable_search'] = true;
                $body['search_options'] = array( 'forced_search' => true );
            } else {
                $plugin = array( 'id' => 'web', 'max_results' => 10 );
                if ( $domains ) {
                    $plugin['include_domains'] = $domains;
                }
                $body['plugins'] = array( $plugin );
            }
        }
        $started = microtime( true );
        $response = wp_remote_post( $url, array(
            // Поиск с чтением страниц идёт заметно дольше обычного ответа.
            'timeout' => 170,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 4194304,
            'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
            'body' => wp_json_encode( $body ),
        ) );
        $duration = (int) round( ( microtime( true ) - $started ) * 1000 );
        $method = 'search.' . $entry['kind'];
        if ( is_wp_error( $response ) ) {
            VKT_Store::log( $method, 'ai', 'error', 0, 'Нет связи: ' . $entry['title'], $duration );
            return self::error( 'Не удалось подключиться к ' . $entry['title'] . ': ' . $response->get_error_message(), 502, true );
        }
        $http = (int) wp_remote_retrieve_response_code( $response );
        $raw = (string) wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );
        if ( $http < 200 || $http >= 300 || ! is_array( $data ) ) {
            $reason = self::reason( $data, $raw, $http, $key );
            VKT_Store::log( $method, 'ai', 'error', $http, mb_substr( $entry['title'] . ': ' . $reason, 0, 250 ), $duration );
            // Модель для текста поиск не меняет — подсказываем, где он выбирается на самом деле.
            $hint = in_array( $http, array( 403, 451 ), true ) ? ' Модель для текста поиск не меняет: в настройках новостей группы выберите в поле «Кто ищет» «Поиск плагина» — он работает с любой моделью.' : '';
            return self::error( $entry['title'] . ' отклонил поиск (HTTP ' . $http . '): ' . $reason . $hint, 422, 429 === $http || $http >= 500 );
        }
        $text = '';
        $urls = array();
        if ( 'xai' === $entry['kind'] ) {
            foreach ( (array) ( $data['output'] ?? array() ) as $item ) {
                foreach ( (array) ( is_array( $item ) ? ( $item['content'] ?? array() ) : array() ) as $part ) {
                    if ( is_array( $part ) && 'output_text' === ( $part['type'] ?? '' ) ) {
                        $text .= (string) ( $part['text'] ?? '' );
                        foreach ( (array) ( $part['annotations'] ?? array() ) as $note ) {
                            $urls[] = (string) ( $note['url'] ?? '' );
                        }
                    }
                }
            }
            if ( '' === $text ) {
                $text = (string) ( $data['output_text'] ?? '' );
            }
            foreach ( (array) ( $data['citations'] ?? array() ) as $citation ) {
                $urls[] = is_array( $citation ) ? (string) ( $citation['url'] ?? '' ) : (string) $citation;
            }
        } else {
            $text = self::choice_text( $data );
            foreach ( (array) ( $data['choices'][0]['message']['annotations'] ?? array() ) as $note ) {
                $urls[] = (string) ( $note['url_citation']['url'] ?? $note['url'] ?? '' );
            }
        }
        VKT_Store::log( $method, 'ai', 'ok', $http, 'Поиск выполнен: ' . $entry['title'] . ', источников ' . count( array_filter( $urls ) ), $duration );
        return '' === trim( $text ) ? self::error( $entry['title'] . ' не вернул результат поиска.', 502, true ) : array( 'text' => trim( $text ), 'urls' => array_values( array_unique( array_filter( $urls ) ) ), 'provider' => $entry['title'] );
    }

    /**
     * Свежие новости по теме поиском в интернете: заголовок, суть и адрес
     * статьи. Писать посты здесь не просим — только найти и пересказать факты.
     */
    public static function search_news( $topic, $limit, $days, $domains = array(), $group_name = '', $engine = '' ) {
        $limit = max( 3, min( 30, (int) $limit ) );
        $input = 'Найди в интернете свежие новости за последние ' . max( 1, (int) $days ) . ' сут. Сегодня ' . wp_date( 'd.m.Y', time() ) . '. Обязательно выполни поиск, не отвечай по памяти.'
            . "\n\nКакие новости нужны: " . mb_substr( trim( (string) $topic ), 0, VKT_News::TOPIC_MAX )
            . ( '' !== trim( (string) $group_name ) ? "\nДля сообщества VK «" . sanitize_text_field( (string) $group_name ) . '».' : '' )
            . ( $domains ? "\nИщи в первую очередь на сайтах: " . implode( ', ', $domains ) . '.' : '' )
            . "\n\nВерни строго JSON без пояснений и Markdown: {\"news\":[{\"title\":\"заголовок на русском\",\"summary\":\"3–5 предложений с фактами из статьи: кто, что, когда, цифры\",\"url\":\"точный адрес страницы статьи, которую ты открыл\",\"date\":\"ГГГГ-ММ-ДД\"}]}."
            . ' До ' . $limit . ' разных новостей, самые важные первыми. Одно событие — одна запись. Адрес — только настоящей статьи из результатов поиска, не главной страницы сайта и не выдуманный. Не нашёл подходящего — верни {"news":[]}.';
        $found = self::search( $input, $domains, $engine );
        if ( is_wp_error( $found ) ) {
            return $found;
        }
        $raw = $found['text'];
        if ( preg_match( '/```(?:json)?\s*(.+?)```/s', $raw, $fenced ) ) {
            $raw = trim( $fenced[1] );
        }
        $decoded = json_decode( $raw, true );
        // Модель с поиском любит приписать пару слов вокруг JSON — достаём сам объект.
        if ( ! is_array( $decoded ) && false !== ( $from = strpos( $raw, '{' ) ) && false !== ( $to = strrpos( $raw, '}' ) ) && $to > $from ) {
            $decoded = json_decode( substr( $raw, $from, $to - $from + 1 ), true );
        }
        if ( ! is_array( $decoded ) ) {
            return self::error( 'Поиск ответил, но список новостей из ответа собрать не удалось. Повторите запрос.', 502, true );
        }
        $items = array();
        foreach ( (array) ( $decoded['news'] ?? ( isset( $decoded[0] ) ? $decoded : array() ) ) as $item ) {
            if ( is_array( $item ) ) {
                $items[] = array( 'title' => (string) ( $item['title'] ?? '' ), 'summary' => (string) ( $item['summary'] ?? '' ), 'url' => (string) ( $item['url'] ?? $item['link'] ?? '' ), 'date' => (string) ( $item['date'] ?? '' ) );
            }
        }
        return array( 'items' => array_slice( $items, 0, $limit ), 'urls' => $found['urls'], 'provider' => $found['provider'] );
    }

    /**
     * Товарный пост по методике VK Shops. Утверждать о товаре можно только
     * то, что вписал владелец; ссылку приписывает плагин, чтобы модель её
     * не исказила. Текущий текст записи, если он есть, служит основой.
     */
    public static function generate_shop_post( $brief, $model = '', $context = '' ) {
        $input = 'Напиши товарный пост для сообщества VK на русском языке по методике ниже. Верни только готовый текст поста — без пояснений, вариантов, кавычек и Markdown.'
            . "\n\nМетодика:\n" . VKT_Shops::rules()
            . "\n\nТовар: " . $brief['title'] . '.'
            . ( '' !== $brief['url'] ? ' Ссылка на товар добавится в конец поста автоматически — сам ссылку не пиши, но подведи к ней последней строкой.' : ' Ссылки на товар нет — не выдумывай её и не пиши «ссылка ниже».' )
            . "\n\nЧто известно по факту — утверждать о товаре, опыте и результате можно только это:\n" . ( '' !== $brief['facts'] ? $brief['facts'] : 'Ничего, кроме названия. Пиши описанием ситуации и товара, без личного опыта, оценок, цен и результатов.' )
            . "\n\nХук: " . ( '' !== $brief['hook'] ? $brief['hook'] : 'выбери сам подходящий к товару' ) . '. Подача: ' . ( '' !== $brief['format'] ? $brief['format'] : 'выбери сам подходящую' ) . '.';
        if ( '' !== $brief['current'] ) {
            $input .= "\n\nСейчас в этой записи такой текст. Возьми его тему и ситуацию за основу и преврати в товарный пост:\n" . $brief['current'];
        }
        $result = self::chat( self::with_group( $input, $context ), 120, $model );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $text = trim( wp_strip_all_tags( $result ) );
        if ( '' === $text ) {
            return self::error( 'Модель не вернула текст.', 502, true );
        }
        if ( '' !== $brief['url'] ) {
            // Модель могла всё же вписать адрес по-своему: оставляем один, настоящий.
            $text = trim( (string) preg_replace( '~\s*https?://\S+~iu', '', $text ) ) . "\n\n" . $brief['url'];
        }
        return array( 'text' => mb_substr( $text, 0, 16000 ) );
    }

    /**
     * Отбор новостей и тексты по ним. Модель видит только заголовок, анонс и
     * номер: ссылок у неё нет, источник к записи приписывает плагин. Номер
     * возвращается назад, по нему текст сверяется с новостью.
     */
    /**
     * Рерайт статей в посты. Не сочинение по теме: модель пересказывает
     * данный ей текст, поэтому факты остаются теми же, что в источнике.
     * Возвращает тексты по номерам статей.
     */
    public static function rewrite_news( $items, $model = '', $context = '' ) {
        $list = array();
        foreach ( array_values( (array) $items ) as $index => $item ) {
            $list[] = array( 'id' => $index + 1, 'text' => mb_substr( (string) $item['text'], 0, VKT_News::ARTICLE_MAX ) );
        }
        if ( ! $list ) {
            return self::error( 'Нет статей для рерайта.' );
        }
        $input = 'Ты редактор новостного сообщества VK. Ниже тексты статей. По каждой сделай рерайт для поста на русском языке: перескажи статью своими словами близко к тексту.'
            . ' Сохрани все факты, цифры, имена, даты и цитаты; ничего не добавляй от себя, не оценивай и не меняй смысл. Убери рекламу, призывы подписаться и ссылки на другие материалы.'
            . ' Первая строка — заголовок, дальше суть новости абзацами, до 1500 символов. Ссылки и слово «Источник» не пиши: источник добавится автоматически. Без Markdown.'
            . ' Верни строго JSON вида {"posts":[{"id":1,"text":"текст"}]} — id из списка, без пояснений.'
            . "\n\nСтатьи:\n" . wp_json_encode( $list, JSON_UNESCAPED_UNICODE );
        $result = self::chat( self::with_group( $input, $context ), 180, $model );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $raw = trim( (string) $result );
        if ( false !== ( $from = strpos( $raw, '{' ) ) && false !== ( $to = strrpos( $raw, '}' ) ) && $to > $from ) {
            $raw = substr( $raw, $from, $to - $from + 1 );
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return self::error( 'Модель ответила, но разобрать рерайт не удалось. Повторите запрос.', 502, true );
        }
        $texts = array();
        foreach ( (array) ( $decoded['posts'] ?? array() ) as $post ) {
            $id = is_array( $post ) ? (int) ( $post['id'] ?? 0 ) : 0;
            $text = is_array( $post ) ? trim( wp_strip_all_tags( (string) ( $post['text'] ?? '' ) ) ) : '';
            if ( $id >= 1 && $id <= count( $list ) && '' !== $text && ! isset( $texts[ $id ] ) ) {
                $texts[ $id ] = mb_substr( $text, 0, 4000 );
            }
        }
        return $texts;
    }

    public static function generate_news( $items, $topic, $count, $mode, $model = '', $context = '' ) {
        $list = array();
        foreach ( array_values( (array) $items ) as $index => $item ) {
            $list[] = array( 'id' => $index + 1, 'title' => (string) $item['title'], 'summary' => mb_substr( (string) $item['summary'], 0, 400 ), 'source' => (string) $item['source'], 'date' => null === $item['date'] ? '' : wp_date( 'd.m H:i', (int) $item['date'] ) );
        }
        if ( ! $list ) {
            return self::error( 'Нет новостей для отбора.' );
        }
        $count = max( 1, min( 30, (int) $count ) );
        $digest = 'digest' === $mode;
        $input = 'Ты редактор новостного сообщества VK. Ниже список свежих новостей из источников владельца. Отбери из него не больше ' . $count . ' новостей, которые подходят сообществу, и по каждой напиши '
            . ( $digest ? 'пункт дайджеста на русском языке: одно-три предложения с сутью.' : 'готовый пост на русском языке: до 1200 символов, с первой строкой-заголовком и сутью новости.' )
            . ' Пиши только то, что есть в заголовке и анонсе: не добавляй цифры, цитаты, имена, причины и подробности, которых там нет. Если анонс скуден — пост короткий.'
            . ' Одно событие из разных источников бери один раз. Подходящих меньше — верни меньше, не добирай неподходящими. Самое важное ставь первым.'
            . ' Ссылки и слово «Источник» не пиши: источник к каждой записи добавится автоматически. Без Markdown.'
            . ( $digest ? ' В intro — одна вводная строка дайджеста.' : '' )
            . ' Верни строго JSON вида {' . ( $digest ? '"intro":"вводная строка",' : '' ) . '"news":[{"id":1,"text":"текст"}]} — id из списка, без пояснений.'
            . "

Какие новости нужны сообществу: " . ( '' !== trim( (string) $topic ) ? mb_substr( trim( (string) $topic ), 0, VKT_News::TOPIC_MAX ) : 'владелец не уточнил — суди по сообществу и его паспорту.' )
            . "

Новости:
" . wp_json_encode( $list, JSON_UNESCAPED_UNICODE );
        $result = self::chat( self::with_group( $input, $context ), 180, $model );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $raw = trim( (string) $result );
        if ( preg_match( '/```(?:json)?\s*(.+?)```/s', $raw, $fenced ) ) {
            $raw = trim( $fenced[1] );
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return self::error( 'Модель ответила, но разобрать её отбор новостей не удалось. Повторите запрос.', 502, true );
        }
        $picks = array();
        foreach ( (array) ( $decoded['news'] ?? $decoded['posts'] ?? ( isset( $decoded[0] ) ? $decoded : array() ) ) as $pick ) {
            $id = is_array( $pick ) ? absint( $pick['id'] ?? 0 ) : 0;
            $text = is_array( $pick ) ? trim( wp_strip_all_tags( (string) ( $pick['text'] ?? '' ) ) ) : '';
            // Ссылку модель знать не может: всё похожее на адрес — выдумка, убираем.
            $text = trim( (string) preg_replace( '~\s*(?:Источник:\s*)?https?://\S+~iu', '', $text ) );
            if ( $id >= 1 && $id <= count( $list ) && '' !== $text && ! isset( $picks[ $id ] ) && count( $picks ) < $count ) {
                $picks[ $id ] = array( 'id' => $id, 'text' => mb_substr( $text, 0, 3000 ) );
            }
        }
        return array( 'intro' => mb_substr( trim( wp_strip_all_tags( (string) ( $decoded['intro'] ?? '' ) ) ), 0, 300 ), 'picks' => array_values( $picks ) );
    }

    /**
     * Черновик паспорта группы по её постам. Поля, которых из постов не
     * узнать (кто ведёт, за кем закреплена), модель оставляет пустыми —
     * выдуманный ответственный хуже пустой строки.
     */
    public static function draft_passport( $template, $current, $name, $digest, $model = '' ) {
        $facts = array(
            'posts' => $digest['posts'], 'per_week' => $digest['per_week'], 'avg_length' => $digest['avg_length'],
            'avg_views' => $digest['avg_views'], 'media_share_percent' => $digest['media_share'], 'link_share_percent' => $digest['link_share'],
            'best_hours' => array_column( $digest['best_hours'], 'key' ), 'best_days' => array_column( $digest['best_days'], 'label' ), 'hashtags' => $digest['hashtags'],
        );
        $samples = array_map( static fn( $post ) => $post['text'] . ( null !== $post['views'] ? ' [просмотры: ' . $post['views'] . ']' : '' ), array_merge( $digest['top'], $digest['recent'] ) );
        $input = 'Составь паспорт сообщества VK «' . sanitize_text_field( $name ) . '» по шаблону ниже. Опиши тему, аудиторию, рубрики, длину и визуал, тон, эмодзи и хештеги, призывы к действию — так, как это видно по постам и цифрам.'
            . ' Раздел «Кто ведёт» и всё, чего не видно из постов, оставь пустым. Сохрани заголовки шаблона. Верни только Markdown паспорта, без пояснений.'
            . "\n\nШаблон:\n" . $template
            . ( '' !== trim( (string) $current ) ? "\n\nТекущий паспорт — уже заполненное владельцем сохрани дословно:\n" . mb_substr( $current, 0, 8000 ) : '' )
            . "\n\nЦифры сборщика за " . $digest['days'] . " дней:\n" . wp_json_encode( $facts, JSON_UNESCAPED_UNICODE )
            . "\n\nПосты (лучшие по просмотрам и последние):\n- " . implode( "\n- ", array_slice( array_unique( $samples ), 0, 25 ) );
        $result = self::chat( $input, 120, $model );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $text = trim( preg_replace( '/^```(?:markdown|md)?\s*|```\s*$/m', '', (string) $result ) );
        return '' === $text ? self::error( 'Модель не вернула паспорт.', 502, true ) : array( 'passport' => mb_substr( wp_strip_all_tags( $text ), 0, VKT_Groups::PASSPORT_MAX ) );
    }

    /** Ответ модели в карту «номер → текст». Если номеров нет, опираемся на порядок. */
    private static function parse_replies( $raw, $count ) {
        $raw = trim( (string) $raw );
        if ( preg_match( '/```(?:json)?\s*(.+?)```/s', $raw, $fenced ) ) {
            $raw = trim( $fenced[1] );
        }
        $decoded = json_decode( $raw, true );
        $items = is_array( $decoded ) ? ( isset( $decoded['replies'] ) && is_array( $decoded['replies'] ) ? $decoded['replies'] : $decoded ) : array();
        $map = array();
        foreach ( array_values( $items ) as $position => $item ) {
            $id = is_array( $item ) && isset( $item['id'] ) ? absint( $item['id'] ) : $position + 1;
            $text = is_array( $item ) ? (string) ( $item['text'] ?? $item['reply'] ?? '' ) : ( is_string( $item ) ? $item : '' );
            $text = mb_substr( trim( wp_strip_all_tags( $text ) ), 0, VKT_Replies::MAX_LENGTH );
            if ( $id >= 1 && $id <= $count && '' !== $text && ! isset( $map[ $id ] ) ) {
                $map[ $id ] = $text;
            }
        }
        return $map;
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

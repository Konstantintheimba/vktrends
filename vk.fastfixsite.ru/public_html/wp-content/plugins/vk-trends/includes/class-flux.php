<?php
defined( 'ABSPATH' ) || exit;

/**
 * Стенд генерации изображений через API Black Forest Labs (FLUX).
 *
 * Зачем отдельно от VKT_AI: xAI пишет тексты и делает картинки «с нуля», а
 * здесь нужна правка своей фотографии по образцу товара — это другой запрос,
 * другой ключ и, главное, другая модерация.
 *
 * Что показал живой прогон 20.09.2026 (ключ из кабинета BFL, FLUX.2 [pro]):
 *
 * - запрос на замену купальника **не режется на входе**: приходит статус
 *   `Content Moderated` с причиной «Sexual Content», то есть модель картинку
 *   нарисовала, а фильтр не отдал результат;
 * - `safety_tolerance` до 5 включительно этого не меняет;
 * - тот же раздельный купальник, нарисованный **текстом без исходного фото**,
 *   проходит и на строгости по умолчанию;
 * - правка одежды на фото проходит, пока купальник остаётся слитным.
 *
 * Отсюда правило: у BFL строгий фильтр именно на пути «правка фотографии
 * человека → открытый купальник». Стенд поэтому показывает статус дословно,
 * вместе с причинами модерации, — чтобы было видно, что именно отказало.
 */
final class VKT_Flux {
    const HOST = 'https://api.bfl.ai';
    const SLOT = 'bfl';
    // Сколько образцов принимает форма. API берёт до восьми, но стенду хватает.
    const MAX_REFERENCES = 4;
    const MAX_BYTES = 8388608;

    /** Модели изображений. Видео у FLUX 3 — отдельная история и здесь не нужно. */
    public static function models() {
        return array(
            'flux-2-pro' => array( 'title' => 'FLUX.2 [pro]', 'hint' => '$0,03 за мегапиксель. Рабочая лошадка: правки и генерация.' ),
            'flux-2-max' => array( 'title' => 'FLUX.2 [max]', 'hint' => '$0,07 за мегапиксель. Самое высокое качество и точность деталей.' ),
            'flux-2-flex' => array( 'title' => 'FLUX.2 [flex]', 'hint' => '$0,06 за мегапиксель. Управляемая версия pro.' ),
            'flux-2-klein-9b' => array( 'title' => 'FLUX.2 [klein] 9B', 'hint' => 'Дешёвая и быстрая. Те же веса открыты под некоммерческой лицензией.' ),
            'flux-2-klein-4b' => array( 'title' => 'FLUX.2 [klein] 4B', 'hint' => 'Самая дешёвая. Веса открыты под Apache 2.0 — эту модель можно поставить к себе.' ),
        );
    }

    private static function error( $message, $status = 400, $extra = array() ) {
        return new WP_Error( 'vkt_flux', $message, array_merge( array( 'status' => $status ), $extra ) );
    }

    public static function configured() {
        return VKT_Tokens::has( self::SLOT );
    }

    public static function public_status() {
        return array(
            'configured' => self::configured(),
            'models' => self::models(),
            'max_references' => self::MAX_REFERENCES,
            'max_tolerance' => 5,
        );
    }

    /** Ключ наружу не уходит: заголовок ставится здесь и только здесь. */
    private static function request( $method, $url, $payload = null, $timeout = 60 ) {
        if ( ! self::configured() ) {
            return self::error( 'Ключ BFL не сохранён. Вставьте его в карточке «Ключ BFL» на этой странице.' );
        }
        $args = array(
            'method' => $method,
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 4194304,
            'headers' => array( 'x-key' => VKT_Tokens::token( self::SLOT ), 'Content-Type' => 'application/json', 'accept' => 'application/json' ),
        );
        if ( null !== $payload ) {
            $args['body'] = wp_json_encode( $payload );
        }
        $started = microtime( true );
        $response = wp_remote_request( $url, $args );
        $ms = (int) round( ( microtime( true ) - $started ) * 1000 );
        if ( is_wp_error( $response ) ) {
            VKT_Store::log( 'bfl.' . basename( wp_parse_url( $url, PHP_URL_PATH ) ), 'ai', 'error', 0, 'Нет связи с api.bfl.ai', $ms );
            return self::error( 'Не удалось связаться с api.bfl.ai.', 502, array( 'retryable' => true ) );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $code || ! is_array( $body ) ) {
            $detail = is_array( $body ) ? sanitize_text_field( (string) ( $body['detail'][0]['msg'] ?? $body['detail'] ?? $body['message'] ?? '' ) ) : '';
            VKT_Store::log( 'bfl.' . basename( wp_parse_url( $url, PHP_URL_PATH ) ), 'ai', 'error', $code, 'BFL отклонил запрос', $ms );
            return self::error( self::http_message( $code ) . ( '' !== $detail ? ' ' . mb_substr( $detail, 0, 180 ) : '' ), 402 === $code ? 402 : 422 );
        }
        return $body;
    }

    private static function http_message( $code ) {
        $messages = array(
            400 => 'BFL: неверный формат запроса.',
            402 => 'BFL: закончились кредиты или не обновлён способ оплаты.',
            403 => 'BFL: ключ не имеет доступа к этой модели.',
            404 => 'BFL: такого метода нет — возможно, модель переименовали.',
            422 => 'BFL: неверные параметры запроса.',
            429 => 'BFL: слишком много одновременных задач. Дождитесь окончания предыдущих.',
            500 => 'BFL: внутренняя ошибка сервиса.',
            503 => 'BFL: сервис временно недоступен.',
        );
        return $messages[ $code ] ?? 'BFL вернул ошибку ' . $code . '.';
    }

    /** Баланс кредитов: заодно самая дешёвая проверка ключа — она бесплатна. */
    public static function credits() {
        $body = self::request( 'GET', self::HOST . '/v1/credits', null, 20 );
        if ( is_wp_error( $body ) ) {
            VKT_Tokens::note( self::SLOT, $body->get_error_message() );
            return $body;
        }
        VKT_Tokens::note( self::SLOT, '' );
        return array( 'credits' => round( (float) ( $body['credits'] ?? 0 ), 2 ) );
    }

    /** Проверка слота в общем формате отчёта по ключам. */
    public static function probe() {
        $credits = self::credits();
        if ( is_wp_error( $credits ) ) {
            return array(
                'slot' => self::SLOT,
                'title' => 'Ключ BFL',
                'ok' => false,
                'checks' => array( array( 'method' => 'v1/credits', 'label' => 'Баланс кредитов', 'ok' => false, 'code' => 0, 'message' => $credits->get_error_message() ) ),
            );
        }
        return array(
            'slot' => self::SLOT,
            'title' => 'Ключ BFL',
            'ok' => true,
            'checks' => array( array( 'method' => 'v1/credits', 'label' => 'Баланс кредитов', 'ok' => true, 'code' => 0, 'message' => 'Ключ принят, на счету ' . $credits['credits'] . ' кредита.' ) ),
        );
    }

    /** Картинки уходят в base64: полагаться на публичный адрес файла сайта не нужно. */
    private static function encode( $attachment_id ) {
        $item = VKT_Media::item( $attachment_id );
        if ( is_wp_error( $item ) ) {
            return $item;
        }
        if ( 'image' !== $item['type'] ) {
            return self::error( 'Образцом может быть только изображение, не видео.' );
        }
        if ( ! VKT_Media::owned( $attachment_id ) ) {
            return self::error( 'Файл не найден в вашей медиатеке.', 404 );
        }
        if ( $item['size'] > self::MAX_BYTES ) {
            return self::error( 'Файл «' . $item['name'] . '» больше 8 МБ — уменьшите его перед отправкой.' );
        }
        $raw = file_get_contents( $item['path'] );
        if ( false === $raw ) {
            return self::error( 'Не удалось прочитать файл ' . $item['name'] . '.', 500 );
        }
        return base64_encode( $raw );
    }

    private static function dimension( $value ) {
        $value = absint( $value );
        if ( ! $value ) {
            return 0;
        }
        // Сторона кратна 32 и не выходит за разумные пределы: иначе BFL ответит 422.
        return max( 64, min( 2048, (int) ( round( $value / 32 ) * 32 ) ) );
    }

    /**
     * Ставит задачу генерации. Возвращает ID, по которому стенд спрашивает статус.
     * Адрес опроса BFL отдаёт свой (у него несколько регионов) — храним его.
     */
    public static function start( $data ) {
        $model = is_string( $data['model'] ?? null ) ? $data['model'] : '';
        if ( ! isset( self::models()[ $model ] ) ) {
            return self::error( 'Выберите модель из списка.' );
        }
        $prompt = is_string( $data['prompt'] ?? null ) ? trim( wp_strip_all_tags( $data['prompt'] ) ) : '';
        if ( mb_strlen( $prompt ) < 3 || mb_strlen( $prompt ) > 5000 ) {
            return self::error( 'Опишите задачу — от 3 до 5000 символов.' );
        }
        $payload = array(
            'prompt' => $prompt,
            'output_format' => in_array( $data['output_format'] ?? '', array( 'png', 'webp' ), true ) ? $data['output_format'] : 'jpeg',
            // 0 — строже всего, 5 — мягче всего. Выше пяти BFL отвечает 422.
            'safety_tolerance' => max( 0, min( 5, absint( $data['safety_tolerance'] ?? 2 ) ) ),
        );
        if ( ! empty( $data['seed'] ) ) {
            $payload['seed'] = absint( $data['seed'] );
        }
        if ( ! empty( $data['disable_pup'] ) ) {
            // Автоматическое расширение промпта дописывает свои формулировки.
            $payload['disable_pup'] = true;
        }
        foreach ( array( 'width', 'height' ) as $side ) {
            $size = self::dimension( $data[ $side ] ?? 0 );
            if ( $size ) {
                $payload[ $side ] = $size;
            }
        }
        $images = array_slice( array_values( array_filter( array_map( 'absint', (array) ( $data['images'] ?? array() ) ) ) ), 0, self::MAX_REFERENCES );
        foreach ( $images as $index => $attachment_id ) {
            $encoded = self::encode( $attachment_id );
            if ( is_wp_error( $encoded ) ) {
                return $encoded;
            }
            $payload[ 0 === $index ? 'input_image' : 'input_image_' . ( $index + 1 ) ] = $encoded;
        }
        $body = self::request( 'POST', self::HOST . '/v1/' . $model, $payload, 120 );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $id = sanitize_text_field( (string) ( $body['id'] ?? '' ) );
        $polling = esc_url_raw( (string) ( $body['polling_url'] ?? '' ), array( 'https' ) );
        if ( '' === $id || '' === $polling ) {
            return self::error( 'BFL не вернул задачу генерации.', 502 );
        }
        set_transient( self::key( $id ), array(
            'polling_url' => $polling,
            'user' => VKT_Account::id(),
            'model' => $model,
            'images' => count( $images ),
            'started' => time(),
        ), HOUR_IN_SECONDS );
        VKT_Store::log( 'bfl.' . $model, 'ai', 'ok', 0, 'Задача генерации поставлена', 0 );
        return array( 'id' => $id, 'status' => 'pending', 'model' => $model );
    }

    private static function key( $id ) {
        return 'vkt_flux_' . hash( 'sha256', (string) $id );
    }

    /**
     * Статус задачи. Готовый файл забираем сразу: ссылка BFL живёт 10 минут,
     * а её адрес наружу не отдаём — в медиатеку кладём сами.
     */
    public static function status( $id ) {
        $id = is_string( $id ) ? trim( $id ) : '';
        if ( '' === $id || ! preg_match( '/^[a-zA-Z0-9._\-]{8,128}$/', $id ) ) {
            return self::error( 'Неверный ID задачи.' );
        }
        $task = get_transient( self::key( $id ) );
        if ( ! is_array( $task ) || absint( $task['user'] ) !== VKT_Account::id() ) {
            return self::error( 'Задача не найдена.', 404 );
        }
        $url = add_query_arg( 'id', rawurlencode( $id ), $task['polling_url'] );
        $body = self::request( 'GET', $url, null, 30 );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $status = (string) ( $body['status'] ?? '' );
        if ( in_array( $status, array( 'Pending', 'Reasoning', 'Generating' ), true ) ) {
            return array( 'status' => 'pending', 'stage' => $status, 'progress' => self::progress( $body['progress'] ?? null ) );
        }
        if ( 'Ready' === $status ) {
            $sample = esc_url_raw( (string) ( $body['result']['sample'] ?? '' ), array( 'https' ) );
            $media = VKT_Media::sideload( $sample, 'image' );
            if ( is_wp_error( $media ) ) {
                return $media;
            }
            delete_transient( self::key( $id ) );
            VKT_Store::log( 'bfl.' . $task['model'], 'ai', 'ok', 0, 'Изображение получено и сохранено в медиатеку', ( time() - (int) $task['started'] ) * 1000 );
            return array( 'status' => 'done', 'media' => $media, 'cost' => isset( $body['cost'] ) ? round( (float) $body['cost'], 3 ) : null );
        }
        delete_transient( self::key( $id ) );
        return self::moderation( $status, $body, $task );
    }

    /** Готовность BFL шлёт то долей, то процентами: приводим к целым процентам. */
    private static function progress( $value ) {
        if ( ! is_numeric( $value ) ) {
            return null;
        }
        $value = (float) $value;
        return (int) max( 0, min( 100, round( $value <= 1 ? $value * 100 : $value ) ) );
    }

    /** Отказ словами: по статусу видно, что именно отклонили — запрос или картинку. */
    private static function moderation( $status, $body, $task ) {
        $reasons = array();
        foreach ( (array) ( $body['details']['Moderation Reasons'] ?? array() ) as $reason ) {
            $reasons[] = sanitize_text_field( (string) $reason );
        }
        $tail = $reasons ? ' Причины BFL: ' . implode( ', ', $reasons ) . '.' : '';
        VKT_Store::log( 'bfl.' . $task['model'], 'ai', 'error', 0, 'BFL: ' . $status . ( $reasons ? ' (' . implode( ',', $reasons ) . ')' : '' ), 0 );
        if ( 'Request Moderated' === $status ) {
            return self::error( 'BFL отклонил сам запрос: промпт или исходная фотография не прошли проверку на входе, генерации не было.' . $tail, 422, array( 'moderation' => 'request', 'reasons' => $reasons ) );
        }
        if ( 'Content Moderated' === $status ) {
            return self::error( 'BFL сгенерировал изображение, но не отдал его: результат не прошёл проверку на выходе. Строгость здесь уже не помогает — это ограничение самого сервиса на правку фотографий людей.' . $tail, 422, array( 'moderation' => 'content', 'reasons' => $reasons ) );
        }
        if ( 'Task not found' === $status ) {
            return self::error( 'BFL потерял задачу: попробуйте отправить заново.', 404 );
        }
        return self::error( 'BFL вернул статус «' . sanitize_text_field( $status ) . '».' . $tail, 502 );
    }
}

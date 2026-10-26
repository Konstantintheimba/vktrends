<?php
defined( 'ABSPATH' ) || exit;

/**
 * Картинки через Wiro (api.wiro.ai) — агрегатор моделей с одним ключом.
 *
 * Берём оттуда модели без фильтра на выходе: у BFL и OpenAI отказ приходит
 * уже на открытом купальнике. Подключение — ключ и секрет проекта Wiro в
 * «Нейросетях»; модели выбираются в «Чем рисуем» через реестр VKT_Images.
 *
 * Как устроен API (документация wiro.ai/docs, 26.10.2026):
 * - POST /Run/{владелец}/{модель} с JSON — в ответ taskid;
 * - POST /Task/Detail с taskid — статус и ссылки на файлы в outputs;
 * - задача закончена на task_postprocess_end (успех — pexit "0" и непустые
 *   outputs) или task_cancel; task_end и task_error концом не считаются;
 * - проект в режиме подписи требует x-nonce и x-signature =
 *   HMAC-SHA256(ключ = API-ключ, сообщение = секрет + nonce); в режиме
 *   «только ключ» хватает x-api-key, поэтому секрет необязателен.
 */
final class VKT_Wiro {
    const HOST = 'https://api.wiro.ai/v1';
    const OPTION = 'vkt_wiro';
    const RATIOS = array( 'portrait' => '3:4', 'square' => '1:1', 'landscape' => '16:9', 'story' => '9:16' );
    // Параметры моделей сняты с Tool/Detail. refs — сколько образцов уходит в модель.
    // Seedream берёт до 14; нам нужны четыре ракурса лица из карточки персонажа.
    // Несколько образцов Wiro принимает только multipart — повторяющимся полем (docs/model-parameters).
    const MODELS = array(
        'z-image-pro' => array( 'title' => 'Z Image Pro Uncensored', 'slug' => 'wiro-partners/z-image-pro-uncensored', 'refs' => 0, 'seed' => true, 'hint' => '$0,02 за картинку, 2K, только по тексту' ),
        // Seed у Seedream в Tool/Detail нет — не отправляем.
        'seedream-4-5' => array( 'title' => 'Seedream 4.5 Uncensored', 'slug' => 'bytedance/seedream-v4-5-uncensored', 'refs' => 4, 'seed' => false, 'hint' => '$0,04 за картинку, 2K, берёт до 4 фото персонажа образцами' ),
        // Поля те же, что у 4.5 (Tool/Detail 27.10.2026); разрешения 2k/3k, пропорции — тот же набор.
        'seedream-5-lite' => array( 'title' => 'Seedream 5 Lite Uncensored', 'slug' => 'bytedance/seedream-v5-lite-uncensored', 'refs' => 4, 'seed' => false, 'hint' => '$0,035 за картинку, 2K, берёт до 4 фото персонажа образцами' ),
        // Pro берёт до 10 образцов — хватает на лицо и вещи мира персонажа, как у FLUX.2. Поля другие: см. payload().
        'seedream-5-pro' => array( 'title' => 'Seedream 5 Pro Uncensored', 'slug' => 'bytedance/seedream-v5-pro-uncensored', 'refs' => 8, 'seed' => false, 'hint' => '$0,09 за картинку, 2K, берёт до 8 образцов: лицо и вещи персонажа' ),
    );
    // На этих стадиях задача ещё ждёт машину, дальше — рисует.
    const QUEUED = array( 'task_queue', 'task_accept', 'task_preprocess_start', 'task_preprocess_end', 'task_assign' );

    private static function error( $message, $status = 400, $extra = array() ) {
        return new WP_Error( 'vkt_wiro', $message, array_merge( array( 'status' => $status ), $extra ) );
    }

    private static function stored() {
        $saved = get_option( self::OPTION, array() );
        return is_array( $saved ) ? $saved : array();
    }

    /** Ключ и секрет в открытом виде — только для заголовков запроса. */
    private static function credentials() {
        $saved = self::stored();
        return array(
            'key' => VKT_Tokens::unseal( (string) ( $saved['key'] ?? '' ) ),
            'secret' => VKT_Tokens::unseal( (string) ( $saved['secret'] ?? '' ) ),
        );
    }

    public static function configured() {
        return '' !== self::credentials()['key'];
    }

    public static function models() {
        return array_map( static fn( $model ) => $model['title'], self::MODELS );
    }

    public static function refs( $model ) {
        return (int) ( self::MODELS[ $model ]['refs'] ?? 0 );
    }

    /** Карточка в «Нейросетях». Огрызок ключа — только администратору. */
    public static function public_status() {
        $saved = self::stored();
        $credentials = self::credentials();
        $admin = VKT_Account::is_admin();
        return array(
            'configured' => '' !== $credentials['key'],
            'preview' => $admin && '' !== $credentials['key'] ? mb_substr( $credentials['key'], 0, 6 ) . '…' . mb_substr( $credentials['key'], -4 ) : '',
            'signed' => '' !== $credentials['secret'],
            'saved_at' => $admin ? (string) ( $saved['saved_at'] ?? '' ) : '',
            'probe' => $admin && is_array( $saved['probe'] ?? null ) ? $saved['probe'] : null,
            'models' => array_map( static fn( $model ) => array( 'title' => $model['title'], 'hint' => $model['hint'], 'refs' => $model['refs'], 'seed' => $model['seed'] ), self::MODELS ),
        );
    }

    /** Заголовки авторизации. С секретом — подпись: проект Wiro по умолчанию её и ждёт. */
    public static function headers( $key, $secret, $nonce = null ) {
        $headers = array( 'x-api-key' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
        if ( '' !== $secret ) {
            $nonce = (string) ( $nonce ?? time() );
            $headers['x-nonce'] = $nonce;
            $headers['x-signature'] = hash_hmac( 'sha256', $secret . $nonce, $key );
        }
        return $headers;
    }

    /** $files — образцы: с ними запрос уходит multipart, каждый файл — тем же полем inputImage. */
    private static function request( $path, $payload, $timeout = 60, $credentials = null, $files = array() ) {
        $credentials = $credentials ?? self::credentials();
        if ( '' === $credentials['key'] ) {
            return self::error( 'Ключ Wiro не сохранён. Вставьте его в «Нейросетях», карточка Wiro.' );
        }
        $headers = self::headers( $credentials['key'], $credentials['secret'] );
        $body = wp_json_encode( $payload );
        if ( $files ) {
            $boundary = 'vkt' . bin2hex( random_bytes( 12 ) );
            $body = '';
            foreach ( $payload as $name => $value ) {
                $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
            }
            foreach ( $files as $file ) {
                $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$file['field']}\"; filename=\"" . str_replace( '"', '', $file['name'] ) . "\"\r\nContent-Type: {$file['mime']}\r\n\r\n{$file['bytes']}\r\n";
            }
            $body .= "--$boundary--\r\n";
            $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
        }
        $started = microtime( true );
        $response = wp_remote_request( self::HOST . $path, array(
            'method' => 'POST',
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 4194304,
            'headers' => $headers,
            'body' => $body,
        ) );
        $ms = (int) round( ( microtime( true ) - $started ) * 1000 );
        $method = 'wiro.' . strtolower( trim( str_replace( '/', '.', $path ), '.' ) );
        $shown = self::shown( $payload, $files );
        if ( is_wp_error( $response ) ) {
            VKT_Store::log( $method, 'ai', 'error', 0, 'Нет связи с api.wiro.ai', $ms, array( 'request' => $shown, 'response' => $response->get_error_message() ) );
            return self::error( 'Не удалось связаться с api.wiro.ai. Дорогу с сервера покажет «Проверить связь» в «Нейросетях».', 502, array( 'retryable' => true ) );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $reason = is_array( $body ) ? self::reason( $body ) : '';
        if ( 200 !== $code || ! is_array( $body ) ) {
            VKT_Store::log( $method, 'ai', 'error', $code, 'Wiro отклонил запрос', $ms, array( 'request' => $shown, 'response' => is_array( $body ) ? $body : (string) wp_remote_retrieve_body( $response ) ) );
            return self::error( self::http_message( $code, '' !== $credentials['secret'] ) . ( '' !== $reason ? ' Ответ Wiro: ' . $reason : '' ), in_array( $code, array( 401, 403, 429 ), true ) ? $code : 422, array( 'http' => $code ) );
        }
        // Число задач одновременно Wiro привязывает к балансу (на $20 — одна). Это очередь,
        // а не сбой: ответ помечен busy, интерфейс ждёт и ставит снова. В журнал не пишем —
        // в тексте есть «balance», и VKT_Notify принял бы его за кончившиеся деньги.
        if ( preg_match( '/concurrent task limit/i', $reason ) ) {
            $slots = preg_match( '/up to (\d+) task/i', $reason, $found ) ? (int) $found[1] : 1;
            return self::error( 'Wiro занят: на текущем балансе одновременно идёт задач — ' . $slots . '. Ждём, пока закончится предыдущая.', 409, array( 'busy' => true, 'slots' => $slots ) );
        }
        // Wiro отвечает 200 и с отказом: признак — result=false и текст в errors.
        if ( false === ( $body['result'] ?? null ) ) {
            VKT_Store::log( $method, 'ai', 'error', $code, 'Wiro: ' . mb_substr( $reason, 0, 120 ), $ms, array( 'request' => $shown, 'response' => $body ) );
            return self::error( 'Wiro отказал' . ( '' !== $reason ? ': ' . $reason : '.' ), 422, array( 'http' => $code ) );
        }
        return $body;
    }

    /** Тело запроса для журнала: поля как есть, образцы — именами файлов, без байтов. */
    private static function shown( $payload, $files = array() ) {
        return array_merge( (array) $payload, $files ? array( 'inputImage' => array_map( static fn( $file ) => $file['name'] . ' (' . strlen( $file['bytes'] ) . ' байт)', $files ) ) : array() );
    }

    /** Текст отказа: errors бывает списком строк или объектов с message. */
    private static function reason( $body ) {
        $parts = array();
        foreach ( (array) ( $body['errors'] ?? array() ) as $error ) {
            $parts[] = is_array( $error ) ? (string) ( $error['message'] ?? $error['msg'] ?? '' ) : (string) $error;
        }
        return mb_substr( sanitize_text_field( implode( '; ', array_filter( $parts ) ) ), 0, 240 );
    }

    private static function http_message( $code, $signed ) {
        $messages = array(
            400 => 'Wiro: неверные параметры запроса.',
            401 => 'Wiro не принял ключ API — вставьте его заново из настроек проекта.',
            // 403 — подпись: либо секрет не того проекта, либо проект ждёт подпись, а секрета нет.
            403 => $signed ? 'Wiro не принял подпись: секрет не от этого ключа. Скопируйте пару ключ + секрет из одного проекта.' : 'Проект Wiro требует подпись запросов: добавьте секрет API рядом с ключом.',
            429 => 'Wiro: слишком много запросов. Подождите и повторите.',
            500 => 'Wiro: внутренняя ошибка сервиса.',
            503 => 'Wiro временно недоступен.',
        );
        return $messages[ $code ] ?? 'Wiro вернул ошибку ' . $code . '.';
    }

    /**
     * Бесплатная проверка пары ключ + секрет: спрашиваем несуществующую задачу.
     * Отказ в доступе приходит кодом 401/403, а «задачи нет» означает, что
     * ключ и подпись приняты.
     */
    private static function probe( $credentials ) {
        $body = self::request( '/Task/Detail', array( 'taskid' => '0' ), 20, $credentials );
        $at = gmdate( 'Y-m-d H:i:s' );
        if ( is_wp_error( $body ) ) {
            $http = (int) ( $body->get_error_data()['http'] ?? 0 );
            // Отказ в доступе Wiro может прислать и с кодом 200 — тогда его выдаёт текст.
            if ( in_array( $http, array( 0, 401, 403, 429 ), true ) || $http >= 500 || preg_match( '/unauthori|forbidden|signature|api.?key|nonce/i', $body->get_error_message() ) ) {
                return array( 'ok' => false, 'message' => $body->get_error_message(), 'at' => $at );
            }
        }
        return array( 'ok' => true, 'message' => 'Ключ' . ( '' !== $credentials['secret'] ? ' и подпись приняты' : ' принят' ) . '.', 'at' => $at );
    }

    public static function save( $data ) {
        $key = trim( (string) ( $data['key'] ?? '' ) );
        $secret = trim( (string) ( $data['secret'] ?? '' ) );
        $saved = self::stored();
        if ( ! preg_match( '/^[!-~]{8,512}$/', $key ) ) {
            return self::error( 'Вставьте ключ API проекта Wiro целиком.' );
        }
        if ( '' !== $secret && ! preg_match( '/^[!-~]{8,512}$/', $secret ) ) {
            return self::error( 'Секрет API вставлен не целиком или с пробелами.' );
        }
        // Пустой секрет при замене ключа — «без подписи», а не «оставить прежний»: ключ и секрет всегда от одного проекта.
        $probe = self::probe( array( 'key' => $key, 'secret' => $secret ) );
        if ( ! $probe['ok'] ) {
            return self::error( $probe['message'] );
        }
        $sealed_key = VKT_Tokens::seal( $key );
        $sealed_secret = '' === $secret ? '' : VKT_Tokens::seal( $secret );
        foreach ( array( $sealed_key, $sealed_secret ) as $sealed ) {
            if ( is_wp_error( $sealed ) ) {
                return $sealed;
            }
        }
        update_option( self::OPTION, array_merge( $saved, array( 'key' => $sealed_key, 'secret' => $sealed_secret, 'saved_at' => gmdate( 'Y-m-d H:i:s' ), 'probe' => $probe ) ), false );
        return array( 'ok' => true, 'probe' => $probe );
    }

    public static function check() {
        $credentials = self::credentials();
        if ( '' === $credentials['key'] ) {
            return self::error( 'Ключ Wiro не сохранён.', 404 );
        }
        $probe = self::probe( $credentials );
        update_option( self::OPTION, array_merge( self::stored(), array( 'probe' => $probe ) ), false );
        return array( 'ok' => $probe['ok'], 'probe' => $probe );
    }

    public static function forget() {
        delete_option( self::OPTION );
        return array( 'ok' => true );
    }

    /**
     * Образец файлом, а не адресом: Wiro не пришлось бы ходить на сайт, и
     * адрес вроде локального или закрытого не сломает запрос. Берём уменьшенную
     * копию (до 1536 px), лицу этого хватает.
     */
    private static function reference_file( $attachment_id ) {
        $item = VKT_Media::reference_path( $attachment_id );
        if ( is_wp_error( $item ) ) {
            return $item;
        }
        if ( 'image' !== $item['type'] ) {
            return self::error( 'Образцом может быть только изображение, не видео.' );
        }
        if ( ! VKT_Media::owned( $attachment_id ) ) {
            return self::error( 'Файл не найден в вашей медиатеке.', 404 );
        }
        if ( $item['size'] > 10485760 ) {
            return self::error( 'Образец «' . $item['name'] . '» больше 10 МБ — Wiro такой не примет.' );
        }
        $bytes = file_get_contents( $item['path'] );
        if ( false === $bytes ) {
            return self::error( 'Не удалось прочитать образец ' . $item['name'] . '.', 500 );
        }
        return array( 'field' => 'inputImage', 'name' => $item['name'], 'mime' => (string) ( $item['mime'] ?? 'image/jpeg' ), 'bytes' => $bytes );
    }

    /** Тело запроса модели: поля у каждой свои, сняты с Tool/Detail. */
    public static function payload( $model, $prompt, $ratio, $seed = 0 ) {
        $ratio = self::RATIOS[ $ratio ] ?? self::RATIOS['portrait'];
        $seed = min( 2147483647, absint( $seed ) );
        if ( 'seedream-5-pro' === $model ) {
            // У Pro картинка всегда одна — поля maxImages нет; формат файла задаём jpeg, png весит вчетверо больше.
            return array( 'prompt' => $prompt, 'resolution' => '2k', 'aspectRatio' => $ratio, 'outputFormat' => 'jpeg', 'watermark' => 'false' );
        }
        if ( str_starts_with( $model, 'seedream' ) ) {
            // Пропорции задаём сами: иначе Seedream возьмёт их у первого образца — портрета лица.
            return array( 'prompt' => $prompt, 'resolution' => '2k', 'aspectRatio' => $ratio, 'maxImages' => 1, 'watermark' => 'false' );
        }
        // Расширение промпта выключено: оно дописывает своё и уводит от сюжета записи.
        $payload = array( 'prompt' => $prompt, 'resolution' => '2k', 'ratio' => $ratio, 'promptExtend' => 'false' );
        if ( $seed && ! empty( self::MODELS[ $model ]['seed'] ) ) {
            $payload['seed'] = $seed;
        }
        return $payload;
    }

    /**
     * Запуск со стенда «Генерация фото»: модель приходит как «wiro:z-image-pro».
     * Лишние изображения здесь ошибка, а не молча отброшенные файлы — на
     * стенде проверяют именно то, что отправили.
     */
    public static function stand_start( $data ) {
        $model = substr( (string) ( $data['model'] ?? '' ), strlen( 'wiro:' ) );
        if ( ! isset( self::MODELS[ $model ] ) ) {
            return self::error( 'Такой модели Wiro в списке нет.' );
        }
        $images = array_values( array_filter( array_map( 'absint', (array) ( $data['images'] ?? array() ) ) ) );
        $limit = self::refs( $model );
        if ( count( $images ) > $limit ) {
            return self::error( self::MODELS[ $model ]['title'] . ( $limit ? ' берёт не больше ' . $limit . ' изображений.' : ' рисует только по тексту — уберите изображения.' ) );
        }
        $started = self::start( $model, $data['prompt'] ?? '', $data['ratio'] ?? 'portrait', $images, $data['seed'] ?? 0 );
        return is_wp_error( $started ) ? $started : array( 'id' => 'wiro:' . $started['id'], 'status' => 'pending', 'model' => $model );
    }

    public static function start( $model, $prompt, $ratio, $refs = array(), $seed = 0 ) {
        if ( ! isset( self::MODELS[ $model ] ) ) {
            return self::error( 'Такой модели Wiro в списке нет.' );
        }
        // Переносы строк — пробелами: Wiro передаёт их модели буквальным «\n» (видно в parameters задачи 5054113).
        $prompt = trim( (string) preg_replace( '/\s*\R\s*/u', ' ', wp_strip_all_tags( (string) $prompt ) ) );
        if ( mb_strlen( $prompt ) < 3 ) {
            return self::error( 'Опишите картинку — хотя бы несколько слов.' );
        }
        $files = array();
        foreach ( array_slice( array_map( 'absint', (array) $refs ), 0, self::refs( $model ) ) as $attachment_id ) {
            $file = self::reference_file( $attachment_id );
            if ( is_wp_error( $file ) ) {
                return $file;
            }
            $files[] = $file;
        }
        $payload = self::payload( $model, mb_substr( $prompt, 0, 5000 ), $ratio, $seed );
        $body = self::request( '/Run/' . self::MODELS[ $model ]['slug'], $payload, 90, null, $files );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $id = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) ( $body['taskid'] ?? '' ) );
        if ( '' === $id ) {
            return self::error( 'Wiro не вернул задачу генерации.', 502 );
        }
        set_transient( self::key( $id ), array( 'user' => VKT_Account::id(), 'model' => $model, 'started' => time() ), HOUR_IN_SECONDS );
        VKT_Store::log( 'wiro.' . $model, 'ai', 'ok', 0, 'Задача генерации поставлена', 0, array( 'request' => array( 'model' => self::MODELS[ $model ]['slug'] ) + self::shown( $payload, $files ), 'response' => $body ) );
        return array( 'id' => $id, 'status' => 'pending' );
    }

    private static function key( $id ) {
        return 'vkt_wiro_' . hash( 'sha256', (string) $id );
    }

    /** Статус задачи. Готовый файл сразу кладём в медиатеку: ссылкам CDN Wiro срок не обещан. */
    public static function status( $id ) {
        $id = is_string( $id ) ? trim( $id ) : '';
        $task = '' !== $id ? get_transient( self::key( $id ) ) : false;
        if ( ! is_array( $task ) || absint( $task['user'] ) !== VKT_Account::id() ) {
            return self::error( 'Задача не найдена.', 404 );
        }
        $body = self::request( '/Task/Detail', array( 'taskid' => $id ), 30 );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $item = is_array( $body['tasklist'][0] ?? null ) ? $body['tasklist'][0] : array();
        $status = (string) ( $item['status'] ?? '' );
        if ( 'task_cancel' === $status ) {
            delete_transient( self::key( $id ) );
            VKT_Store::log( 'wiro.' . $task['model'], 'ai', 'error', 0, 'Wiro: задача отменена', 0, array( 'response' => $item ) );
            return self::error( 'Wiro отменил задачу.' . self::debug( $item ), 422 );
        }
        if ( 'task_postprocess_end' !== $status ) {
            return array( 'status' => 'pending', 'stage' => in_array( $status, self::QUEUED, true ) || '' === $status ? 'Pending' : 'Generating', 'progress' => null );
        }
        delete_transient( self::key( $id ) );
        $url = '';
        foreach ( (array) ( $item['outputs'] ?? array() ) as $output ) {
            if ( is_array( $output ) && '' !== (string) ( $output['url'] ?? '' ) && ! str_starts_with( (string) ( $output['contenttype'] ?? 'image/' ), 'video' ) ) {
                $url = (string) $output['url'];
                break;
            }
        }
        if ( '0' !== (string) ( $item['pexit'] ?? '' ) || '' === $url ) {
            VKT_Store::log( 'wiro.' . $task['model'], 'ai', 'error', 0, 'Wiro: генерация не удалась', 0, array( 'response' => $item ) );
            return self::error( 'Wiro не нарисовал картинку.' . self::debug( $item ), 422 );
        }
        $media = VKT_Media::sideload( $url, 'image' );
        if ( is_wp_error( $media ) ) {
            return $media;
        }
        VKT_Store::log( 'wiro.' . $task['model'], 'ai', 'ok', 0, 'Изображение получено и сохранено в медиатеку', ( time() - (int) $task['started'] ) * 1000, array( 'response' => $item ) );
        // Сколько списано, Wiro пишет в долларах — на стенде это видно рядом с картинкой.
        return array( 'status' => 'done', 'media' => $media, 'cost_usd' => isset( $item['totalcost'] ) ? round( (float) $item['totalcost'], 4 ) : null );
    }

    /** Хвост debugerror: там Wiro пишет причину сбоя модели. */
    private static function debug( $item ) {
        $text = trim( sanitize_text_field( (string) ( $item['debugerror'] ?? '' ) ) );
        return '' === $text ? '' : ' Причина: ' . mb_substr( $text, -240 );
    }
}

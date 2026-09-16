<?php
defined( 'ABSPATH' ) || exit;

/** Локальные файлы WordPress и их подготовка для вложений wall.post. */
final class VKT_Media {
    const MAX_ITEMS = 10;
    const MAX_FILE_BYTES = 104857600; // 100 МБ на один файл в интерфейсе плагина.

    private static function error( $message, $status = 400, $retryable = false ) {
        return new WP_Error( 'vkt_media', $message, array( 'status' => $status, 'retryable' => $retryable ) );
    }

    private static function allowed_mime( $mime ) {
        return str_starts_with( (string) $mime, 'image/' ) || 'video/mp4' === $mime;
    }

    public static function item( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        $mime = (string) get_post_mime_type( $attachment_id );
        $path = get_attached_file( $attachment_id );
        $url = wp_get_attachment_url( $attachment_id );
        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! self::allowed_mime( $mime ) || ! is_string( $path ) || ! is_file( $path ) || ! $url ) {
            return self::error( 'Локальный файл не найден или имеет неподдерживаемый формат.', 404 );
        }
        $size = (int) filesize( $path );
        if ( $size < 1 || $size > self::MAX_FILE_BYTES ) {
            return self::error( 'Размер одного файла должен быть от 1 байта до 100 МБ.' );
        }
        return array(
            'id' => $attachment_id,
            'url' => esc_url_raw( $url, array( 'https', 'http' ) ),
            'mime' => $mime,
            'type' => str_starts_with( $mime, 'image/' ) ? 'image' : 'video',
            'name' => sanitize_file_name( basename( $path ) ),
            'title' => sanitize_text_field( get_the_title( $attachment_id ) ),
            'path' => $path,
            'size' => $size,
        );
    }

    /** То же самое для браузера: без абсолютного пути, зато с превью. */
    public static function public_item( $attachment_id ) {
        $item = self::item( $attachment_id );
        if ( is_wp_error( $item ) ) {
            return $item;
        }
        unset( $item['path'] );
        $thumbnail = 'image' === $item['type'] ? wp_get_attachment_image_url( $item['id'], 'medium' ) : '';
        $item['thumbnail'] = $thumbnail ? esc_url_raw( $thumbnail, array( 'https', 'http' ) ) : '';
        return $item;
    }

    /** Недавние файлы медиатеки: выбор кликом вместо ручного поиска ID вложения. */
    public static function library( $limit = 24, $search = '' ) {
        $posts = get_posts( array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => array( 'image', 'video/mp4' ),
            'posts_per_page' => max( 1, min( 60, absint( $limit ) ) ),
            's' => sanitize_text_field( (string) $search ),
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => false,
            'fields' => 'ids',
        ) );
        $items = array();
        foreach ( $posts as $id ) {
            $item = self::public_item( $id );
            if ( ! is_wp_error( $item ) ) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** Сведения о выбранных файлах для конструктора записи. */
    public static function public_items( $raw ) {
        $ids = self::validate_ids( $raw );
        if ( is_wp_error( $ids ) ) {
            return $ids;
        }
        $items = array();
        foreach ( $ids as $id ) {
            $item = self::public_item( $id );
            if ( is_wp_error( $item ) ) {
                return $item;
            }
            $items[] = $item;
        }
        return $items;
    }

    public static function validate_ids( $raw ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
        if ( count( $ids ) > self::MAX_ITEMS ) {
            return self::error( 'Можно прикрепить не больше 10 локальных файлов.' );
        }
        foreach ( $ids as $id ) {
            if ( is_wp_error( self::item( $id ) ) ) {
                return self::error( 'Один из выбранных файлов удалён или недоступен.' );
            }
        }
        return $ids;
    }

    /** Принимает multipart/form-data из закрытого REST-маршрута. */
    public static function handle_upload() {
        if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
            return self::error( 'Выберите изображение или MP4-видео.' );
        }
        $file = $_FILES['file'];
        if ( ! empty( $file['size'] ) && (int) $file['size'] > min( self::MAX_FILE_BYTES, wp_max_upload_size() ) ) {
            return self::error( 'Файл превышает допустимый размер загрузки сервера.' );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload( 'file', 0, array(), array( 'test_form' => false ) );
        if ( is_wp_error( $attachment_id ) ) {
            return self::error( 'WordPress не сохранил файл: ' . $attachment_id->get_error_message() );
        }
        $item = self::public_item( $attachment_id );
        if ( is_wp_error( $item ) ) {
            wp_delete_attachment( $attachment_id, true );
            return $item;
        }
        return $item;
    }

    /** Сохраняет временный результат xAI в медиатеку WordPress. */
    public static function sideload( $url, $kind ) {
        $url = esc_url_raw( (string) $url, array( 'https' ) );
        if ( ! $url || ! wp_http_validate_url( $url ) || ! in_array( $kind, array( 'image', 'video' ), true ) ) {
            return self::error( 'Сервис генерации вернул небезопасный адрес файла.', 502 );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $name = 'vkt-ai-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . ( 'image' === $kind ? '.jpg' : '.mp4' );
        $tmp = wp_tempnam( $name );
        if ( ! $tmp ) {
            return self::error( 'Не удалось создать временный файл.', 500 );
        }
        $response = wp_safe_remote_get( $url, array(
            'timeout' => 'video' === $kind ? 180 : 90,
            'redirection' => 3,
            'stream' => true,
            'filename' => $tmp,
            'limit_response_size' => self::MAX_FILE_BYTES + 1,
        ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! is_file( $tmp ) ) {
            @unlink( $tmp );
            return self::error( 'Не удалось скачать сгенерированный файл.', 502, true );
        }
        $size = (int) filesize( $tmp );
        if ( $size < 1 || $size > self::MAX_FILE_BYTES ) {
            @unlink( $tmp );
            return self::error( 'Сгенерированный файл пуст или больше 100 МБ.', 422 );
        }
        $attachment_id = media_handle_sideload( array(
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => $size,
            'error' => 0,
        ), 0, 'Материал для публикации, созданный xAI' );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return self::error( 'WordPress не сохранил сгенерированный файл: ' . $attachment_id->get_error_message(), 500 );
        }
        return self::public_item( $attachment_id );
    }

    /** Пустые «» и «[]» означают, что сервер загрузки файл отверг. */
    private static function accepted( $uploaded ) {
        $photo = trim( (string) ( $uploaded['photo'] ?? '' ) );
        return '' !== $photo && '[]' !== $photo;
    }

    private static function multipart_upload( $url, $field, $item ) {
        $url = esc_url_raw( (string) $url, array( 'https' ) );
        if ( ! $url || ! wp_http_validate_url( $url ) ) {
            return self::error( 'VK вернул небезопасный адрес сервера загрузки.', 502 );
        }
        $contents = file_get_contents( $item['path'] );
        if ( false === $contents ) {
            return self::error( 'Не удалось прочитать локальный файл.', 500 );
        }
        $boundary = '----------------vkt' . wp_generate_password( 24, false, false );
        $filename = str_replace( array( '"', "\r", "\n" ), '', $item['name'] );
        $body = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $filename . '"' . "\r\n"
            . 'Content-Type: ' . $item['mime'] . "\r\n\r\n"
            . $contents . "\r\n--" . $boundary . "--\r\n";
        unset( $contents );
        $response = wp_safe_remote_post( $url, array(
            'timeout' => 180,
            'redirection' => 0,
            'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
            'body' => $body,
            'limit_response_size' => 1048576,
        ) );
        unset( $body );
        if ( is_wp_error( $response ) ) {
            return self::error( 'Не удалось передать файл на сервер загрузки VK.', 502, true );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || isset( $data['error'] ) ) {
            return self::error( 'Сервер загрузки VK отклонил файл.', 502, true );
        }
        return $data;
    }

    /**
     * Сервер загрузки VK изредка отвечает пустым photo — файл он при этом не
     * принял. Отдавать такую пустоту в photos.saveWallPhoto бессмысленно: VK
     * ответит «photo is undefined», и причина будет непонятна. Поэтому пробуем
     * ещё раз с новым адресом загрузки и только потом сдаёмся.
     */
    private static function upload_photo( $item, $group_id ) {
        $uploaded = null;
        for ( $attempt = 1; $attempt <= 2; ++$attempt ) {
            $server = VKT_API::publishing_request( 'photos.getWallUploadServer', array( 'group_id' => $group_id ) );
            if ( is_wp_error( $server ) ) {
                return $server;
            }
            $uploaded = self::multipart_upload( (string) ( $server['response']['upload_url'] ?? '' ), 'photo', $item );
            if ( is_wp_error( $uploaded ) ) {
                return $uploaded;
            }
            if ( self::accepted( $uploaded ) ) {
                break;
            }
            $uploaded = null;
        }
        if ( null === $uploaded ) {
            return self::error( 'Сервер загрузки VK не принял файл «' . $item['name'] . '»: в ответе пустое поле photo. Проверьте, что это обычный JPEG или PNG и он не повреждён.', 502, true );
        }
        foreach ( array( 'server', 'photo', 'hash' ) as $field ) {
            if ( ! isset( $uploaded[ $field ] ) || ! is_scalar( $uploaded[ $field ] ) ) {
                return self::error( 'Сервер загрузки VK вернул неполные данные фотографии.', 502, true );
            }
        }
        $saved = VKT_API::publishing_request( 'photos.saveWallPhoto', array(
            'group_id' => $group_id,
            'server' => $uploaded['server'],
            'photo' => $uploaded['photo'],
            'hash' => $uploaded['hash'],
        ) );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        $photo = (array) ( $saved['response'][0] ?? array() );
        $owner_id = (int) ( $photo['owner_id'] ?? -$group_id );
        $photo_id = absint( $photo['id'] ?? 0 );
        if ( ! $photo_id ) {
            return self::error( 'VK не вернул ID сохранённой фотографии.', 502, true );
        }
        return 'photo' . $owner_id . '_' . $photo_id . ( ! empty( $photo['access_key'] ) ? '_' . preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $photo['access_key'] ) : '' );
    }

    private static function upload_video( $item, $group_id ) {
        $saved = VKT_API::publishing_request( 'video.save', array(
            'group_id' => $group_id,
            'name' => mb_substr( $item['title'] ?: pathinfo( $item['name'], PATHINFO_FILENAME ), 0, 128 ),
            'wallpost' => 0,
        ) );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        $video = (array) ( $saved['response'] ?? array() );
        $uploaded = self::multipart_upload( (string) ( $video['upload_url'] ?? '' ), 'video_file', $item );
        if ( is_wp_error( $uploaded ) ) {
            return $uploaded;
        }
        $owner_id = (int) ( $uploaded['owner_id'] ?? $video['owner_id'] ?? -$group_id );
        $video_id = absint( $uploaded['video_id'] ?? $video['video_id'] ?? 0 );
        if ( ! $video_id ) {
            return self::error( 'VK не вернул ID загруженного видео.', 502, true );
        }
        return 'video' . $owner_id . '_' . $video_id . ( ! empty( $video['access_key'] ) ? '_' . preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $video['access_key'] ) : '' );
    }

    /** Возвращает строку attachment ID; с одним group-token использует публичный URL. */
    public static function prepare_for_vk( $media_ids, $group_id ) {
        $ids = self::validate_ids( $media_ids );
        if ( is_wp_error( $ids ) ) {
            return $ids;
        }
        if ( ! $ids ) {
            return '';
        }
        $items = array();
        foreach ( $ids as $id ) {
            $item = self::item( $id );
            if ( is_wp_error( $item ) ) {
                return $item;
            }
            $items[] = $item;
        }
        // Проверено живыми запросами 14.09.2026: ключу сообщества VK закрывает
        // photos.getWallUploadServer (27), video.save (5) и docs (15), фото из
        // messages-альбома wall.post молча отбрасывает, а ссылку на файл
        // отклоняет кодом 100. Рабочего обходного пути нет.
        if ( ! VKT_Tokens::has( 'user' ) ) {
            return self::error( 'VK не разрешает ключу сообщества загружать фото и видео. Чтобы прикладывать файлы с сервера, сохраните в настройках пользовательский токен VK ID с правами wall, photos, groups и video.' );
        }
        $attachments = array();
        foreach ( $items as $item ) {
            $attachment = 'image' === $item['type'] ? self::upload_photo( $item, $group_id ) : self::upload_video( $item, $group_id );
            if ( is_wp_error( $attachment ) ) {
                return $attachment;
            }
            $attachments[] = $attachment;
        }
        return implode( ',', $attachments );
    }
}

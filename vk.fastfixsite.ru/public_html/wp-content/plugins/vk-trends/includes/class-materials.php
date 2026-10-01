<?php
defined( 'ABSPATH' ) || exit;

/**
 * База ведения группы: материалы, по которым модель пишет посты, серии и
 * ответы на комментарии. Материал — либо файл из библиотеки плагина
 * (materials/*.md), либо свой текст владельца.
 *
 * Библиотечный материал хранится ссылкой на файл, а не копией: поправили
 * методику в плагине — она обновилась у всех групп сразу.
 */
final class VKT_Materials {
    const MAX_ITEMS = 12;
    const TEXT_MAX = 20000;
    // В запрос уходит начало материала: длинный документ целиком и дорог, и размывает задачу.
    const PROMPT_EACH = 6000;
    const PROMPT_TOTAL = 12000;

    private static $library = null;

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_materials', $message, array( 'status' => $status ) );
    }

    /**
     * Текст владельца как есть, без управляющих символов. Теги не вырезаем:
     * strip_tags принимает «цена <1000 руб» за начало тега и молча съедает
     * всё до конца. Наружу текст выходит только экранированным.
     */
    public static function plain( $value ) {
        $value = is_string( $value ) ? str_replace( array( "\r\n", "\r" ), "\n", $value ) : '';
        return trim( (string) preg_replace( '/[^\P{C}\n\t]+/u', '', $value ) );
    }

    /**
     * Файлы библиотеки. Первая строка «# …» — название, строка «> …» —
     * описание для интерфейса; в модель уходит всё остальное.
     */
    public static function library() {
        if ( null !== self::$library ) {
            return self::$library;
        }
        self::$library = array();
        foreach ( (array) glob( VKT_DIR . 'materials/*.md' ) as $path ) {
            $key = basename( $path, '.md' );
            $raw = preg_match( '/^[a-z0-9-]{2,40}$/', $key ) ? (string) file_get_contents( $path ) : '';
            if ( '' === trim( $raw ) ) {
                continue;
            }
            $title = $key;
            $description = '';
            $body = array();
            foreach ( explode( "\n", str_replace( "\r\n", "\n", $raw ) ) as $line ) {
                if ( $title === $key && str_starts_with( $line, '# ' ) ) {
                    $title = trim( substr( $line, 2 ) );
                } elseif ( '' === $description && str_starts_with( $line, '> ' ) ) {
                    $description = trim( substr( $line, 2 ) );
                } else {
                    $body[] = $line;
                }
            }
            self::$library[ $key ] = array( 'file' => $key, 'title' => $title, 'description' => $description, 'text' => trim( implode( "\n", $body ) ) );
        }
        return self::$library;
    }

    /**
     * Сохранённый список в рабочий вид: у библиотечных подставлен текст из
     * файла. Материал, чей файл из плагина убрали, пропадает из списка.
     */
    public static function normalize( $raw ) {
        $stored = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        $library = self::library();
        $list = array();
        foreach ( is_array( $stored ) ? $stored : array() as $item ) {
            if ( ! is_array( $item ) || ! preg_match( '/^m[a-f0-9]{8}$/', (string) ( $item['id'] ?? '' ) ) ) {
                continue;
            }
            $file = (string) ( $item['file'] ?? '' );
            if ( '' !== $file && ! isset( $library[ $file ] ) ) {
                continue;
            }
            $text = '' !== $file ? $library[ $file ]['text'] : (string) ( $item['text'] ?? '' );
            $list[] = array(
                'id' => (string) $item['id'],
                'file' => $file,
                'title' => '' !== $file ? $library[ $file ]['title'] : (string) ( $item['title'] ?? '' ),
                'text' => $text,
                'chars' => mb_strlen( $text ),
                'posts' => ! empty( $item['posts'] ),
                'replies' => ! empty( $item['replies'] ),
            );
        }
        return array_slice( $list, 0, self::MAX_ITEMS );
    }

    /** Рабочий список в JSON для базы: текст библиотечных не дублируем. */
    public static function pack( $list ) {
        return wp_json_encode( array_map( static fn( $item ) => array(
            'id' => $item['id'],
            'file' => $item['file'],
            'title' => '' !== $item['file'] ? '' : $item['title'],
            'text' => '' !== $item['file'] ? '' : $item['text'],
            'posts' => $item['posts'],
            'replies' => $item['replies'],
        ), $list ), JSON_UNESCAPED_UNICODE );
    }

    /**
     * Добавляет материал или правит существующий. Приходит только то, что
     * меняется: галочки переключаются без пересылки текста.
     */
    public static function upsert( $list, $data ) {
        $data = is_array( $data ) ? $data : array();
        $flag = static fn( $key, $default ) => array_key_exists( $key, $data ) ? ! empty( $data[ $key ] ) : $default;
        $id = (string) ( $data['id'] ?? '' );
        if ( '' !== $id ) {
            foreach ( $list as $index => $item ) {
                if ( $item['id'] !== $id ) {
                    continue;
                }
                $item['posts'] = $flag( 'posts', $item['posts'] );
                $item['replies'] = $flag( 'replies', $item['replies'] );
                if ( '' === $item['file'] && ( array_key_exists( 'title', $data ) || array_key_exists( 'text', $data ) ) ) {
                    $checked = self::own( array_key_exists( 'title', $data ) ? $data['title'] : $item['title'], array_key_exists( 'text', $data ) ? $data['text'] : $item['text'] );
                    if ( is_wp_error( $checked ) ) {
                        return $checked;
                    }
                    $item = array_merge( $item, $checked );
                }
                $list[ $index ] = $item;
                return $list;
            }
            return self::error( 'Материал не найден — возможно, его уже убрали.', 404 );
        }
        if ( count( $list ) >= self::MAX_ITEMS ) {
            return self::error( 'У группы не больше ' . self::MAX_ITEMS . ' материалов: модель всё равно не прочтёт больше. Уберите лишний.' );
        }
        $item = array( 'id' => 'm' . bin2hex( random_bytes( 4 ) ), 'file' => '', 'posts' => $flag( 'posts', true ), 'replies' => $flag( 'replies', false ) );
        $file = is_string( $data['file'] ?? null ) ? $data['file'] : '';
        if ( '' !== $file ) {
            $library = self::library();
            if ( ! isset( $library[ $file ] ) ) {
                return self::error( 'Такого материала в библиотеке плагина нет.', 404 );
            }
            if ( in_array( $file, array_column( $list, 'file' ), true ) ) {
                return self::error( 'Этот материал уже подключён к группе.' );
            }
            $list[] = array_merge( $item, array( 'file' => $file, 'title' => $library[ $file ]['title'], 'text' => $library[ $file ]['text'], 'chars' => mb_strlen( $library[ $file ]['text'] ) ) );
            return $list;
        }
        $checked = self::own( $data['title'] ?? '', $data['text'] ?? '' );
        if ( is_wp_error( $checked ) ) {
            return $checked;
        }
        $list[] = array_merge( $item, $checked );
        return $list;
    }

    private static function own( $title, $text ) {
        // Название — одна строка: оно становится заголовком материала в запросе.
        $title = trim( (string) preg_replace( '/\s+/u', ' ', self::plain( $title ) ) );
        $text = self::plain( $text );
        if ( '' === $title || mb_strlen( $title ) > 120 ) {
            return self::error( 'Дайте материалу название — до 120 символов.' );
        }
        if ( mb_strlen( $text ) < 3 || mb_strlen( $text ) > self::TEXT_MAX ) {
            return self::error( 'Текст материала — от 3 до ' . self::TEXT_MAX . ' символов.' );
        }
        return array( 'title' => $title, 'text' => $text, 'chars' => mb_strlen( $text ) );
    }

    public static function remove( $list, $id ) {
        return array_values( array_filter( $list, static fn( $item ) => $item['id'] !== (string) $id ) );
    }

    /** Материалы для запроса: $purpose — posts (пост, серия) или replies (ответы на комментарии). */
    public static function prompt( $list, $purpose ) {
        $purpose = 'replies' === $purpose ? 'replies' : 'posts';
        $out = '';
        foreach ( $list as $item ) {
            if ( empty( $item[ $purpose ] ) || '' === trim( $item['text'] ) ) {
                continue;
            }
            $left = self::PROMPT_TOTAL - mb_strlen( $out );
            if ( $left < 200 ) {
                break;
            }
            $out .= ( '' === $out ? '' : "\n\n" ) . '### ' . $item['title'] . "\n" . mb_substr( $item['text'], 0, min( self::PROMPT_EACH, $left ) );
        }
        return $out;
    }
}

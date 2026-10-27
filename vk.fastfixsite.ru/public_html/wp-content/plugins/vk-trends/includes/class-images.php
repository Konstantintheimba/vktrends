<?php
defined( 'ABSPATH' ) || exit;

/**
 * Общий вход для рисования картинок к записям: один список моделей и одна
 * пара действий «поставить — спросить статус» для всех поставщиков.
 *
 * Новый поставщик добавляется одной записью в registry(): как понять, что
 * он подключён, какие у него модели, сколько опорных фото он берёт, как
 * поставить задачу и как спросить статус. Интерфейс и остальной код о поставщиках ничего не знают — они
 * получают готовый список и ID вида «поставщик:модель».
 */
final class VKT_Images {
    // Стороны кратны 32 — иначе BFL отвечает 422.
    const SIZES = array( 'portrait' => array( 768, 1024 ), 'square' => array( 1024, 1024 ), 'landscape' => array( 1344, 768 ), 'story' => array( 768, 1344 ) );

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_images', $message, array( 'status' => $status ) );
    }

    private static function registry() {
        return array(
            'xai' => array(
                'title' => 'xAI',
                'ready' => static fn() => VKT_AI::configured(),
                'models' => static fn() => array( VKT_AI::IMAGE_MODEL => VKT_AI::IMAGE_MODEL ),
                // Опорные фото xAI не принимает: персонажа он рисует только по описанию.
                'refs' => static fn( $model ) => 0,
                // xAI отдаёт картинку сразу, без задачи и опроса.
                'start' => static function ( $model, $prompt, $ratio, $refs ) {
                    $media = VKT_AI::generate_image( $prompt, $ratio );
                    return is_wp_error( $media ) ? $media : array( 'status' => 'done', 'media' => $media );
                },
                'status' => null,
            ),
            'openai' => array(
                'title' => 'OpenAI',
                'ready' => static fn() => (bool) VKT_AI::openai_image_models(),
                'models' => static fn() => VKT_AI::openai_image_models(),
                'refs' => static fn( $model ) => VKT_AI::OPENAI_IMAGE_REFS,
                // Как и xAI, отдаёт картинку сразу.
                'start' => static function ( $model, $prompt, $ratio, $refs ) {
                    $media = VKT_AI::generate_openai_image( $model, $prompt, $ratio, $refs );
                    return is_wp_error( $media ) ? $media : array( 'status' => 'done', 'media' => $media );
                },
                'status' => null,
            ),
            'wiro' => array(
                'title' => 'Wiro',
                'ready' => static fn() => VKT_Wiro::configured(),
                'models' => static fn() => VKT_Wiro::models(),
                // Z Image — только по тексту, Seedream — до четырёх образцов (см. VKT_Wiro::MODELS).
                'refs' => static fn( $model ) => VKT_Wiro::refs( $model ),
                'start' => static fn( $model, $prompt, $ratio, $refs ) => VKT_Wiro::start( $model, $prompt, $ratio, $refs ),
                'status' => static fn( $id ) => VKT_Wiro::status( $id ),
            ),
            'bfl' => array(
                'title' => 'BFL',
                'ready' => static fn() => VKT_Flux::configured(),
                'models' => static fn() => array_map( static fn( $model ) => $model['title'], VKT_Flux::models() ),
                // FLUX.2 pro/max/flex берут до восьми образцов, klein — до четырёх.
                'refs' => static fn( $model ) => str_contains( $model, 'klein' ) ? 4 : VKT_Flux::MAX_INPUTS,
                'start' => static function ( $model, $prompt, $ratio, $refs ) {
                    list( $width, $height ) = self::SIZES[ $ratio ];
                    return VKT_Flux::start( array( 'model' => $model, 'prompt' => $prompt, 'width' => $width, 'height' => $height, 'safety_tolerance' => 2, 'output_format' => 'jpeg', 'images' => $refs ), count( $refs ) );
                },
                'status' => static fn( $id ) => VKT_Flux::status( $id ),
            ),
        );
    }

    /** Подключённые модели для интерфейса: ID вида «bfl:flux-2-pro» и подпись. Если модель у поставщика одна, ID — просто его имя («xai»). */
    public static function providers() {
        $list = array();
        foreach ( self::registry() as $key => $provider ) {
            if ( ! $provider['ready']() ) {
                continue;
            }
            $models = $provider['models']();
            foreach ( $models as $model => $title ) {
                $list[] = array( 'id' => 1 === count( $models ) ? $key : $key . ':' . $model, 'title' => $provider['title'] . ' · ' . $title, 'refs' => $provider['refs']( (string) $model ) );
            }
        }
        return $list;
    }

    /**
     * Ставит картинку в работу. Ответ — либо готовый файл, либо ID задачи для status().
     * $persona — персонаж группы из VKT_Groups::image_brief(): его фото уходят
     * образцом тем, кто их принимает, описание и стиль — в промпт всем.
     * $match — текст записи по-русски: по нему отбираются предметы мира
     * персонажа, ведь сам промпт — уже английская сцена.
     */
    public static function start( $id, $prompt, $ratio, $persona = null, $match = '' ) {
        $id = is_string( $id ) ? $id : '';
        if ( ! in_array( $id, array_column( self::providers(), 'id' ), true ) ) {
            return self::error( 'Эта модель для картинок не подключена. Выберите другую из списка.' );
        }
        list( $key, $model ) = array_pad( explode( ':', $id, 2 ), 2, '' );
        $provider = self::registry()[ $key ];
        $ratio = is_string( $ratio ) && isset( self::SIZES[ $ratio ] ) ? $ratio : 'portrait';
        $model = '' !== $model ? $model : (string) array_key_first( $provider['models']() );
        $refs = array();
        if ( $persona ) {
            $plan = self::persona_plan( is_string( $prompt ) ? trim( $prompt ) : '', $persona, $provider['refs']( $model ), is_string( $match ) ? $match : '' );
            $prompt = $plan['prompt'];
            $refs = $plan['refs'];
        }
        $result = $provider['start']( $model, $prompt, $ratio, $refs );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        // Что именно ушло в модель, остаётся при картинке: без этого не понять, почему вышел не тот кадр.
        $note = array( 'prompt' => (string) $prompt, 'model' => $key . ':' . $model, 'refs' => count( $refs ) );
        if ( 'done' === ( $result['status'] ?? '' ) ) {
            $result['media'] = VKT_Media::describe( $result['media'], $note );
            return $result;
        }
        set_transient( self::note_key( $key . ':' . $result['id'] ), $note, DAY_IN_SECONDS );
        return array( 'status' => 'pending', 'id' => $key . ':' . $result['id'] );
    }

    private static function note_key( $id ) {
        return 'vkt_image_note_' . hash( 'sha256', (string) $id );
    }

    /**
     * Что из мира персонажа идёт в кадр и какие фото — образцами. Предмет
     * берётся, если в сюжете есть одно из его слов («кошк, Муся»); без слов —
     * всегда. Лицо идёт первым, затем по фото на предмет, вторые фото — если
     * осталось место у модели.
     */
    public static function persona_plan( $prompt, $persona, $limit, $match = '' ) {
        $source = trim( $prompt . "\n" . $match );
        $items = array_values( array_filter( (array) ( $persona['items'] ?? array() ), static fn( $item ) => self::wanted( $item, $source ) ) );
        $refs = array();
        $owners = array();
        foreach ( array_slice( (array) $persona['photos'], 0, $limit ) as $photo ) {
            $refs[] = $photo;
            $owners[] = 'person';
        }
        for ( $round = 0; $round < 2; ++$round ) {
            foreach ( $items as $index => $item ) {
                if ( count( $refs ) < $limit && isset( $item['photos'][ $round ] ) ) {
                    $refs[] = $item['photos'][ $round ];
                    $owners[] = $index;
                }
            }
        }
        return array( 'refs' => $refs, 'prompt' => self::persona_prompt( $prompt, $persona, $items, $owners ) );
    }

    private static function wanted( $item, $prompt ) {
        $words = array_filter( array_map( 'trim', preg_split( '/[,;\n]+/u', (string) ( $item['words'] ?? '' ) ) ) );
        foreach ( $words as $word ) {
            if ( false !== mb_stripos( $prompt, $word ) ) {
                return true;
            }
        }
        return ! $words;
    }

    /**
     * Персонаж и его мир — до сцены: модели держат внешность лучше, когда
     * она задана до сюжета. Хвост сцены при нехватке места режется, персонаж
     * и запреты — нет. $owners — чьё каждое опорное фото: 'person' или номер предмета.
     * Служебные фразы — по-английски: модели картинок понимают его надёжнее
     * (27.10.2026 Seedream пропустил русскую оговорку «кадр не копируй» и
     * вернул четырём постам одну кухню). Про образцы — один абзац, а не три
     * повтора: модель весит каждую фразу, и тройное «тот же человек» глушило
     * сцену. Запрет на посторонних — после сцены и с оговоркой: стоял до неё —
     * и мужа из пожелания автора модель выбрасывала.
     */
    public static function persona_prompt( $prompt, $persona, $items = array(), $owners = array() ) {
        // Номера фото одного владельца: «1–3», «4» или «4, 6».
        $numbers = static function ( $owner ) use ( $owners ) {
            $list = array_map( static fn( $index ) => $index + 1, array_keys( $owners, $owner, true ) );
            $range = $list && count( $list ) > 1 && end( $list ) - $list[0] === count( $list ) - 1;
            return $range ? $list[0] . '–' . end( $list ) : implode( ', ', $list );
        };
        $head = array();
        $person = $numbers( 'person' );
        if ( $owners ) {
            $map = '' !== $person ? array( ( str_contains( $person, '–' ) || str_contains( $person, ',' ) ? 'images ' : 'image ' ) . $person . ' show the main person' ) : array();
            foreach ( $items as $index => $item ) {
                if ( '' !== $numbers( $index ) ) {
                    $map[] = 'image ' . $numbers( $index ) . ' — ' . $item['title'];
                }
            }
            // Первой строкой: одно фото Seedream считал картинкой для правки и возвращал тот же кадр.
            $head[] = 'Create a completely new photograph, not an edit of the reference images. Reference images: ' . implode( '; ', $map ) . '.'
                . ( '' !== $person ? ' Keep the main person\'s identity — face, facial features, hair colour and length, body type and age.' : '' )
                . ' Take nothing else from the references: the place, background, clothing, pose, framing and light come from the scene.';
        }
        if ( '' !== $persona['look'] ) {
            $head[] = ( '' !== $person ? 'The main person: ' : 'The main person is always the same: ' ) . $persona['look'];
        }
        if ( $items ) {
            $head[] = "Recurring places, things and pets of the blog — show them as in the references and descriptions:\n" . implode( "\n", array_map( static fn( $item ) => '- ' . $item['title'] . ( '' !== $item['look'] ? ': ' . $item['look'] : '' ), $items ) );
        }
        if ( '' !== ( $persona['visual'] ?? '' ) ) {
            $head[] = 'Photo style: ' . $persona['visual'];
        }
        if ( '' !== ( $persona['rules'] ?? '' ) ) {
            $head[] = 'Photo rules: ' . $persona['rules'];
        }
        if ( ! $head ) {
            return $prompt;
        }
        // Модели любят дорисовать уюта — котика или подругу; у блога они свои, чужие ломают узнаваемость.
        $tail = '' !== $persona['look'] || $persona['photos'] || ! empty( $persona['items'] ) ? "\n\nOnly the people and animals named in the scene — nobody else." : '';
        $head = implode( "\n\n", $head ) . "\n\nScene:\n";
        return $head . mb_substr( $prompt, 0, max( 0, 5000 - mb_strlen( $head ) - mb_strlen( $tail ) ) ) . $tail;
    }

    public static function status( $id ) {
        list( $key, $task ) = array_pad( explode( ':', is_string( $id ) ? $id : '', 2 ), 2, '' );
        $provider = self::registry()[ $key ] ?? null;
        if ( ! $provider || ! $provider['status'] || '' === $task ) {
            return self::error( 'Задача генерации не найдена.', 404 );
        }
        $result = $provider['status']( $task );
        $note = ! is_wp_error( $result ) && 'done' === ( $result['status'] ?? '' ) ? get_transient( self::note_key( $id ) ) : false;
        if ( is_array( $note ) ) {
            $result['media'] = VKT_Media::describe( $result['media'], $note );
            delete_transient( self::note_key( $id ) );
        }
        return $result;
    }
}

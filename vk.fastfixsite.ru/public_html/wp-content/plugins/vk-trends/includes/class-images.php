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
     */
    public static function start( $id, $prompt, $ratio, $persona = null ) {
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
            $plan = self::persona_plan( is_string( $prompt ) ? trim( $prompt ) : '', $persona, $provider['refs']( $model ) );
            $prompt = $plan['prompt'];
            $refs = $plan['refs'];
        }
        $result = $provider['start']( $model, $prompt, $ratio, $refs );
        if ( is_wp_error( $result ) || 'done' === ( $result['status'] ?? '' ) ) {
            return $result;
        }
        return array( 'status' => 'pending', 'id' => $key . ':' . $result['id'] );
    }

    /**
     * Что из мира персонажа идёт в кадр и какие фото — образцами. Предмет
     * берётся, если в сюжете есть одно из его слов («кошк, Муся»); без слов —
     * всегда. Лицо идёт первым, затем по фото на предмет, вторые фото — если
     * осталось место у модели.
     */
    public static function persona_plan( $prompt, $persona, $limit ) {
        $items = array_values( array_filter( (array) ( $persona['items'] ?? array() ), static fn( $item ) => self::wanted( $item, $prompt ) ) );
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
     * Персонаж и его мир — в начале промпта: модели держат внешность лучше,
     * когда она задана до сюжета. Хвост сюжета при нехватке места режется,
     * персонаж — нет. $owners — чьё каждое опорное фото: 'person' или номер предмета.
     */
    public static function persona_prompt( $prompt, $persona, $items = array(), $owners = array() ) {
        // Номера фото одного владельца: «1–3», «4» или «4, 6».
        $numbers = static function ( $owner ) use ( $owners ) {
            $list = array_map( static fn( $index ) => $index + 1, array_keys( $owners, $owner, true ) );
            $range = $list && count( $list ) > 1 && end( $list ) - $list[0] === count( $list ) - 1;
            return $range ? $list[0] . '–' . end( $list ) : implode( ', ', $list );
        };
        $lines = array();
        $person = $numbers( 'person' );
        if ( $owners ) {
            $map = '' !== $person ? array( 'фото ' . $person . ' — главный человек кадра' ) : array();
            foreach ( $items as $index => $item ) {
                if ( '' !== $numbers( $index ) ) {
                    $map[] = 'фото ' . $numbers( $index ) . ' — ' . $item['title'];
                }
            }
            $lines[] = 'Опорные фото по порядку: ' . implode( '; ', $map ) . '. Это образцы того, как выглядят человек, места, вещи и питомцы. Позу, одежду, ракурс и свет бери из сюжета ниже, кадры с опорных фото целиком не копируй.';
        }
        if ( '' !== $person ) {
            $lines[] = 'Главный человек в кадре — тот же, что на опорных фото ' . $person . ': то же лицо и черты лица, цвет и длина волос, телосложение, возраст.';
        }
        if ( '' !== $persona['look'] ) {
            $lines[] = ( '' !== $person ? 'Внешность героя: ' : 'Главный человек в кадре всегда один и тот же. Его внешность: ' ) . $persona['look'];
        }
        if ( $items ) {
            $lines[] = "Постоянные места, вещи и питомцы блога — в кадре они точно такие, как на опорных фото и в описании, других вместо них не придумывай:\n" . implode( "\n", array_map( static fn( $item ) => '- ' . $item['title'] . ( '' !== $item['look'] ? ': ' . $item['look'] : '' ), $items ) );
        }
        if ( '' !== $persona['look'] || $persona['photos'] || ! empty( $persona['items'] ) ) {
            // Модели любят дорисовать уюта — котика или подругу; у блога они свои, чужие ломают узнаваемость.
            $lines[] = 'Не добавляй в кадр других людей и животных, если их нет в сюжете.';
        }
        if ( '' !== ( $persona['visual'] ?? '' ) ) {
            $lines[] = 'Как снимает блог: ' . $persona['visual'];
        }
        return $lines ? mb_substr( implode( "\n\n", $lines ) . "\n\nСюжет:\n" . $prompt, 0, 5000 ) : $prompt;
    }

    public static function status( $id ) {
        list( $key, $task ) = array_pad( explode( ':', is_string( $id ) ? $id : '', 2 ), 2, '' );
        $provider = self::registry()[ $key ] ?? null;
        if ( ! $provider || ! $provider['status'] || '' === $task ) {
            return self::error( 'Задача генерации не найдена.', 404 );
        }
        return $provider['status']( $task );
    }
}

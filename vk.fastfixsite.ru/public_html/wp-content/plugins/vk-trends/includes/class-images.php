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
                'refs' => 0,
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
                'refs' => VKT_AI::OPENAI_IMAGE_REFS,
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
                'refs' => VKT_Flux::MAX_REFERENCES,
                'start' => static function ( $model, $prompt, $ratio, $refs ) {
                    list( $width, $height ) = self::SIZES[ $ratio ];
                    return VKT_Flux::start( array( 'model' => $model, 'prompt' => $prompt, 'width' => $width, 'height' => $height, 'safety_tolerance' => 2, 'output_format' => 'jpeg', 'images' => $refs ) );
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
                $list[] = array( 'id' => 1 === count( $models ) ? $key : $key . ':' . $model, 'title' => $provider['title'] . ' · ' . $title, 'refs' => $provider['refs'] );
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
        $refs = $persona ? array_slice( $persona['photos'], 0, $provider['refs'] ) : array();
        if ( $persona ) {
            $prompt = self::persona_prompt( is_string( $prompt ) ? trim( $prompt ) : '', $persona, (bool) $refs );
        }
        $result = $provider['start']( '' !== $model ? $model : (string) array_key_first( $provider['models']() ), $prompt, $ratio, $refs );
        if ( is_wp_error( $result ) || 'done' === ( $result['status'] ?? '' ) ) {
            return $result;
        }
        return array( 'status' => 'pending', 'id' => $key . ':' . $result['id'] );
    }

    /**
     * Персонаж — в начале промпта: модели держат внешность лучше, когда она
     * задана до сюжета. Хвост сюжета при нехватке места режется, персонаж — нет.
     */
    public static function persona_prompt( $prompt, $persona, $with_photos ) {
        $lines = array();
        if ( $with_photos ) {
            $lines[] = 'Главный человек в кадре — тот же, что на опорных фото: то же лицо и черты лица, цвет и длина волос, телосложение, возраст. Опорные фото — только образец внешности: позу, одежду, место, свет и ракурс бери из сюжета ниже, фон и кадр с опорных фото не копируй.';
        }
        if ( '' !== $persona['look'] ) {
            $lines[] = ( $with_photos ? 'Внешность героя: ' : 'Главный человек в кадре всегда один и тот же. Его внешность: ' ) . $persona['look'];
        }
        if ( '' !== $persona['visual'] ) {
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

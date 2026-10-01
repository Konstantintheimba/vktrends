<?php
defined( 'ABSPATH' ) || exit;

/**
 * Общий вход для рисования картинок к записям: один список моделей и одна
 * пара действий «поставить — спросить статус» для всех поставщиков.
 *
 * Новый поставщик добавляется одной записью в registry(): как понять, что
 * он подключён, какие у него модели, как поставить задачу и как спросить
 * статус. Интерфейс и остальной код о поставщиках ничего не знают — они
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
                // xAI отдаёт картинку сразу, без задачи и опроса.
                'start' => static function ( $model, $prompt, $ratio ) {
                    $media = VKT_AI::generate_image( $prompt, $ratio );
                    return is_wp_error( $media ) ? $media : array( 'status' => 'done', 'media' => $media );
                },
                'status' => null,
            ),
            'bfl' => array(
                'title' => 'BFL',
                'ready' => static fn() => VKT_Flux::configured(),
                'models' => static fn() => array_map( static fn( $model ) => $model['title'], VKT_Flux::models() ),
                'start' => static function ( $model, $prompt, $ratio ) {
                    list( $width, $height ) = self::SIZES[ $ratio ];
                    return VKT_Flux::start( array( 'model' => $model, 'prompt' => $prompt, 'width' => $width, 'height' => $height, 'safety_tolerance' => 2, 'output_format' => 'jpeg' ) );
                },
                'status' => static fn( $id ) => VKT_Flux::status( $id ),
            ),
        );
    }

    /** Подключённые модели для интерфейса: ID вида «bfl:flux-2-pro» и подпись. У xAI модель одна, поэтому ID — просто «xai». */
    public static function providers() {
        $list = array();
        foreach ( self::registry() as $key => $provider ) {
            if ( ! $provider['ready']() ) {
                continue;
            }
            $models = $provider['models']();
            foreach ( $models as $model => $title ) {
                $list[] = array( 'id' => 1 === count( $models ) ? $key : $key . ':' . $model, 'title' => $provider['title'] . ' · ' . $title );
            }
        }
        return $list;
    }

    /** Ставит картинку в работу. Ответ — либо готовый файл, либо ID задачи для status(). */
    public static function start( $id, $prompt, $ratio ) {
        $id = is_string( $id ) ? $id : '';
        if ( ! in_array( $id, array_column( self::providers(), 'id' ), true ) ) {
            return self::error( 'Эта модель для картинок не подключена. Выберите другую из списка.' );
        }
        list( $key, $model ) = array_pad( explode( ':', $id, 2 ), 2, '' );
        $provider = self::registry()[ $key ];
        $ratio = is_string( $ratio ) && isset( self::SIZES[ $ratio ] ) ? $ratio : 'portrait';
        $result = $provider['start']( '' !== $model ? $model : (string) array_key_first( $provider['models']() ), $prompt, $ratio );
        if ( is_wp_error( $result ) || 'done' === ( $result['status'] ?? '' ) ) {
            return $result;
        }
        return array( 'status' => 'pending', 'id' => $key . ':' . $result['id'] );
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

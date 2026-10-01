<?php
defined( 'ABSPATH' ) || exit;

/**
 * Товарный пост по методике VK Shops. Методика нужна не всей группе, а
 * одной-двум записям серии: её включают в окне записи, когда пост должен
 * продавать товар. Правила лежат в prompts/vk-shops.md и уходят в модель
 * только с таким запросом.
 */
final class VKT_Shops {
    const HOOKS = array(
        'intrigue' => 'интрига', 'conflict' => 'конфликт', 'fact' => 'шок или сильный факт', 'dispute' => 'спорное утверждение',
        'curiosity' => 'любопытство', 'mistake' => 'ошибка', 'benefit' => 'выгода', 'result' => 'результат',
    );
    const FORMATS = array(
        'review' => 'обзор', 'unboxing' => 'распаковка', 'demo' => 'демонстрация', 'before_after' => 'до/после', 'problem' => 'проблема/решение',
        'story' => 'история', 'lifehack' => 'лайфхак', 'experiment' => 'эксперимент', 'test' => 'тест', 'compare' => 'сравнение',
        'reaction' => 'реакция', 'error' => 'ошибка', 'anti' => 'антисовет', 'myth' => 'миф/разоблачение', 'top' => 'топ/подборка',
        'guide' => 'инструкция', 'faq' => 'FAQ', 'feedback' => 'отзыв', 'case' => 'кейс',
    );
    const FACTS_MAX = 3000;

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'vkt_shops', $message, array( 'status' => $status ) );
    }

    public static function options() {
        return array( 'hooks' => self::HOOKS, 'formats' => self::FORMATS );
    }

    /** Текст методики без заголовка и строки-описания. */
    public static function rules() {
        $raw = (string) @file_get_contents( VKT_DIR . 'prompts/vk-shops.md' );
        $lines = array_filter( explode( "\n", str_replace( "\r\n", "\n", $raw ) ), static fn( $line ) => ! str_starts_with( $line, '# ' ) && ! str_starts_with( $line, '> ' ) );
        return trim( implode( "\n", $lines ) );
    }

    /**
     * Задание на товарный пост из формы. Товар — свой из «Товаров» либо
     * название и ссылка, вписанные руками.
     */
    public static function brief( $data ) {
        global $wpdb;
        $data = is_array( $data ) ? $data : array();
        $title = '';
        $url = '';
        $product_id = absint( $data['product_id'] ?? 0 );
        if ( $product_id ) {
            $product = $wpdb->get_row( $wpdb->prepare( 'SELECT title,url FROM ' . VKT_Store::table( 'products' ) . ' WHERE id=%d AND user_id=%d', $product_id, VKT_Account::id() ), ARRAY_A );
            if ( ! $product ) {
                return self::error( 'Товар не найден в вашем списке.', 404 );
            }
            $title = (string) $product['title'];
            $url = (string) $product['url'];
        } else {
            $title = trim( (string) preg_replace( '/\s+/u', ' ', VKT_Materials::plain( $data['title'] ?? '' ) ) );
            $url = trim( (string) ( $data['url'] ?? '' ) );
        }
        if ( '' === $title || mb_strlen( $title ) > 255 ) {
            return self::error( 'Выберите товар из списка или впишите его название — до 255 символов.' );
        }
        if ( '' !== $url ) {
            $url = esc_url_raw( $url, array( 'http', 'https' ) );
            if ( '' === $url ) {
                return self::error( 'Ссылка на товар должна начинаться с https://.' );
            }
        }
        $facts = VKT_Materials::plain( $data['facts'] ?? '' );
        if ( mb_strlen( $facts ) > self::FACTS_MAX ) {
            return self::error( 'Факты о товаре — не длиннее ' . self::FACTS_MAX . ' символов.' );
        }
        return array(
            'title' => $title,
            'url' => $url,
            'facts' => $facts,
            'hook' => self::HOOKS[ $data['hook'] ?? '' ] ?? '',
            'format' => self::FORMATS[ $data['format'] ?? '' ] ?? '',
            'current' => mb_substr( VKT_Materials::plain( $data['current'] ?? '' ), 0, 16000 ),
        );
    }
}

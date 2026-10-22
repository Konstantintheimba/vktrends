<?php
// Товарный пост VK Shops в записи серии и общий список моделей картинок.
// Сеть, база и поставщики подменены.
define( 'ABSPATH', __DIR__ . '/' );
define( 'VKT_DIR', dirname( __DIR__ ) . '/' );
define( 'VKT_XAI_API_KEY', 'XAI_TEST_KEY' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url, $protocols = null ) { return preg_match( '~^https?://[^\s<>"]+$~i', (string) $url ) ? (string) $url : ''; }
function get_option( $key, $default = false ) { return $default; }
function wp_remote_retrieve_response_code( $response ) { return 200; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_post( $url, $args ) {
    $GLOBALS['vkt_model_request'] = json_decode( $args['body'], true );
    return array( 'body' => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $GLOBALS['vkt_model_text'] ) ) ) ) ) );
}
class VKT_Tokens { public static function unseal( $value ) { return ''; } }
class VKT_Account { public static function is_admin() { return true; } public static function id() { return 7; } }
class VKT_Store { public static function log() {} public static function table( $name ) { return 'wp_vkt_' . $name; } }
// База: товар 5 принадлежит кабинету 7, остальных нет.
$GLOBALS['wpdb'] = new class {
    public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%d', '%s', $sql ), $args ); }
    public function get_row( $sql ) { return str_contains( $sql, 'id=5 AND user_id=7' ) ? array( 'title' => 'Умная салфетка', 'url' => 'https://shop.test/p/5' ) : null; }
};
class VKT_Flux {
    const MAX_REFERENCES = 4;
    const MAX_INPUTS = 8;
    public static $ready = false;
    public static $started = array();
    public static function configured() { return self::$ready; }
    public static function models() { return array( 'flux-2-pro' => array( 'title' => 'FLUX.2 [pro]' ), 'flux-2-max' => array( 'title' => 'FLUX.2 [max]' ) ); }
    public static function start( $data, $max = 4 ) { self::$started = $data; return array( 'id' => 'task-12345678', 'status' => 'pending', 'model' => $data['model'] ); }
    public static function status( $id ) { return array( 'status' => 'done', 'media' => array( 'id' => 9 ), 'asked' => $id ); }
}

require dirname( __DIR__ ) . '/includes/class-materials.php';
require dirname( __DIR__ ) . '/includes/class-shops.php';
require dirname( __DIR__ ) . '/includes/class-ai.php';
require dirname( __DIR__ ) . '/includes/class-images.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

// Методика: отдельный файл, не материал группы.
$rules = VKT_Shops::rules();
$assert( str_contains( $rules, 'хук × подача' ) && ! str_contains( $rules, '# Методика VK Shops' ) && array() === VKT_Materials::library(), 'Методика VK Shops лежит в prompts и в базу ведения группы не предлагается' );
$assert( 8 === count( VKT_Shops::HOOKS ) && 19 === count( VKT_Shops::FORMATS ), 'Восемь хуков и девятнадцать подач' );

// Задание: товар из списка или вписанный руками.
$brief = VKT_Shops::brief( array( 'product_id' => 5, 'hook' => 'mistake', 'format' => 'compare', 'facts' => "Цена <500 руб\r\nПроверяли на плите", 'current' => 'Пост про уборку' ) );
$assert( ! is_wp_error( $brief ) && 'Умная салфетка' === $brief['title'] && 'https://shop.test/p/5' === $brief['url'] && 'ошибка' === $brief['hook'] && 'сравнение' === $brief['format'] && "Цена <500 руб\nПроверяли на плите" === $brief['facts'], 'Товар из списка: название и ссылка из базы, факты дословно' );
$assert( is_wp_error( VKT_Shops::brief( array( 'product_id' => 6 ) ) ), 'Чужой или несуществующий товар не берётся' );
$manual = VKT_Shops::brief( array( 'title' => " Щётка \n для окон ", 'url' => '', 'hook' => 'нет такого' ) );
$assert( 'Щётка для окон' === $manual['title'] && '' === $manual['url'] && '' === $manual['hook'], 'Товар руками: название в одну строку, неизвестный хук — на выбор модели' );
$assert( is_wp_error( VKT_Shops::brief( array( 'title' => '' ) ) ) && is_wp_error( VKT_Shops::brief( array( 'title' => 'Т', 'url' => 'javascript:1' ) ) ) && is_wp_error( VKT_Shops::brief( array( 'title' => 'Т', 'facts' => str_repeat( 'а', VKT_Shops::FACTS_MAX + 1 ) ) ) ), 'Без названия, с плохой ссылкой или слишком длинными фактами — отказ' );

// Запрос к модели и сборка текста.
$GLOBALS['vkt_model_text'] = "Муж опять тёр плиту губкой.\nСсылка: https://wrong.test/x";
$post = VKT_AI::generate_shop_post( $brief, '', 'Паспорт: на вы' );
$prompt = $GLOBALS['vkt_model_request']['messages'][0]['content'];
$assert( str_contains( $prompt, 'хук × подача' ) && str_contains( $prompt, 'Умная салфетка' ) && str_contains( $prompt, 'Цена <500 руб' ) && str_contains( $prompt, 'Хук: ошибка. Подача: сравнение.' ) && str_contains( $prompt, 'Пост про уборку' ) && str_contains( $prompt, 'Паспорт: на вы' ), 'В модель уходят методика, товар, факты, хук с подачей, текущий текст и паспорт группы' );
$assert( ! str_contains( $prompt, 'shop.test/p/5' ), 'Ссылку на товар модель не видит' );
$assert( "Муж опять тёр плиту губкой.\nСсылка:\n\nhttps://shop.test/p/5" === $post['text'], 'Чужой адрес из ответа вырезан, настоящая ссылка приписана в конец' );
$GLOBALS['vkt_model_text'] = 'Текст без ссылки';
$post = VKT_AI::generate_shop_post( $manual );
$prompt = $GLOBALS['vkt_model_request']['messages'][0]['content'];
$assert( 'Текст без ссылки' === $post['text'] && str_contains( $prompt, 'Ссылки на товар нет' ) && str_contains( $prompt, 'Ничего, кроме названия' ) && str_contains( $prompt, 'выбери сам' ) && ! str_contains( $prompt, 'Сейчас в этой записи' ), 'Без ссылки, фактов и текста модель предупреждена и ничего не приписывается' );

// Модели картинок: один список и один вход для всех поставщиков.
$assert( array( array( 'id' => 'xai', 'title' => 'xAI · ' . VKT_AI::IMAGE_MODEL, 'refs' => 0 ) ) === VKT_Images::providers(), 'Без ключа BFL в списке только xAI' );
VKT_Flux::$ready = true;
$ids = array_column( VKT_Images::providers(), 'id' );
$assert( array( 'xai', 'bfl:flux-2-pro', 'bfl:flux-2-max' ) === $ids && 'BFL · FLUX.2 [pro]' === VKT_Images::providers()[1]['title'], 'С ключом BFL модели FLUX появляются в общем списке' );
$started = VKT_Images::start( 'bfl:flux-2-max', 'Кухня', 'story' );
$assert( array( 'status' => 'pending', 'id' => 'bfl:task-12345678' ) === $started && 'flux-2-max' === VKT_Flux::$started['model'] && 768 === VKT_Flux::$started['width'] && 1344 === VKT_Flux::$started['height'], 'FLUX: задача поставлена с размерами формата, ID помнит поставщика' );
$done = VKT_Images::status( 'bfl:task-12345678' );
$assert( 'done' === $done['status'] && 'task-12345678' === $done['asked'], 'Статус спрашивается у того же поставщика' );
$assert( is_wp_error( VKT_Images::start( 'bfl:unknown', 'Кухня', 'story' ) ) && is_wp_error( VKT_Images::start( 'midjourney', 'Кухня', 'story' ) ) && is_wp_error( VKT_Images::status( 'xai:1' ) ) && is_wp_error( VKT_Images::status( 'nope' ) ), 'Неизвестная модель и чужая задача — отказ' );
$bad_ratio = VKT_Images::start( 'bfl:flux-2-pro', 'Кухня', 'panorama' );
$assert( 768 === VKT_Flux::$started['width'] && 1024 === VKT_Flux::$started['height'], 'Неизвестный формат заменяется вертикальным' );

// Персонаж группы: фото уходят образцом тому, кто их берёт, внешность и визуал — в начало промпта.
$persona = array( 'look' => 'женщина 34 лет, русые волосы до плеч', 'photos' => array( 11, 12, 13, 14, 15 ), 'items' => array(), 'visual' => 'фото на телефон, обычная квартира' );
VKT_Images::start( 'bfl:flux-2-pro', 'Маша выбирает шторы', 'story', $persona );
$prompt = VKT_Flux::$started['prompt'];
$assert( array( 11, 12, 13, 14, 15 ) === VKT_Flux::$started['images'], 'FLUX.2 pro получает все фото персонажа' );
$assert( str_starts_with( $prompt, 'Опорные фото по порядку: фото 1–5 — главный человек кадра' ) && str_contains( $prompt, 'тот же, что на опорных фото 1–5' ) && str_contains( $prompt, 'Внешность героя: женщина 34 лет' ) && str_contains( $prompt, 'Как снимает блог: фото на телефон' ) && str_contains( $prompt, 'других людей и животных' ) && str_ends_with( $prompt, "Сюжет:\nМаша выбирает шторы" ), 'Персонаж и визуал — до сюжета, фото пронумерованы' );
VKT_Images::start( 'bfl:flux-2-pro', 'Маша выбирает шторы', 'story' );
$assert( array() === VKT_Flux::$started['images'] && 'Маша выбирает шторы' === VKT_Flux::$started['prompt'], 'Без персонажа промпт и образцы не трогаются' );
$plain = VKT_Images::persona_prompt( 'Сюжет', array( 'look' => 'мужчина с бородой', 'photos' => array(), 'items' => array(), 'visual' => '' ) );
$assert( str_contains( $plain, 'всегда один и тот же. Его внешность: мужчина с бородой' ) && ! str_contains( $plain, 'опорных фото' ), 'Без фото (xAI) — только описание, про образцы ни слова' );

// Мир персонажа: в кадр идёт то, о чём сюжет; фото нумеруются, лицо первым.
$world = array( 'look' => '', 'photos' => array( 1, 2, 3 ), 'visual' => '', 'items' => array(
    array( 'title' => 'Кошка Муся', 'look' => 'серая британка с медными глазами', 'words' => 'кошк, Мус', 'photos' => array( 21, 22 ) ),
    array( 'title' => 'Квартира', 'look' => 'светлая гостиная, бежевый диван', 'words' => 'дома, квартир, кухн', 'photos' => array( 31 ) ),
    array( 'title' => 'Студия штор', 'look' => 'стеллажи с образцами тканей', 'words' => 'студи, работ', 'photos' => array( 41 ) ),
    array( 'title' => 'Часы', 'look' => 'золотые часы-браслет', 'words' => '', 'photos' => array() ),
) );
$plan = VKT_Images::persona_plan( 'Вечер дома, Муся спит на подоконнике', $world, 8 );
$assert( array( 1, 2, 3, 21, 31, 22 ) === $plan['refs'], 'Лицо, по фото на предмет, вторые фото — если есть место' );
$assert( str_contains( $plain = $plan['prompt'], 'фото 1–3 — главный человек кадра; фото 4, 6 — Кошка Муся; фото 5 — Квартира' ) && str_contains( $plain, '- Кошка Муся: серая британка' ) && str_contains( $plain, '- Часы: золотые' ) && ! str_contains( $plain, 'Студия штор' ), 'Предметы по словам сюжета, без слов — всегда; студии в кадре нет' );
$assert( array( 1, 2, 3, 41 ) === VKT_Images::persona_plan( 'Работа в студии', $world, 4 )['refs'], 'klein: четыре образца — лицо и студия' );
$assert( array() === VKT_Images::persona_plan( 'Вечер дома с Мусей', $world, 0 )['refs'] && str_contains( VKT_Images::persona_plan( 'Вечер дома с Мусей', $world, 0 )['prompt'], 'серая британка' ), 'xAI: без фото, но с описанием кошки' );
$ids = array_column( VKT_Images::providers(), 'refs', 'id' );
$assert( 0 === $ids['xai'] && 8 === $ids['bfl:flux-2-pro'], 'Реестр знает, сколько образцов берёт каждая модель' );
$assert( mb_strlen( VKT_Images::persona_prompt( str_repeat( 'а', 6000 ), $world, $world['items'], array( 'person' ) ) ) <= 5000, 'Промпт с персонажем не длиннее 5000 знаков' );

echo "PASS: $checks shops and images checks\n";

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
    public static $ready = false;
    public static $started = array();
    public static function configured() { return self::$ready; }
    public static function models() { return array( 'flux-2-pro' => array( 'title' => 'FLUX.2 [pro]' ), 'flux-2-max' => array( 'title' => 'FLUX.2 [max]' ) ); }
    public static function start( $data ) { self::$started = $data; return array( 'id' => 'task-12345678', 'status' => 'pending', 'model' => $data['model'] ); }
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
$assert( array( array( 'id' => 'xai', 'title' => 'xAI · ' . VKT_AI::IMAGE_MODEL ) ) === VKT_Images::providers(), 'Без ключа BFL в списке только xAI' );
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

echo "PASS: $checks shops and images checks\n";

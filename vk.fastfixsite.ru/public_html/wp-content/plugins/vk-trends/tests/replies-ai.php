<?php
// Генерация ответов на комментарии через xAI: что уходит в модель и как
// разбирается её ответ. Сеть подменена.
define( 'ABSPATH', __DIR__ . '/' );
define( 'VKT_XAI_API_KEY', 'XAI_TEST_KEY' );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_remote_retrieve_response_code( $response ) { return 200; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
// Модель отвечает тем, что лежит в $GLOBALS['vkt_model_text']; запрос сохраняется для проверки.
function wp_remote_post( $url, $args ) {
    $GLOBALS['vkt_model_request'] = json_decode( $args['body'], true );
    return array( 'body' => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $GLOBALS['vkt_model_text'] ) ) ) ) ) );
}
function get_option( $key, $default = false ) { return $default; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
class VKT_Tokens { public static function unseal( $value ) { return ''; } }
class VKT_Account { public static function is_admin() { return true; } }
class VKT_Store { public static function log() {} }
class VKT_Replies { const MAX_BATCH = 50; const BATCH_CEILING = 100; const MAX_LENGTH = 2000; }

require dirname( __DIR__ ) . '/includes/class-ai.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};
$items = array(
    array( 'author' => 'Анна', 'post' => 'Новая коллекция термосов', 'comment' => 'Сколько стоит синий?' ),
    array( 'author' => 'Олег', 'post' => 'Новая коллекция термосов', 'comment' => '' ),
    array( 'author' => 'Ира', 'post' => 'Новая коллекция термосов', 'comment' => 'Есть доставка?' ),
);

// Модель переставила ответы и завернула их в ```json.
$GLOBALS['vkt_model_text'] = "```json\n{\"replies\":[{\"id\":2,\"text\":\"Ира, доставка есть.\"},{\"id\":1,\"text\":\"Анна, цену напишем в сообщениях.\"}]}\n```";
$result = VKT_AI::generate_replies( 'Без смайликов', $items );
$prompt = $GLOBALS['vkt_model_request']['messages'][0]['content'];
$assert( str_contains( $prompt, 'Без смайликов' ) && str_contains( $prompt, 'Новая коллекция термосов' ), 'В модель уходят указания владельца и текст записи' );
$assert( ! str_contains( $prompt, '"author":"Олег"' ), 'Комментарий без текста в модель не уходит' );
$assert( 2 === count( $result['replies'] ), 'Ответ — только на комментарии с текстом' );
$assert( 0 === $result['replies'][0]['index'] && str_starts_with( $result['replies'][0]['text'], 'Анна' ), 'Ответ сопоставлен с комментарием по номеру, а не по порядку' );
$assert( 2 === $result['replies'][1]['index'] && str_starts_with( $result['replies'][1]['text'], 'Ира' ), 'Номер модели переведён обратно в позицию выбора с учётом пропуска' );

// Без номеров — по порядку; лишнее отбрасывается.
$GLOBALS['vkt_model_text'] = '["Первый","Второй","Лишний"]';
$result = VKT_AI::generate_replies( '', array( $items[0], $items[2] ) );
$assert( 'Первый' === $result['replies'][0]['text'] && 'Второй' === $result['replies'][1]['text'], 'Список без номеров разобран по порядку' );

// Пропущенный ответ остаётся пустым, а не сдвигает остальные.
$GLOBALS['vkt_model_text'] = '{"replies":[{"id":2,"text":"Только второй"}]}';
$result = VKT_AI::generate_replies( '', array( $items[0], $items[2] ) );
$assert( '' === $result['replies'][0]['text'] && 'Только второй' === $result['replies'][1]['text'], 'Пропуск модели не сдвигает ответы' );

$GLOBALS['vkt_model_text'] = 'Извините, не могу.';
$assert( is_wp_error( VKT_AI::generate_replies( '', $items ) ), 'Неразборчивый ответ модели — ошибка, а не пустые черновики' );
$assert( is_wp_error( VKT_AI::generate_replies( '', array( $items[1] ) ) ), 'Без текста комментариев модель не вызывается' );
$assert( is_wp_error( VKT_AI::generate_replies( str_repeat( 'а', 2001 ), $items ) ), 'Указания ограничены по длине' );

echo "All $checks offline reply generation checks passed.\n";

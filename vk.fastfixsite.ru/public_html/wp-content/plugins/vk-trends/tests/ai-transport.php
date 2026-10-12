<?php
// Связь с поставщиками: повтор при отказе соединения, понятная ошибка и
// картинки OpenAI через общий реестр. Сеть подменена.
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function wp_remote_retrieve_response_code( $response ) { return $response['code'] ?? 200; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
// Ответы сети идут по очереди из $GLOBALS['vkt_net']; запросы копятся для проверки.
function wp_remote_post( $url, $args ) {
    $GLOBALS['vkt_calls'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
    return array_shift( $GLOBALS['vkt_net'] );
}
$GLOBALS['options'] = array(
    'vkt_ai_keys' => array(
        'deepseek' => array( 'key' => 'SEALED:sk-deepseek-key', 'base' => 'https://api.deepseek.com' ),
        'openai' => array( 'key' => 'SEALED:sk-openai-key', 'base' => 'https://api.openai.com/v1', 'probe' => array( 'image' => array( 'dall-e-3', 'gpt-image-1', 'gpt-image-1-mini' ) ) ),
    ),
    'vkt_text_models' => array( 'default' => 'ds', 'models' => array( 'ds' => array( 'title' => 'Deepseek 4v Pro', 'preset' => 'deepseek', 'base' => 'https://api.deepseek.com', 'model' => 'deepseek-chat', 'key' => '' ) ) ),
);
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
class VKT_Tokens { public static function unseal( $value ) { return str_starts_with( (string) $value, 'SEALED:' ) ? substr( $value, 7 ) : ''; } }
class VKT_Account { public static function is_admin() { return true; } }
class VKT_Store {
    public static array $entries = array();
    public static function log( $method, $context, $status, $code, $message, $ms = 0 ) { self::$entries[] = compact( 'method', 'status', 'message' ); }
}
class VKT_Media {
    public static $stored = '';
    public static function store_image( $bytes ) { self::$stored = $bytes; return array( 'id' => 77 ); }
}
class VKT_Flux { public static function configured() { return false; } }

require dirname( __DIR__ ) . '/includes/class-ai.php';
require dirname( __DIR__ ) . '/includes/class-images.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};
$refused = new WP_Error( 'http_request_failed', "cURL error 7: Failed to connect to api.deepseek.com port 443 after 1011 ms: Couldn't connect to server" );
$answer = array( 'body' => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'Готовый пост' ) ) ) ) ) );

// Соединение не открылось один раз — второй заход проходит, пользователь ошибки не видит.
$GLOBALS['vkt_calls'] = array();
$GLOBALS['vkt_net'] = array( $refused, $answer );
$result = VKT_AI::generate_text( 'Пост о термосах' );
$assert( ! is_wp_error( $result ) && 'Готовый пост' === $result['text'], 'Отказ соединения повторяется, и текст приходит' );
$assert( 2 === count( $GLOBALS['vkt_calls'] ), 'Повтор — ровно один, пока не ответили' );

// Не открылось трижды — ошибка называет сеть, а не ключ, и ведёт в «Нейросети».
$GLOBALS['vkt_calls'] = array();
$GLOBALS['vkt_net'] = array( $refused, $refused, $refused, $answer );
$result = VKT_AI::generate_text( 'Пост о термосах' );
$assert( is_wp_error( $result ) && 3 === count( $GLOBALS['vkt_calls'] ), 'После трёх отказов попытки прекращаются' );
$assert( str_contains( $result->get_error_message(), 'api.deepseek.com' ) && str_contains( $result->get_error_message(), 'ключ и модель ни при чём' ) && str_contains( $result->get_error_message(), 'Адрес API' ), 'Ошибка объясняет, что запрос не дошёл' );
$assert( 'models' === $result->get_error_data()['fix']['view'] && $result->get_error_data()['retryable'], 'Ошибка повторяемая и ведёт к выбору модели' );
$assert( str_contains( end( VKT_Store::$entries )['message'], 'cURL error 7' ), 'В журнал попадает причина отказа' );

// Таймаут не повторяется: запрос мог дойти, второй был бы оплачен ещё раз.
$GLOBALS['vkt_calls'] = array();
$GLOBALS['vkt_net'] = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 90001 milliseconds with 0 bytes received' ), $answer );
$result = VKT_AI::generate_text( 'Пост о термосах' );
$assert( is_wp_error( $result ) && 1 === count( $GLOBALS['vkt_calls'] ), 'Таймаут не повторяется' );
$assert( ! str_contains( $result->get_error_message(), 'ни при чём' ), 'Про недошедший запрос при таймауте не говорим' );

// Таймаут на этапе соединения — тот же недошедший запрос: повторяем.
$GLOBALS['vkt_calls'] = array();
$GLOBALS['vkt_net'] = array( new WP_Error( 'http_request_failed', 'cURL error 28: Failed to connect to api.deepseek.com port 443 after 10002 ms: Timeout was reached' ), $answer );
$result = VKT_AI::generate_text( 'Пост о термосах' );
$assert( ! is_wp_error( $result ) && 2 === count( $GLOBALS['vkt_calls'] ), 'Таймаут соединения повторяется' );

// Картинки OpenAI: в списке только gpt-image из проверки ключа.
$ids = array_column( VKT_Images::providers(), 'id' );
$assert( array( 'openai:gpt-image-1', 'openai:gpt-image-1-mini' ) === $ids, 'В реестре картинок — модели gpt-image, без dall-e' );
$GLOBALS['vkt_calls'] = array();
$GLOBALS['vkt_net'] = array( array( 'body' => json_encode( array( 'data' => array( array( 'b64_json' => base64_encode( 'JPEGDATA' ) ) ) ) ) ) );
$result = VKT_Images::start( 'openai:gpt-image-1', 'Термос на столе', 'landscape' );
$call = $GLOBALS['vkt_calls'][0];
$assert( 'done' === $result['status'] && 77 === $result['media']['id'] && 'JPEGDATA' === VKT_Media::$stored, 'Картинка из ответа сохранена в медиатеку' );
$assert( 'https://api.openai.com/v1/images/generations' === $call['url'] && 'gpt-image-1' === $call['body']['model'] && '1536x1024' === $call['body']['size'], 'Запрос уходит на адрес поставщика с выбранной моделью и размером' );

// Отказ по стране сервера приходит словами OpenAI, ключ в текст не попадает.
$GLOBALS['vkt_net'] = array( array( 'code' => 403, 'body' => json_encode( array( 'error' => array( 'message' => 'Country, region, or territory not supported sk-openai-key' ) ) ) ) );
$result = VKT_Images::start( 'openai:gpt-image-1', 'Термос на столе', 'square' );
$assert( is_wp_error( $result ) && str_contains( $result->get_error_message(), 'HTTP 403' ) && ! str_contains( $result->get_error_message(), 'sk-openai-key' ), 'Отказ OpenAI показан без ключа' );
$assert( is_wp_error( VKT_Images::start( 'openai:dall-e-3', 'Термос на столе', 'square' ) ), 'Модель не из списка не запускается' );

// Ключ удалили — модели картинок пропадают из списка.
unset( $GLOBALS['options']['vkt_ai_keys']['openai']['key'] );
$assert( array() === VKT_Images::providers(), 'Без ключа OpenAI картинок в списке нет' );

// Ключ, вписанный прямо в модель, поднимается в карточку поставщика, модель переходит на общий ключ.
function update_option( $key, $value, $autoload = true ) { $GLOBALS['options'][ $key ] = $value; return true; }
$GLOBALS['options']['vkt_text_models']['models']['or'] = array( 'title' => 'OpenRouter', 'preset' => 'openrouter', 'base' => 'https://openrouter.ai/api/v1', 'model' => 'deepseek/deepseek-chat', 'key' => 'SEALED:sk-or-own-key', 'created_at' => '2026-10-01 10:00:00' );
$card = array_values( array_filter( VKT_AI::providers(), static fn( $provider ) => 'openrouter' === $provider['id'] ) )[0];
$assert( $card['connected'] && 'sk-or-…-key' === $card['preview'] && '2026-10-01 10:00:00' === $card['saved_at'], 'Ключ из модели виден в карточке поставщика' );
$assert( '' === $GLOBALS['options']['vkt_text_models']['models']['or']['key'] && 'SEALED:sk-or-own-key' === $GLOBALS['options']['vkt_ai_keys']['openrouter']['key'], 'Модель переведена на общий ключ' );
$listed = array_values( array_filter( VKT_AI::text_models(), static fn( $model ) => 'or' === $model['id'] ) );
$assert( 1 === count( $listed ) && $listed[0]['shared'], 'Модель продолжает работать' );
// Удалённый ключ поставщика из оставшихся моделей заново не поднимается.
$GLOBALS['options']['vkt_text_models']['models']['or2'] = array( 'title' => 'Вторая', 'preset' => 'openrouter', 'base' => 'https://openrouter.ai/api/v1', 'model' => 'x/y', 'key' => 'SEALED:sk-or-other-key' );
VKT_AI::delete_key( 'openrouter' );
$card = array_values( array_filter( VKT_AI::providers(), static fn( $provider ) => 'openrouter' === $provider['id'] ) )[0];
$assert( ! $card['connected'] && 'SEALED:sk-or-other-key' === $GLOBALS['options']['vkt_text_models']['models']['or2']['key'], 'После удаления ключа поставщик остаётся отключённым, свой ключ модели цел' );

// Проверка связи: список — VK и все поставщики с адресом, без «своего» без адреса.
$reach = VKT_AI::reach();
$titles = array_column( $reach['rows'], 'title' );
$assert( in_array( 'DeepSeek', $titles, true ) && in_array( 'VK (для сравнения)', $titles, true ) && ! in_array( 'Свой, OpenAI-совместимый', $titles, true ), 'Проверка связи обходит VK и поставщиков с адресом' );

echo "All $checks AI transport checks passed.\n";

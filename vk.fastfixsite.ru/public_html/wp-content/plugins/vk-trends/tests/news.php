<?php
// Новостная группа: настройки, разбор RSS и Atom, поиск ленты на странице,
// отбор свежего, запрос к модели и сборка записей с источником.
// Сеть подменена: источники и модель отвечают из $GLOBALS.
define( 'ABSPATH', __DIR__ . '/' );
define( 'VKT_DIR', dirname( __DIR__ ) . '/' );
define( 'VKT_VERSION', 'test' );
define( 'VKT_XAI_API_KEY', 'XAI_TEST_KEY' );
define( 'DAY_IN_SECONDS', 86400 );

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
function wp_http_validate_url( $url ) { $host = (string) parse_url( $url, PHP_URL_HOST ); return str_contains( $host, '.' ) && ! preg_match( '/^(127\.|10\.|192\.168\.)/', $host ) ? $url : false; }
function wp_date( $format, $stamp ) { return gmdate( $format, $stamp ); }
function home_url( $path = '' ) { return 'https://site.test' . $path; }
function get_option( $key, $default = false ) { return $GLOBALS['vkt_options'][ $key ] ?? $default; }
function add_query_arg( $args, $url ) { return $url . '?' . implode( '&', array_map( static fn( $key, $value ) => $key . '=' . $value, array_keys( $args ), $args ) ); }
function wp_parse_str( $string, &$result ) { parse_str( (string) $string, $result ); }
function wp_remote_retrieve_response_code( $response ) { return $response['code'] ?? 200; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_safe_remote_get( $url, $args = array() ) {
    $GLOBALS['vkt_fetched'][] = $url;
    $page = $GLOBALS['vkt_pages'][ $url ] ?? null;
    foreach ( $GLOBALS['vkt_pages_prefix'] ?? array() as $prefix => $body ) {
        if ( null === $page && str_starts_with( $url, $prefix ) ) { $page = $body; }
    }
    return null === $page ? new WP_Error( 'http', 'cURL error 28: timeout' ) : ( is_array( $page ) ? $page : array( 'body' => $page ) );
}
function wp_safe_remote_head( $url, $args = array() ) {
    $GLOBALS['vkt_checked'][] = $url;
    return in_array( $url, $GLOBALS['vkt_alive'] ?? array(), true ) ? array( 'code' => 200, 'body' => '' ) : array( 'code' => 404, 'body' => '' );
}
function wp_remote_post( $url, $args ) {
    $GLOBALS['vkt_post_url'] = $url;
    if ( str_ends_with( $url, '/v1/responses' ) ) {
        $GLOBALS['vkt_search_request'] = json_decode( $args['body'], true );
        return array( 'code' => $GLOBALS['vkt_search_code'] ?? 200, 'body' => json_encode( $GLOBALS['vkt_search_response'] ) );
    }
    $GLOBALS['vkt_model_request'] = json_decode( $args['body'], true );
    return array( 'body' => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $GLOBALS['vkt_model_text'] ) ) ) ) ) );
}
class VKT_Tokens { public static function unseal( $value ) { return $value; } }
class VKT_Account { public static function is_admin() { return true; } }
class VKT_Store { public static $log = array(); public static function log( ...$entry ) { self::$log[] = $entry; } }

require dirname( __DIR__ ) . '/includes/class-materials.php';
require dirname( __DIR__ ) . '/includes/class-images.php';
require dirname( __DIR__ ) . '/includes/class-cover.php';
require dirname( __DIR__ ) . '/includes/class-news.php';
require dirname( __DIR__ ) . '/includes/class-ai.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};
$now = time();
$rfc = static fn( $ago ) => gmdate( 'D, d M Y H:i:s', $now - $ago ) . ' +0000';

// Настройки: значения по умолчанию, проверка адресов и пределов.
$defaults = VKT_News::settings( 'мусор' );
$assert( ! $defaults['enabled'] && 10 === $defaults['count'] && 1 === $defaults['days'] && 'posts' === $defaults['mode'] && array() === $defaults['sources'], 'Без настроек группа не новостная, значения по умолчанию разумны' );
$saved = VKT_News::clean( array( 'enabled' => true, 'sources' => "sport.test/rss\nhttps://news.test/feed.xml\n\nsport.test/rss", 'topic' => " Только <НБА>, счёт <100 не брать ", 'count' => 15, 'days' => 3, 'mode' => 'digest' ), array( 'used' => array( 'abc' ) ) );
$assert( ! is_wp_error( $saved ) && array( 'https://sport.test/rss', 'https://news.test/feed.xml' ) === $saved['sources'], 'Адрес без https:// дополняется, повторы убираются' );
$assert( 'Только <НБА>, счёт <100 не брать' === $saved['topic'] && 15 === $saved['count'] && 3 === $saved['days'] && 'digest' === $saved['mode'] && array( 'abc' ) === $saved['used'], 'Выборка сохранена дословно, использованные новости не теряются при сохранении' );
$assert( 10 === VKT_News::clean( array( 'sources' => 'a.test', 'count' => 7, 'days' => 99, 'mode' => 'x' ), array() )['count'], 'Число вне списка заменяется значением по умолчанию' );
$assert( is_wp_error( VKT_News::clean( array( 'enabled' => true, 'sources' => '' ), array() ) ), 'Новостная группа без источников не сохраняется' );
$assert( is_wp_error( VKT_News::clean( array( 'sources' => 'http://127.0.0.1/rss' ), array() ) ) && is_wp_error( VKT_News::clean( array( 'sources' => 'javascript:alert(1)' ), array() ) ), 'Внутренний адрес и не-ссылка отклоняются' );
$assert( is_wp_error( VKT_News::clean( array( 'sources' => implode( "\n", array_map( static fn( $i ) => "s$i.test", range( 1, VKT_News::MAX_SOURCES + 1 ) ) ) ), array() ) ), 'Источников не больше предела' );

// RSS 2.0: теги и сущности в анонсе убраны, относительная ссылка достроена.
$rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Спорт</title>'
    . '<item><title>Клуб &amp; тренер продлили контракт</title><link>https://sport.test/a1</link><pubDate>' . $rfc( 3600 ) . '</pubDate><description><![CDATA[<p>Контракт <b>до 2028</b> года.&nbsp;Подробности позже.</p>]]></description></item>'
    . '<item><title>Старая новость</title><link>/old</link><pubDate>' . $rfc( 5 * 86400 ) . '</pubDate><description>Давно</description></item>'
    . '<item><title>Без даты</title><link>https://sport.test/nodate?utm_source=rss&amp;id=7&amp;utm_campaign=x</link></item>'
    . '<item><title></title><link>https://sport.test/empty</link></item>'
    . '</channel></rss>';
$items = VKT_News::parse( $rss, 'https://www.sport.test/rss' );
$assert( 3 === count( $items ) && 'Клуб & тренер продлили контракт' === $items[0]['title'] && 'Контракт до 2028 года. Подробности позже.' === $items[0]['summary'] && 'sport.test' === $items[0]['source'], 'RSS: заголовок и анонс очищены, запись без заголовка пропущена' );
$assert( 'https://www.sport.test/old' === $items[1]['link'] && null === $items[2]['date'] && abs( $items[0]['date'] - ( $now - 3600 ) ) < 2, 'RSS: относительная ссылка достроена, дата разобрана, её отсутствие — null' );
$assert( 'https://sport.test/nodate?id=7' === $items[2]['link'], 'Метки utm_ из ссылки на источник убраны, остальные параметры на месте' );

// Atom и RSS 1.0 (RDF).
$atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Лента</title>'
    . '<entry><title>Обмен игроков</title><link rel="self" href="https://news.test/api/1"/><link rel="alternate" href="https://news.test/trade"/><updated>' . gmdate( 'c', $now - 7200 ) . '</updated><summary>Команды обменялись защитниками.</summary></entry></feed>';
$entries = VKT_News::parse( $atom, 'https://news.test/feed.xml' );
$assert( 1 === count( $entries ) && 'https://news.test/trade' === $entries[0]['link'] && 'Команды обменялись защитниками.' === $entries[0]['summary'] && abs( $entries[0]['date'] - ( $now - 7200 ) ) < 2, 'Atom: берётся ссылка alternate, дата и анонс' );
$rdf = '<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>Л</title></channel>'
    . '<item><title>Запись RDF</title><link>https://rdf.test/1</link><dc:date>' . gmdate( 'c', $now - 60 ) . '</dc:date><description>Текст</description></item></rdf:RDF>';
$rdf_items = VKT_News::parse( $rdf, 'https://rdf.test/rss' );
$assert( 1 === count( (array) $rdf_items ) && 'Запись RDF' === $rdf_items[0]['title'] && null !== $rdf_items[0]['date'], 'RSS 1.0 (RDF): записи и дата dc:date читаются' );
$assert( null === VKT_News::parse( '<html><body>не лента</body></html>', 'https://a.test' ) && null === VKT_News::parse( '<rss><channel><item>', 'https://a.test' ) && null === VKT_News::parse( '', 'https://a.test' ), 'HTML, битый XML и пустой ответ — не лента' );

// Чужой XML не подтягивает внешние сущности.
$xxe = '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><item><title>T &x;</title><link>https://a.test/1</link></item></channel></rss>';
$parsed = VKT_News::parse( $xxe, 'https://a.test/rss' );
$assert( ! str_contains( wp_json_encode( $parsed ), 'root:' ), 'Внешняя сущность XML не раскрывается' );

// Лента, объявленная на странице сайта.
$assert( 'https://blog.test/feed/' === VKT_News::discover( '<head><link rel="stylesheet" href="/s.css"><link rel="alternate" type="application/rss+xml" title="RSS" href="/feed/"></head>', 'https://blog.test/news/' ), 'Адрес ленты найден в <head> и достроен от корня' );
$assert( 'https://blog.test/news/atom.xml' === VKT_News::discover( "<link href='atom.xml' type='application/atom+xml'>", 'https://blog.test/news/index.html' ) && '' === VKT_News::discover( '<link rel="icon" href="/f.ico">', 'https://blog.test' ), 'Относительный адрес ленты и её отсутствие' );

// Сбор: свежесть, повторы между источниками, использованное, недоступный сайт, поиск ленты.
$GLOBALS['vkt_pages'] = array(
    'https://sport.test/rss' => $rss,
    'https://news.test/feed.xml' => $atom,
    'https://blog.test' => '<html><head><link rel="alternate" type="application/rss+xml" href="/feed/"></head></html>',
    'https://blog.test/feed/' => '<rss><channel><item><title>Повтор обмена</title><link>https://news.test/trade</link><pubDate>' . $rfc( 100 ) . '</pubDate></item><item><title>Уже брали</title><link>https://blog.test/used</link><pubDate>' . $rfc( 50 ) . '</pubDate></item></channel></rss>',
    'https://closed.test/rss' => array( 'code' => 403, 'body' => 'denied' ),
    'https://plain.test' => '<html><head><title>Без ленты</title></head></html>',
);
$settings = VKT_News::settings( array( 'enabled' => true, 'days' => 1, 'used' => array( VKT_News::key( 'https://blog.test/used' ) ), 'sources' => array( 'https://sport.test/rss', 'https://news.test/feed.xml', 'https://blog.test', 'https://closed.test/rss', 'https://down.test/rss', 'https://plain.test' ) ) );
$collected = VKT_News::collect( $settings );
$titles = array_column( $collected['items'], 'title' );
$assert( array( 'Клуб & тренер продлили контракт', 'Обмен игроков', 'Без даты' ) === $titles, 'Собрано свежее без повторов и использованного, новые сверху, запись без даты — в конце' );
$report = array_column( $collected['sources'], null, 'url' );
$assert( $report['https://sport.test/rss']['ok'] && 3 === $report['https://sport.test/rss']['total'] && 2 === $report['https://sport.test/rss']['fresh'], 'По источнику видно, сколько в ленте и сколько свежего' );
$assert( $report['https://blog.test']['ok'] && 0 === $report['https://blog.test']['fresh'] && in_array( 'https://blog.test/feed/', $GLOBALS['vkt_fetched'], true ), 'Адрес сайта: лента найдена на странице и прочитана' );
$assert( ! $report['https://closed.test/rss']['ok'] && str_contains( $report['https://closed.test/rss']['message'], '403' ) && str_contains( $report['https://down.test/rss']['message'], 'не ответил' ) && str_contains( $report['https://plain.test']['message'], 'нет RSS-ленты' ), 'Отказ, недоступность и сайт без ленты объяснены словами' );
$assert( 6 === count( VKT_Store::$log ), 'Каждое чтение источника записано в журнал' );
$week = VKT_News::collect( array_merge( $settings, array( 'days' => 7 ) ) );
$assert( in_array( 'Старая новость', array_column( $week['items'], 'title' ), true ), 'Срок «неделя» возвращает новость пятидневной давности' );

// Модель: что уходит и как разбирается ответ.
$GLOBALS['vkt_model_text'] = "```json\n{\"intro\":\"Главное за день\",\"news\":[{\"id\":2,\"text\":\"Обмен состоялся. Источник: https://fake.test/x\"},{\"id\":9,\"text\":\"Чужой номер\"},{\"id\":2,\"text\":\"Дубль\"},{\"id\":1,\"text\":\"<b>Контракт</b> продлён.\"}]}\n```";
$answer = VKT_AI::generate_news( $collected['items'], 'Только НБА', 5, 'posts', '', 'Паспорт: на вы' );
$prompt = $GLOBALS['vkt_model_request']['messages'][0]['content'];
$assert( str_contains( $prompt, 'Только НБА' ) && str_contains( $prompt, 'Обмен игроков' ) && str_contains( $prompt, 'Паспорт: на вы' ) && str_contains( $prompt, 'не больше 5' ), 'В модель уходят выборка, новости, число и контекст группы' );
$assert( ! str_contains( $prompt, 'https://news.test/trade' ) && ! str_contains( $prompt, 'sport.test/a1' ), 'Ссылок на новости модель не видит' );
$assert( 2 === count( $answer['picks'] ) && 2 === $answer['picks'][0]['id'] && 'Обмен состоялся.' === $answer['picks'][0]['text'] && 'Контракт продлён.' === $answer['picks'][1]['text'], 'Чужой номер и дубль отброшены, выдуманная ссылка и теги вырезаны, порядок модели сохранён' );
$limited = VKT_AI::generate_news( $collected['items'], '', 1, 'posts' );
$assert( 1 === count( $limited['picks'] ) && str_contains( $GLOBALS['vkt_model_request']['messages'][0]['content'], 'владелец не уточнил' ), 'Модель не может вернуть больше заказанного; пустая выборка оговорена' );
$GLOBALS['vkt_model_text'] = 'Извините, не могу.';
$assert( is_wp_error( VKT_AI::generate_news( $collected['items'], '', 5, 'posts' ) ), 'Ответ без JSON — ошибка, а не пустой результат' );

// Сборка: источник приписывает плагин.
$posts = VKT_News::compose( $collected['items'], $answer, 'posts' );
$assert( 2 === count( $posts ) && "Обмен состоялся.\n\nИсточник: https://news.test/trade" === $posts[0]['text'] && 'Обмен игроков' === $posts[0]['title'] && array( 'https://sport.test/a1' ) === $posts[1]['links'], 'Отдельные посты: к каждому приписана настоящая ссылка' );
$digest = VKT_News::compose( $collected['items'], $answer, 'digest' );
$assert( 1 === count( $digest ) && str_starts_with( $digest[0]['text'], "Главное за день\n\n— Обмен состоялся.\nИсточник: https://news.test/trade\n\n— Контракт продлён." ) && 2 === count( $digest[0]['links'] ) && 'news.test, sport.test' === $digest[0]['source'], 'Дайджест: вводная строка, пункты и источник у каждого' );
$assert( array() === VKT_News::compose( $collected['items'], array( 'picks' => array() ), 'digest' ), 'Пустой отбор — пустой результат' );

// Поиск в интернете: запрос к xAI, сверка ссылок с источниками поиска и живая проверка остальных.
$assert( 'search' === VKT_News::settings( array() )['method'] && is_wp_error( VKT_News::clean( array( 'enabled' => true, 'method' => 'search', 'topic' => '' ), array() ) ) && ! is_wp_error( VKT_News::clean( array( 'enabled' => true, 'method' => 'search', 'topic' => 'НБА' ), array() ) ), 'По умолчанию — поиск: ему нужна выборка, а источники необязательны' );
$assert( array( 'feed', 'xai' ) === array_column( VKT_AI::search_providers(), 'id' ) && 'xAI Grok · web_search' === VKT_AI::search_providers()[1]['title'], 'По умолчанию ищет плагин — ему не нужен ключ; xAI можно выбрать' );
$found_json = json_encode( array( 'news' => array(
    array( 'title' => 'Обмен в НБА', 'summary' => 'Клубы обменялись <b>защитниками</b>.', 'url' => 'https://www.kp.ru/sport/trade/?utm_source=x', 'date' => gmdate( 'Y-m-d' ) ),
    array( 'title' => 'Живая без цитаты', 'summary' => 'Факт.', 'url' => 'https://tass.ru/sport/1', 'date' => '' ),
    array( 'title' => 'Выдуманная', 'summary' => 'Факт.', 'url' => 'https://fake-site.test/news/42', 'date' => '' ),
    array( 'title' => 'Уже брали', 'summary' => 'Факт.', 'url' => 'https://kp.ru/used', 'date' => '' ),
    array( 'title' => 'Не ссылка', 'summary' => 'Факт.', 'url' => 'нет адреса', 'date' => '' ),
) ), JSON_UNESCAPED_UNICODE );
$GLOBALS['vkt_search_response'] = array( 'output' => array(
    array( 'type' => 'web_search_call' ),
    array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => "Вот что нашлось:\n" . $found_json . "\nГотово.", 'annotations' => array( array( 'type' => 'url_citation', 'url' => 'https://kp.ru/sport/trade' ), array( 'type' => 'url_citation', 'url' => 'https://kp.ru/used' ) ) ) ) ),
) );
$GLOBALS['vkt_alive'] = array( 'https://tass.ru/sport/1' );
$GLOBALS['vkt_checked'] = array();
$search_settings = VKT_News::settings( array( 'enabled' => true, 'method' => 'search', 'engine' => 'xai', 'topic' => 'Только НБА', 'count' => 5, 'days' => 3, 'sources' => array( 'https://www.kp.ru/', 'https://tass.ru' ), 'used' => array( VKT_News::key( 'https://kp.ru/used' ) ) ) );
$searched = VKT_News::search( $search_settings, 'Баскетбол' );
$request = $GLOBALS['vkt_search_request'];
$assert( VKT_AI::SEARCH_MODEL === $request['model'] && 'web_search' === $request['tools'][0]['type'] && array( 'kp.ru', 'tass.ru' ) === $request['tools'][0]['filters']['allowed_domains'], 'Запрос к xAI: инструмент web_search и сайты группы фильтром' );
$assert( str_contains( $request['input'][0]['content'], 'Только НБА' ) && str_contains( $request['input'][0]['content'], 'за последние 3 сут' ) && str_contains( $request['input'][0]['content'], 'Баскетбол' ) && str_contains( $request['input'][0]['content'], 'До 10 ' ), 'В поиск уходят выборка, срок, группа и запас по числу' );
$assert( array( 'Обмен в НБА', 'Живая без цитаты' ) === array_column( $searched['items'], 'title' ), 'Остались новости с подтверждённой ссылкой: названная поиском и открывающаяся' );
$assert( 'https://www.kp.ru/sport/trade/' === $searched['items'][0]['link'] && 'kp.ru' === $searched['items'][0]['source'] && 'Клубы обменялись защитниками.' === $searched['items'][0]['summary'] && null === $searched['items'][1]['date'], 'Ссылка без utm-меток, источник — домен, теги из пересказа убраны' );
$assert( array( 'https://tass.ru/sport/1', 'https://fake-site.test/news/42' ) === $GLOBALS['vkt_checked'], 'Живьём проверяются только ссылки, которых поиск источниками не назвал' );
$assert( str_contains( $searched['sources'][0]['message'], 'отброшено без подтверждённой ссылки: 1' ) && 5 === $searched['sources'][0]['total'] && 2 === $searched['sources'][0]['fresh'] && str_contains( $searched['sources'][0]['url'], 'xAI Grok' ), 'В отчёте видно, кто искал и сколько отброшено' );
$many_sites = VKT_News::settings( array( 'method' => 'search', 'engine' => 'xai', 'topic' => 'Т', 'sources' => array_map( static fn( $i ) => "https://s$i.test", range( 1, 7 ) ) ) );
VKT_News::search( $many_sites );
$assert( ! isset( $GLOBALS['vkt_search_request']['tools'][0]['filters'] ), 'Больше пяти сайтов — поиск без фильтра: xAI принимает не больше пяти' );
$GLOBALS['vkt_search_response'] = array( 'output_text' => 'Ничего не нашёл.' );
$assert( is_wp_error( VKT_News::search( $search_settings ) ), 'Ответ поиска без списка — ошибка' );
$GLOBALS['vkt_search_response'] = array( 'error' => array( 'message' => 'model not found' ) );
$GLOBALS['vkt_search_code'] = 404;
$denied = VKT_News::search( $search_settings );
$assert( is_wp_error( $denied ) && str_contains( $denied->get_error_message(), 'model not found' ) && str_contains( $denied->get_error_message(), '404' ), 'Отказ xAI показан дословно' );

// Выбор поиска: кто ищет, задаётся в настройках новостей, а не моделью для текста.
$GLOBALS['vkt_search_code'] = 403;
$GLOBALS['vkt_search_response'] = 'This service is not available in your region.';
$region = VKT_News::search( $search_settings );
$assert( is_wp_error( $region ) && str_contains( $region->get_error_message(), '«Кто ищет»' ) && str_contains( $region->get_error_message(), 'Поиск плагина' ), 'Отказ по региону подсказывает, где сменить поиск и на какой' );
$GLOBALS['vkt_options'][ VKT_AI::MODELS_OPTION ] = array( 'models' => array(
    'qw' => array( 'title' => 'Qwen Plus', 'preset' => 'qwen', 'base' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'model' => 'qwen-plus', 'key' => 'QWEN_KEY' ),
    'ds' => array( 'title' => 'DeepSeek', 'preset' => 'deepseek', 'base' => 'https://api.deepseek.com', 'model' => 'deepseek-chat', 'key' => 'DS_KEY' ),
    'or' => array( 'title' => 'OR DeepSeek', 'preset' => 'openrouter', 'base' => 'https://openrouter.ai/api/v1', 'model' => 'deepseek/deepseek-chat', 'key' => 'OR_KEY' ),
) );
$assert( array( 'feed', 'xai', 'qwen-qw', 'openrouter-or' ) === array_column( VKT_AI::search_providers(), 'id' ), 'Сами ищут xAI, Qwen и OpenRouter; прямой DeepSeek — через поиск плагина' );
$GLOBALS['vkt_model_text'] = $found_json;
$by_qwen = VKT_News::search( VKT_News::clean( array( 'enabled' => true, 'method' => 'search', 'engine' => 'qwen-qw', 'topic' => 'Только НБА', 'sources' => array( 'https://www.kp.ru/', 'https://tass.ru' ) ), array() ) );
$assert( ! is_wp_error( $by_qwen ) && str_starts_with( $GLOBALS['vkt_post_url'], 'https://dashscope-intl.aliyuncs.com/' ) && true === $GLOBALS['vkt_model_request']['enable_search'] && 'qwen-plus' === $GLOBALS['vkt_model_request']['model'], 'Выбранный Qwen ищет сам, xAI не трогается' );
VKT_News::search( VKT_News::settings( array( 'method' => 'search', 'engine' => 'openrouter-or', 'topic' => 'Т', 'sources' => array( 'https://tass.ru' ) ) ) );
$assert( str_starts_with( $GLOBALS['vkt_post_url'], 'https://openrouter.ai/' ) && array( 'tass.ru' ) === $GLOBALS['vkt_model_request']['plugins'][0]['include_domains'], 'Выбранный OpenRouter ищет плагином web по названным сайтам' );
$assert( 'feed' === VKT_AI::search_kind( 'gone-1' ) && 'feed' === VKT_AI::search_kind( '' ) && 'xai' === VKT_AI::search_kind( 'xai' ) && '' === VKT_News::settings( array( 'engine' => 'Не ID!' ) )['engine'], 'Отключённый или не выбранный поиск — это поиск плагина; мусор в настройке не хранится' );

// Поиск плагина: запросы составляет модель для текста (DeepSeek), выдачу читает плагин.
$feed_item = static fn( $title, $url, $ago ) => '<item><title>' . $title . '</title><link>http://www.bing.com/news/apiclick.aspx?ref=FexRss&amp;url=' . rawurlencode( $url ) . '&amp;c=1&amp;mkt=ru-ru</link><description>Анонс ' . $title . '</description><pubDate>' . gmdate( 'r', time() - $ago ) . '</pubDate></item>';
$feed = static fn( ...$items ) => '<?xml version="1.0" encoding="utf-8" ?><rss version="2.0"><channel><title>Bing</title>' . implode( '', $items ) . '</channel></rss>';
$bing = static fn( $line ) => add_query_arg( array( 'q' => rawurlencode( $line ), 'qft' => rawurlencode( 'interval="8"' ), 'format' => 'rss', 'setlang' => 'ru', 'cc' => 'RU' ), 'https://www.bing.com/news/search' );
$GLOBALS['vkt_pages'] = array(
    $bing( 'НБА обмены site:kp.ru' ) => $feed( $feed_item( 'Обмен в НБА', 'https://www.kp.ru/sport/trade/?utm_source=bing', 3600 ), $feed_item( 'Старая', 'https://www.kp.ru/sport/old/', 5 * 86400 ) ),
    $bing( 'НБА травмы site:kp.ru' ) => $feed( $feed_item( 'Обмен в НБА', 'https://www.kp.ru/sport/trade/', 3600 ), $feed_item( 'Травма лидера', 'https://www.kp.ru/sport/injury/', 7200 ), $feed_item( 'Уже брали', 'https://www.kp.ru/sport/used/', 60 ) ),
);
$GLOBALS['vkt_fetched'] = array();
$GLOBALS['vkt_model_text'] = '{"queries":["НБА обмены","НБА травмы","нба ОБМЕНЫ"]}';
$feed_settings = VKT_News::settings( array( 'enabled' => true, 'method' => 'search', 'engine' => 'feed', 'topic' => 'Только НБА: обмены и травмы, без слухов', 'count' => 5, 'days' => 3, 'sources' => array( 'https://www.kp.ru/' ), 'used' => array( VKT_News::key( 'https://www.kp.ru/sport/used/' ) ) ) );
$by_feed = VKT_News::search( $feed_settings, 'Баскетбол', 'ds' );
$assert( 'deepseek-chat' === $GLOBALS['vkt_model_request']['model'] && str_contains( $GLOBALS['vkt_model_request']['messages'][0]['content'] ?? json_encode( $GLOBALS['vkt_model_request'], JSON_UNESCAPED_UNICODE ), 'без слухов' ), 'Запросы для поиска составляет выбранная модель для текста — DeepSeek' );
$assert( array( $bing( 'НБА обмены site:kp.ru' ), $bing( 'НБА травмы site:kp.ru' ) ) === $GLOBALS['vkt_fetched'], 'Повтор запроса убран, сайт группы ушёл в запрос оператором site:' );
$assert( ! is_wp_error( $by_feed ) && array( 'Обмен в НБА', 'Травма лидера' ) === array_column( $by_feed['items'], 'title' ) && 'https://www.kp.ru/sport/trade/' === $by_feed['items'][0]['link'] && 'kp.ru' === $by_feed['items'][0]['source'], 'Из выдачи взяты свежие и неиспользованные, без повторов; адрес статьи — настоящий, без счётчика поисковика' );
$assert( 5 === $by_feed['sources'][0]['total'] && 2 === $by_feed['sources'][0]['fresh'] && str_contains( $by_feed['sources'][0]['url'], 'НБА обмены; НБА травмы' ), 'В отчёте видно, какими запросами искали' );
$GLOBALS['vkt_model_text'] = 'Не могу помочь.';
$GLOBALS['vkt_fetched'] = array();
$GLOBALS['vkt_pages'] = array();
$dead = VKT_News::search( VKT_News::settings( array( 'method' => 'search', 'engine' => 'feed', 'topic' => 'Экономика новости', 'days' => 1 ) ), '', 'ds' );
$assert( 1 === count( $GLOBALS['vkt_fetched'] ) && str_contains( $GLOBALS['vkt_fetched'][0], rawurlencode( 'Экономика новости' ) ) && str_contains( $GLOBALS['vkt_fetched'][0], rawurlencode( 'interval="7"' ) ) && is_wp_error( $dead ) && str_contains( $dead->get_error_message(), 'Поиск плагина не получил выдачу' ), 'Модель не дала запросов — ищем по описанию; выдача не ответила — понятная ошибка' );

// Выбранный поиск не ответил — сбор не встаёт: ищет плагин.
$GLOBALS['vkt_search_code'] = 403;
$GLOBALS['vkt_search_response'] = 'This service is not available in your region.';
$GLOBALS['vkt_model_text'] = '{"queries":["НБА обмены"]}';
$GLOBALS['vkt_pages'] = array( $bing( 'НБА обмены site:kp.ru' ) => $feed( $feed_item( 'Обмен в НБА', 'https://www.kp.ru/sport/trade/', 3600 ) ) );
$rescued = VKT_News::search( VKT_News::settings( array( 'method' => 'search', 'engine' => 'xai', 'topic' => 'Только НБА', 'days' => 3, 'sources' => array( 'https://www.kp.ru/' ) ) ), '', 'ds' );
$assert( ! is_wp_error( $rescued ) && array( 'Обмен в НБА' ) === array_column( $rescued['items'], 'title' ) && str_contains( $rescued['sources'][0]['message'], 'выбранный поиск не ответил' ), 'Grok отказал по региону — новости нашёл поиск плагина, и это видно в отчёте' );

// Фото к новости: со страницы статьи, без чужих анонсов и служебных картинок.
$GLOBALS['vkt_pages']['https://www.kp.ru/sport/trade/'] = '<html><head><meta property="og:image" content="https://s.kp.ru/photo/main.jpg?w=1200"><meta name="twitter:image" content="https://s.kp.ru/photo/main.jpg"></head><body><img src="https://s.kp.ru/other-news.jpg"><article><img src="/img/logo.png"><img width="120" src="https://s.kp.ru/small.jpg"><img data-src="//s.kp.ru/photo/second.jpg" width="900"><img src="http://s.kp.ru/insecure.jpg"><img src="/photo/third.webp"><img src="https://s.kp.ru/photo/second_140x100_crop.jpg"></article></body></html>';
$assert( array( 'photos' => array( 'https://s.kp.ru/photo/main.jpg?w=1200', 'https://s.kp.ru/photo/second.jpg', 'https://www.kp.ru/photo/third.webp' ) ) === VKT_News::photos( 'https://www.kp.ru/sport/trade/' ), 'Фото статьи: главный снимок и снимки из текста, без логотипа, мелочи, миниатюр, повторов, http и чужих анонсов' );
$assert( is_wp_error( VKT_News::photos( 'не адрес' ) ) && is_wp_error( VKT_News::photos( 'https://www.kp.ru/sport/gone/' ) ), 'Неверный адрес и неоткрывшаяся статья — ошибка, а не пустой список' );

// Рерайт: текст берётся со страницы статьи, модель его пересказывает, источник приписывает плагин.
$long = str_repeat( 'Клубы договорились об обмене защитниками, сделка закрыта вечером. ', 4 );
$GLOBALS['vkt_pages']['https://www.kp.ru/sport/trade/'] = '<html><body><p>' . $long . ' Чужой анонс вне статьи.</p><article><header><p>' . $long . ' Шапка.</p></header><p>Фото: агентство</p><p>' . $long . '</p><script>var p = "<p>' . $long . ' код</p>";</script><p>По данным клуба, сумма сделки &mdash; 12&nbsp;млн. ' . $long . '</p></article></body></html>';
$article = VKT_News::article( 'https://www.kp.ru/sport/trade/' );
$assert( is_string( $article ) && str_contains( $article, 'сумма сделки — 12' ) && ! str_contains( $article, 'Чужой анонс' ) && ! str_contains( $article, 'Шапка' ) && ! str_contains( $article, 'Фото: агентство' ) && ! str_contains( $article, 'код' ) && 2 === count( explode( "\n", $article ) ), 'Текст статьи: абзацы из article без шапки, подписей, скриптов и чужих анонсов' );
$GLOBALS['vkt_pages']['https://www.kp.ru/sport/short/'] = '<article><p>Коротко.</p></article>';
$assert( is_wp_error( VKT_News::article( 'https://www.kp.ru/sport/short/' ) ) && is_wp_error( VKT_News::article( 'https://www.kp.ru/sport/gone/' ) ), 'Страница без текста и неоткрывшаяся — ошибка: такая запись останется по анонсу' );
$GLOBALS['vkt_model_text'] = "<b>Обмен</b> состоялся.\n\nСумма — 12 млн.";
$rewritten = VKT_AI::rewrite_article( $article, 'ds' );
$sent = $GLOBALS['vkt_model_request']['messages'][0]['content'];
$assert( "Обмен состоялся.\n\nСумма — 12 млн." === $rewritten && 'deepseek-chat' === $GLOBALS['vkt_model_request']['model'] && str_contains( $sent, 'рерайт' ) && str_contains( $sent, 'не сокращай' ) && str_contains( $sent, 'Ничего не добавляй' ) && str_contains( $sent, 'сумма сделки — 12' ), 'Модель получает весь текст статьи и задачу полного рерайта: без сокращений и без добавлений' );
$assert( is_wp_error( VKT_AI::rewrite_article( 'Коротко.', 'ds' ) ) && 12000 === VKT_News::ARTICLE_MAX, 'Без текста статьи рерайт не запускается; статья уходит целиком, до 12 тысяч знаков' );

// Обложка сообщества: макет в настройках новостей группы, рисует плагин.
$cover = VKT_News::settings( array( 'cover' => array( 'name' => "  БОП <b>|</b>  Новости ", 'label' => '', 'color' => 'red', 'ratio' => 'panorama' ) ) )['cover'];
$assert( 'БОП | Новости' === $cover['name'] && '' === $cover['label'] && VKT_Cover::COLOR === $cover['color'] && 'landscape' === $cover['ratio'] && 'Новость' === VKT_News::settings( array() )['cover']['label'], 'Макет обложки: теги вырезаны, неверный цвет и формат заменены, метку можно убрать, по умолчанию «Новость»' );
$kept = VKT_News::clean( array( 'enabled' => true, 'method' => 'search', 'topic' => 'НБА' ), array( 'cover' => array( 'name' => 'БОП', 'color' => '#c0392b' ) ) );
$assert( 'БОП' === $kept['cover']['name'] && '#c0392b' === $kept['cover']['color'], 'Сохранение настроек новостей без блока обложки макет не стирает' );
$assert( 'Заголовок новости' === VKT_Cover::title( "  Заголовок <i>новости</i>\n\nТекст поста." ) && 160 === mb_strlen( VKT_Cover::title( str_repeat( 'слово ', 60 ) ) ) && str_ends_with( VKT_Cover::title( str_repeat( 'слово ', 60 ) ), '…' ), 'Заголовок обложки — первая строка записи, длинный обрезается' );
if ( VKT_Cover::available() ) {
    $lines = VKT_Cover::wrap( 'Эксперт назвал частые ошибки при установке зимних шин', 60, 700 );
    $assert( count( $lines ) >= 3 && 'Эксперт назвал частые ошибки при установке зимних шин' === implode( ' ', $lines ), 'Заголовок переносится по словам без потерь' );
    $photo = imagecreatetruecolor( 400, 900 );
    imagefilledrectangle( $photo, 0, 0, 400, 900, imagecolorallocate( $photo, 255, 255, 255 ) );
    $image = VKT_Cover::render( VKT_Cover::settings( array( 'name' => 'БОП', 'color' => '#c0392b', 'ratio' => 'square' ) ), 'Заголовок', $photo );
    $bottom = imagecolorat( $image, 1000, 1020 );
    $top = imagecolorat( $image, 1000, 20 );
    $assert( 1024 === imagesx( $image ) && 1024 === imagesy( $image ) && ( ( $bottom >> 16 ) & 255 ) > 170 && ( ( $bottom >> 8 ) & 255 ) < 90 && abs( ( ( $top >> 16 ) & 255 ) - ( ( $top >> 8 ) & 255 ) ) < 12, 'Обложка нужного размера: фото заполняет кадр, внизу градиент цвета сообщества, вверху — затемнённое фото' );
    $made = VKT_Cover::make( VKT_Cover::settings( array( 'name' => 'БОП' ) ), "Заголовок\nТекст", 0, '', true );
    $assert( is_array( $made ) && str_starts_with( $made['preview'], 'data:image/jpeg;base64,/9j/' ) && is_wp_error( VKT_Cover::make( VKT_Cover::settings( array() ), '   ', 0, '', true ) ), 'Пример обложки отдаётся картинкой без сохранения; без заголовка обложка не делается' );
} else {
    echo "SKIP: GD с FreeType не найден, отрисовка обложки не проверена\n";
}

// Картинки по запросу — с Викисклада: мелкие отброшены, автор и лицензия названы.
$GLOBALS['vkt_pages_prefix']['https://commons.wikimedia.org/w/api.php'] = json_encode( array( 'query' => array( 'pages' => array(
    '2' => array( 'index' => 2, 'imageinfo' => array( array( 'thumburl' => 'https://upload.wikimedia.org/b.jpg', 'width' => 3000, 'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:B.jpg', 'extmetadata' => array( 'Artist' => array( 'value' => '<a href="#">Автор Б</a>' ), 'LicenseShortName' => array( 'value' => 'CC BY-SA 4.0' ) ) ) ) ),
    '1' => array( 'index' => 1, 'imageinfo' => array( array( 'thumburl' => 'https://upload.wikimedia.org/a.jpg', 'width' => 1600, 'extmetadata' => array( 'LicenseShortName' => array( 'value' => 'CC0' ) ) ) ) ),
    '3' => array( 'index' => 3, 'imageinfo' => array( array( 'thumburl' => 'https://upload.wikimedia.org/icon.png', 'width' => 120 ) ) ),
) ) ) );
$images = VKT_News::image_search( ' Subaru   Outback ' );
$assert( array( 'https://upload.wikimedia.org/a.jpg', 'https://upload.wikimedia.org/b.jpg' ) === array_column( $images['images'], 'url' ) && 'CC0' === $images['images'][0]['credit'] && 'Автор Б · CC BY-SA 4.0' === $images['images'][1]['credit'] && 'Subaru Outback' === $images['query'] && is_wp_error( VKT_News::image_search( 'я' ) ), 'Поиск картинок: порядок выдачи, без значков, с автором и лицензией; пустой запрос не уходит' );

// Автосбор: настройки, счёт упоминаний и отбор.
$auto = VKT_News::settings( array( 'auto' => array( 'enabled' => 1, 'hours' => 5, 'pick' => 'x', 'limit' => 99, 'photo' => 'gif', 'model' => 'Bad Model', 'series' => 'DROP', 'last' => str_repeat( 'я', 900 ) ) ) )['auto'];
$assert( $auto['enabled'] && 3 === $auto['hours'] && 'mentions' === $auto['pick'] && 10 === $auto['limit'] && 'article' === $auto['photo'] && '' === $auto['model'] && '' === $auto['series'] && 400 === mb_strlen( $auto['last'] ), 'Настройки автосбора вне списков приводятся к значениям по умолчанию' );
$kept = VKT_News::clean( array( 'enabled' => true, 'method' => 'rss', 'sources' => 'sport.test/rss', 'auto' => array( 'enabled' => true, 'hours' => 2, 'pick' => 'all', 'last' => 'подделка', 'series' => 'sforged1234' ) ), array( 'auto' => array( 'last' => 'Поставлено 2', 'last_at' => 100, 'series' => 'sabcdef1234', 'series_day' => '2026-10-05' ), 'recent' => array( 'Старое событие' ) ) );
$assert( ! is_wp_error( $kept ) && $kept['auto']['enabled'] && 2 === $kept['auto']['hours'] && 'all' === $kept['auto']['pick'] && 'Поставлено 2' === $kept['auto']['last'] && 100 === $kept['auto']['last_at'] && 'sabcdef1234' === $kept['auto']['series'] && array( 'Старое событие' ) === $kept['recent'], 'Форма меняет только выбор человека: итог захода, серия дня и память о событиях остаются' );
$assert( ! VKT_News::clean( array( 'enabled' => false, 'auto' => array( 'enabled' => true ) ), array() )['auto']['enabled'], 'У выключенной новостной группы автосбор тоже выключен' );
$item = static fn( $title, $source, $ago, $summary = '' ) => array( 'title' => $title, 'link' => 'https://' . $source . '/' . md5( $title ), 'date' => $now - $ago, 'summary' => $summary, 'source' => $source );
$pool = array(
    $item( 'Погода в Иркутске испортится к выходным', 'a.test', 10 ),
    $item( 'Зенит обыграл Спартак в матче тура со счётом 3:1', 'a.test', 20 ),
    $item( 'Спартак проиграл Зениту матч тура — 1:3', 'b.test', 30, 'Подробный анонс матча с составами и голами.' ),
    $item( 'Нападающий Иванов перешёл в Динамо за рекордную сумму', 'b.test', 40 ),
    $item( 'Иванов перешёл в Динамо: рекордная сумма трансфера', 'c.test', 50 ),
    $item( 'Матч тура: Зенит победил Спартак', 'c.test', 60 ),
    $item( 'Зенит и Спартак: разбор матча тура', 'a.test', 70 ),
);
$ranked = VKT_News::rank( $pool );
$assert( 3 === count( $ranked ) && array( 3, 2, 1 ) === array_column( $ranked, 'mentions' ), 'Семь статей — три события; вторая статья того же сайта упоминанием не считается' );
$assert( 'b.test' === $ranked[0]['source'] && 3 === count( $ranked[0]['also'] ) && array() === $ranked[2]['also'], 'Событие пересказывается по самому подробному анонсу, остальные статьи о нём запомнены' );
$assert( 2 === count( VKT_News::rank( $pool, array( 'Зенит обыграл Спартак в матче тура' ) ) ), 'Событие, о котором сообщество уже писало, отбрасывается' );
list( $short, $count, $hint ) = VKT_News::shortlist( $pool, array( 'pick' => 'mentions', 'limit' => 2 ) );
$assert( 3 === count( $short ) && 2 === $count && 3 === $short[0]['mentions'] && str_contains( $hint, 'mentions' ), '«Самое упоминаемое»: список по убыванию упоминаний, с запасом на неподходящее' );
list( $short, $count, $hint ) = VKT_News::shortlist( $pool, array( 'pick' => 'all', 'limit' => 2 ) );
$assert( 2 === count( $short ) && 2 === $count && str_starts_with( $short[0]['title'], 'Погода' ) && str_contains( $hint, 'всё найденное' ), '«Всё найденное»: свежее первым, не больше предела, без повторов события' );
list( $short, $count ) = VKT_News::shortlist( $pool, array( 'pick' => 'model', 'limit' => 1 ) );
$assert( 3 === count( $short ) && 1 === $count, '«Самое интересное»: модель выбирает из всех событий' );
$GLOBALS['vkt_model_text'] = '{"news":[{"id":1,"text":"Матч"}]}';
$answer = VKT_AI::generate_news( $ranked, 'Спорт', 2, 'posts', '', '', 'Бери сверху вниз.' );
$sent = (string) $GLOBALS['vkt_model_request']['messages'][0]['content'];
$assert( str_contains( $sent, 'Бери сверху вниз.' ) && str_contains( $sent, '"mentions":3' ), 'Напутствие автосбора и счётчик упоминаний уходят модели' );
$composed = VKT_News::compose( $ranked, $answer, 'posts' );
$assert( 1 === count( $composed ) && 3 === $composed[0]['mentions'] && 3 === count( $composed[0]['also'] ) && array( $ranked[0]['link'] ) === $composed[0]['links'], 'Запись помнит все статьи своего события' );

echo "PASS: $checks news checks\n";

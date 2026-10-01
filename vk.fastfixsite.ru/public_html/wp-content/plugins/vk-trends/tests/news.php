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
function get_option( $key, $default = false ) { return $default; }
function wp_remote_retrieve_response_code( $response ) { return $response['code'] ?? 200; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_safe_remote_get( $url, $args = array() ) {
    $GLOBALS['vkt_fetched'][] = $url;
    $page = $GLOBALS['vkt_pages'][ $url ] ?? null;
    return null === $page ? new WP_Error( 'http', 'cURL error 28: timeout' ) : ( is_array( $page ) ? $page : array( 'body' => $page ) );
}
function wp_safe_remote_head( $url, $args = array() ) {
    $GLOBALS['vkt_checked'][] = $url;
    return in_array( $url, $GLOBALS['vkt_alive'] ?? array(), true ) ? array( 'code' => 200, 'body' => '' ) : array( 'code' => 404, 'body' => '' );
}
function wp_remote_post( $url, $args ) {
    if ( str_ends_with( $url, '/v1/responses' ) ) {
        $GLOBALS['vkt_search_request'] = json_decode( $args['body'], true );
        return array( 'code' => $GLOBALS['vkt_search_code'] ?? 200, 'body' => json_encode( $GLOBALS['vkt_search_response'] ) );
    }
    $GLOBALS['vkt_model_request'] = json_decode( $args['body'], true );
    return array( 'body' => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $GLOBALS['vkt_model_text'] ) ) ) ) ) );
}
class VKT_Tokens { public static function unseal( $value ) { return ''; } }
class VKT_Account { public static function is_admin() { return true; } }
class VKT_Store { public static $log = array(); public static function log( ...$entry ) { self::$log[] = $entry; } }

require dirname( __DIR__ ) . '/includes/class-materials.php';
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
$assert( array( array( 'id' => 'xai', 'title' => 'xAI Grok · web_search' ) ) === VKT_AI::search_providers(), 'С ключом xAI поиск доступен' );
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
$search_settings = VKT_News::settings( array( 'enabled' => true, 'method' => 'search', 'topic' => 'Только НБА', 'count' => 5, 'days' => 3, 'sources' => array( 'https://www.kp.ru/', 'https://tass.ru' ), 'used' => array( VKT_News::key( 'https://kp.ru/used' ) ) ) );
$searched = VKT_News::search( $search_settings, 'Баскетбол' );
$request = $GLOBALS['vkt_search_request'];
$assert( VKT_AI::SEARCH_MODEL === $request['model'] && 'web_search' === $request['tools'][0]['type'] && array( 'kp.ru', 'tass.ru' ) === $request['tools'][0]['filters']['allowed_domains'], 'Запрос к xAI: инструмент web_search и сайты группы фильтром' );
$assert( str_contains( $request['input'][0]['content'], 'Только НБА' ) && str_contains( $request['input'][0]['content'], 'за последние 3 сут' ) && str_contains( $request['input'][0]['content'], 'Баскетбол' ) && str_contains( $request['input'][0]['content'], 'До 10 ' ), 'В поиск уходят выборка, срок, группа и запас по числу' );
$assert( array( 'Обмен в НБА', 'Живая без цитаты' ) === array_column( $searched['items'], 'title' ), 'Остались новости с подтверждённой ссылкой: названная поиском и открывающаяся' );
$assert( 'https://www.kp.ru/sport/trade/' === $searched['items'][0]['link'] && 'kp.ru' === $searched['items'][0]['source'] && 'Клубы обменялись защитниками.' === $searched['items'][0]['summary'] && null === $searched['items'][1]['date'], 'Ссылка без utm-меток, источник — домен, теги из пересказа убраны' );
$assert( array( 'https://tass.ru/sport/1', 'https://fake-site.test/news/42' ) === $GLOBALS['vkt_checked'], 'Живьём проверяются только ссылки, которых поиск источниками не назвал' );
$assert( str_contains( $searched['sources'][0]['message'], 'отброшено без подтверждённой ссылки: 1' ) && 5 === $searched['sources'][0]['total'] && 2 === $searched['sources'][0]['fresh'] && str_contains( $searched['sources'][0]['url'], 'xAI Grok' ), 'В отчёте видно, кто искал и сколько отброшено' );
$many_sites = VKT_News::settings( array( 'method' => 'search', 'topic' => 'Т', 'sources' => array_map( static fn( $i ) => "https://s$i.test", range( 1, 7 ) ) ) );
VKT_News::search( $many_sites );
$assert( ! isset( $GLOBALS['vkt_search_request']['tools'][0]['filters'] ), 'Больше пяти сайтов — поиск без фильтра: xAI принимает не больше пяти' );
$GLOBALS['vkt_search_response'] = array( 'output_text' => 'Ничего не нашёл.' );
$assert( is_wp_error( VKT_News::search( $search_settings ) ), 'Ответ поиска без списка — ошибка' );
$GLOBALS['vkt_search_response'] = array( 'error' => array( 'message' => 'model not found' ) );
$GLOBALS['vkt_search_code'] = 404;
$denied = VKT_News::search( $search_settings );
$assert( is_wp_error( $denied ) && str_contains( $denied->get_error_message(), 'model not found' ) && str_contains( $denied->get_error_message(), '404' ), 'Отказ xAI показан дословно' );

echo "PASS: $checks news checks\n";

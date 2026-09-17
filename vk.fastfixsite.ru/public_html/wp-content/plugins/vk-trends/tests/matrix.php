<?php
// Изолированная проверка стенда постинга: ни WordPress, ни сети.
// Живые ответы VK подменяются, поэтому видно, какие методы стенд вызывает,
// а какие — намеренно не трогает.
define( 'ABSPATH', __DIR__ . '/' );
define( 'VKT_COMMUNITY_ID', 241464933 );

class WP_Error {
    public function __construct( public $code = '', public $message = '', public $data = array() ) {}
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
    public function add_data( $data ) { $this->data = $data; }
}
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function update_option( $name, $value, $autoload = true ) { return true; }

class VKT_Plugin {
    public static function settings() { return array( 'api_version' => '5.199' ); }
}
class VKT_Store {
    // Сколько раз блокировка окажется занятой, прежде чем освободиться.
    public static int $lock_fails = 0;
    public static function lock( $name, $seconds ) {
        if ( self::$lock_fails > 0 ) { --self::$lock_fails; return false; }
        return true;
    }
    public static function unlock( $name ) {}
    public static function log() {}
}
class VKT_Community {
    public static function group_id() { return VKT_COMMUNITY_ID; }
    public static function configured() { return true; }
}
class VKT_Publisher {
    public static array $targets = array(
        array( 'group_id' => 241464933, 'name' => 'Своя группа' ),
        array( 'group_id' => 777, 'name' => 'Чужая группа' ),
    );
    public static function upload_targets( $limit = 5 ) { return self::$targets; }
}
class VKT_Tokens {
    public static array $slots = array( 'user' => 'USER_TOKEN', 'community' => 'COMMUNITY_TOKEN' );
    public static array $data = array();
    public static array $notes = array();
    public static function definitions() {
        return array(
            'service' => array( 'title' => 'Сервисный ключ приложения' ),
            'user' => array( 'title' => 'Пользовательский токен' ),
            'community' => array( 'title' => 'Ключ сообщества' ),
        );
    }
    public static function token( $slot ) { return (string) ( self::$slots[ $slot ] ?? '' ); }
    public static function has( $slot ) { return '' !== self::token( $slot ); }
    public static function get( $slot ) { return self::$data; }
    public static function note( $slot, $message ) { self::$notes[ $slot ] = $message; }
    public static function save( $slot, $token, $extra = array() ) { return true; }
}

// Каждый вызов VK записывается: стенд обязан не трогать методы, которые создают объекты.
$GLOBALS['vkt_calls'] = array();
// Методы, которым подменённый VK ответит кодом 6: 'once' — только первый раз.
$GLOBALS['vkt_rate_limit'] = array();
function wp_remote_retrieve_response_code( $response ) { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return (string) $response['body']; }
function wp_remote_post( $url, $args ) {
    $method = substr( $url, strrpos( $url, '/' ) + 1 );
    $group = absint( $args['body']['group_id'] ?? $args['body']['group_ids'] ?? 0 );
    $GLOBALS['vkt_calls'][] = array( 'method' => $method, 'group' => $group, 'token' => (string) ( $args['body']['access_token'] ?? '' ) );
    $own = 241464933 === $group;
    $limit = $GLOBALS['vkt_rate_limit'][ $method ] ?? '';
    if ( '' !== $limit ) {
        if ( 'once' === $limit ) { unset( $GLOBALS['vkt_rate_limit'][ $method ] ); }
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'error' => array( 'error_code' => 6, 'error_msg' => 'Too many requests per second' ) ) ) );
    }
    if ( 'users.get' === $method ) {
        $body = array( 'response' => array( array( 'id' => 38975563 ) ) );
    } elseif ( 'wall.get' === $method ) {
        $body = array( 'response' => array( 'count' => 208, 'items' => array() ) );
    } elseif ( 'groups.get' === $method || 'groups.getTokenPermissions' === $method ) {
        $body = array( 'response' => array( 'count' => 23, 'items' => array() ) );
    } elseif ( 'groups.getById' === $method ) {
        $body = array( 'response' => array( 'groups' => array( array(
            'id' => $group,
            'is_admin' => $own ? 1 : 0,
            'admin_level' => $own ? 3 : 0,
            'can_post' => 1,
        ) ) ) );
    } elseif ( 'photos.getWallUploadServer' === $method ) {
        $body = $own
            ? array( 'response' => array( 'upload_url' => 'https://pu.vk.com/upload', 'album_id' => -14 ) )
            : array( 'error' => array( 'error_code' => 15, 'error_msg' => 'Access denied: no access to call this method.' ) );
    } else {
        // wall.post без текста и вложений: право есть, а запись не создаётся.
        $body = array( 'error' => array( 'error_code' => 100, 'error_msg' => 'One of the parameters specified was missing or invalid' ) );
    }
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ) );
}

require dirname( __DIR__ ) . '/includes/class-api.php';

// Живой стенд выдерживает паузу между вызовами, offline-прогон её не ждёт.
VKT_API::$probe_pause_us = 0;

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

$report = VKT_API::probe_matrix();
$assert( ! is_wp_error( $report ), 'Стенд отработал на двух ключах и двух адресатах' );
$assert( 'Стенд постинга' === $report['title'] && '' !== $report['note'], 'У отчёта есть заголовок и своя подпись' );

$methods = array_column( $GLOBALS['vkt_calls'], 'method' );
$assert( ! in_array( 'photos.saveWallPhoto', $methods, true ), 'Стенд не сохраняет фотографию' );
$assert( ! in_array( 'video.save', $methods, true ), 'Стенд не создаёт видео' );
$assert( ! in_array( 'docs.getWallUploadServer', $methods, true ), 'Стенд не трогает документы' );
foreach ( $GLOBALS['vkt_calls'] as $call ) {
    if ( 'wall.post' === $call['method'] ) {
        $assert( true, 'wall.post вызывается только ради кода отказа' );
        break;
    }
}
// Пользовательский токен: три общих вызова и два на каждый адресат. Ключ
// сообщества: свои права и публикация только в своё сообщество.
$assert( 9 === count( $GLOBALS['vkt_calls'] ), 'Число живых запросов предсказуемо: 7 + 2' );
$assert( 9 === count( $report['checks'] ), 'Каждый вызов попал в отчёт отдельной строкой' );

$labels = array_column( $report['checks'], 'label' );
$assert( in_array( 'Пользовательский токен · адрес загрузки файлов в «Своя группа»', $labels, true ), 'В строке видно и ключ, и адресата' );
$assert( ! in_array( 'Ключ сообщества · право публикации в «Чужая группа»', $labels, true ), 'Ключ сообщества спрашивается только про своё сообщество' );
$assert( in_array( 'Ключ сообщества · выданные права', $labels, true ), 'Ключу сообщества достаётся своя проверка прав' );
$assert( ! in_array( 'Сервисный ключ приложения · чей это ключ', $labels, true ), 'Несохранённый ключ в отчёт не попадает' );
$assert( ! in_array( 'Ключ сообщества · права в «Своя группа»', $labels, true ), 'Ключу сообщества groups.getById не задаётся: его флаги про пользователя' );

$by_label = array_column( $report['checks'], null, 'label' );
$own_upload = $by_label['Пользовательский токен · адрес загрузки файлов в «Своя группа»'];
$alien_upload = $by_label['Пользовательский токен · адрес загрузки файлов в «Чужая группа»'];
$assert( true === $own_upload['ok'], 'В своём сообществе загрузка доступна' );
$assert( false === $alien_upload['ok'] && 15 === $alien_upload['code'], 'В чужом сообществе VK отказывает кодом 15' );

$own_rights = $by_label['Пользовательский токен · права в «Своя группа»'];
$alien_rights = $by_label['Пользовательский токен · права в «Чужая группа»'];
$assert( str_contains( $own_rights['message'], 'администратор (admin_level 3)' ), 'admin_level расшифрован словами' );
$assert( str_contains( $alien_rights['message'], 'не администратор (admin_level 0)' ), 'Чужая группа отличима по admin_level' );
$assert( str_contains( $own_rights['message'], 'can_post 1' ), 'can_post показан рядом' );

$wall = $by_label['Ключ сообщества · право публикации в «Своя группа»'];
$assert( true === $wall['ok'] && 100 === $wall['code'], 'Код 100 у пустого wall.post означает наличие права' );

// Итог одной строкой на сообщество — то, зачем стенд и нужен.
$assert( 2 === count( $report['verdict'] ), 'Итог подводится по каждому адресату' );
$assert( str_contains( $report['verdict'][0], 'Своя группа — запись с файлами уйдёт' ), 'Для своей группы итог утвердительный' );
$assert( str_contains( $report['verdict'][1], 'Чужая группа — ни загрузки, ни публикации' ), 'Для чужой группы итог объясняет, чего не хватает' );
$assert( true === $report['ok'], 'Отказ в чужой группе не портит общий итог: он про группу, а не про ключ' );
$assert( array() === VKT_Tokens::$notes, 'Стенд ничего не закрепляет за ключами' );

// Сервисный ключ читает стены и только. Раньше стенд просил у него загрузку и
// публикацию и собирал красные строки о том, что и не должно работать.
VKT_Tokens::$slots = array( 'service' => 'SERVICE_TOKEN' );
$GLOBALS['vkt_calls'] = array();
$service = VKT_API::probe_matrix();
$assert( 1 === count( $service['checks'] ), 'Сервисному ключу задаётся один вопрос' );
$assert( 'wall.get' === $service['checks'][0]['method'] && true === $service['checks'][0]['ok'], 'И это чтение стены, его настоящая работа' );
$service_methods = array_column( $GLOBALS['vkt_calls'], 'method' );
$assert( ! in_array( 'photos.getWallUploadServer', $service_methods, true ), 'Загрузку у сервисного ключа не спрашиваем' );
$assert( ! in_array( 'wall.post', $service_methods, true ), 'Публикацию у сервисного ключа не спрашиваем' );
$assert( str_contains( $service['verdict'][0], 'ни загрузки, ни публикации' ), 'Итог честно говорит, что одним сервисным ключом файл не приложить' );

// Ограничение частоты: разовый код 6 переспрашивается и не портит вердикт.
VKT_Publisher::$targets = array( array( 'group_id' => 241464933, 'name' => 'Своя группа' ) );
VKT_Tokens::$slots = array( 'user' => 'USER_TOKEN' );
$GLOBALS['vkt_calls'] = array();
$GLOBALS['vkt_rate_limit'] = array( 'photos.getWallUploadServer' => 'once' );
$retried = VKT_API::probe_matrix();
$upload = array_column( $retried['checks'], null, 'method' )['photos.getWallUploadServer'];
$assert( true === $upload['ok'] && 0 === $upload['code'], 'Разовый код 6 переспрашивается, и право видно верно' );
$assert( 2 === count( array_filter( $GLOBALS['vkt_calls'], static fn( $call ) => 'photos.getWallUploadServer' === $call['method'] ) ), 'Переспрос сделан ровно один раз' );

// Устойчивый код 6 остаётся отказом, но объясняется как темп, а не как право.
$GLOBALS['vkt_rate_limit'] = array( 'photos.getWallUploadServer' => 'always' );
$limited = VKT_API::probe_matrix();
$upload = array_column( $limited['checks'], null, 'method' )['photos.getWallUploadServer'];
$assert( false === $upload['ok'] && 6 === $upload['code'], 'Устойчивое ограничение частоты остаётся видимым' );
$assert( str_contains( $upload['message'], 'ограничение частоты запросов VK, а не отказ в праве' ), 'Код 6 объяснён словами' );
$GLOBALS['vkt_rate_limit'] = array();
VKT_Publisher::$targets = array(
    array( 'group_id' => 241464933, 'name' => 'Своя группа' ),
    array( 'group_id' => 777, 'name' => 'Чужая группа' ),
);
VKT_Tokens::$slots = array( 'user' => 'USER_TOKEN', 'community' => 'COMMUNITY_TOKEN' );

// Расшифровка ответа groups.getById отдельно: VK отдаёт две разные формы.
$rights = new ReflectionMethod( VKT_API::class, 'group_rights' );
$rights->setAccessible( true );
$assert( str_contains( $rights->invoke( null, array( 'groups' => array( array( 'admin_level' => 2, 'can_post' => 1 ) ) ) ), 'редактор (admin_level 2)' ), 'Форма {groups:[…]} читается' );
$assert( str_contains( $rights->invoke( null, array( array( 'admin_level' => 1, 'can_post' => 0 ) ) ), 'модератор (admin_level 1)' ), 'Плоская форма читается' );
$assert( 'VK не вернул данные сообщества.' === $rights->invoke( null, array() ), 'Пустой ответ объясняется словами' );

// Без адресатов и без ключей стенд отвечает понятной ошибкой, а не пустой таблицей.
VKT_Publisher::$targets = array();
$empty = VKT_API::probe_matrix();
$assert( is_wp_error( $empty ) && 'no_targets' === $empty->code, 'Без адресатов стенд объясняет, чего не хватает' );
VKT_Publisher::$targets = array( array( 'group_id' => 241464933, 'name' => 'Своя группа' ) );
VKT_Tokens::$slots = array();
$no_tokens = VKT_API::probe_matrix();
$assert( is_wp_error( $no_tokens ) && 'no_tokens' === $no_tokens->code, 'Без ключей стенд объясняет, чего не хватает' );
VKT_Tokens::$slots = array( 'user' => 'USER_TOKEN' );

// Токен классического обмена кода: пары обновления нет, в сеть ходить не за чем.
$GLOBALS['vkt_calls'] = array();
VKT_Tokens::$data = array( 'access_token' => 'USER_TOKEN', 'refresh_token' => '', 'device_id' => '', 'client_id' => '', 'expires_at' => time() + 60 );
$assert( true === VKT_API::maybe_refresh(), 'Токен без пары обновления не отправляется в VK ID' );
$assert( array() === $GLOBALS['vkt_calls'], 'Обновление не сделало ни одного запроса' );
VKT_Tokens::$data = array( 'access_token' => 'USER_TOKEN', 'refresh_token' => '', 'device_id' => '', 'client_id' => '', 'expires_at' => 0 );
$assert( true === VKT_API::maybe_refresh(), 'Токен без срока годности тоже не обновляется' );

// Темп запросов: занятая блокировка больше не проваливает запрос. Именно из-за
// этого запись с фотографией не могла уйти — её отправка идёт тремя вызовами
// подряд, и второй падал «Запрос уже выполняется».
$GLOBALS['vkt_calls'] = array();
VKT_Store::$lock_fails = 2;
$paced = VKT_API::publishing_request( 'photos.getWallUploadServer', array( 'group_id' => 241464933 ) );
$assert( ! is_wp_error( $paced ), 'Запрос дождался очереди вместо отказа' );
$assert( 0 === VKT_Store::$lock_fails, 'Блокировка опрошена столько раз, сколько была занята' );
$assert( 1 === count( $GLOBALS['vkt_calls'] ), 'В VK при этом ушёл ровно один запрос' );

echo 'All ' . $checks . ' offline posting matrix checks passed.' . PHP_EOL;

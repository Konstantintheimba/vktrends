<?php
defined( 'ABSPATH' ) || exit;

final class VKT_API {
    public static function methods() {
        static $methods;
        if ( null === $methods ) {
            $methods = json_decode( file_get_contents( VKT_DIR . 'includes/methods.json' ), true );
        }
        return $methods;
    }

    // Пустые значения для полей, которых нет у сервисного ключа.
    private static function empty_payload() {
        return array( 'kind' => 'service', 'access_token' => '', 'refresh_token' => '', 'expires_at' => 0, 'device_id' => '', 'client_id' => '', 'scope' => '' );
    }

    /**
     * Какой слот обслужит метод.
     *
     * Часть методов VK принимает только пользовательский токен, остальное
     * дешевле и надёжнее читать сервисным ключом: он не истекает и не требует
     * прав. Если подходящего слота нет — берём любой доступный, чтобы ошибка
     * пришла от VK с внятным текстом, а не от плагина.
     */
    const USER_ONLY = array( 'video.get', 'groups.get', 'wall.post', 'photos.getWallUploadServer', 'photos.saveWallPhoto', 'video.save' );

    public static function slot_for( $method ) {
        if ( in_array( $method, self::USER_ONLY, true ) && VKT_Tokens::has( 'user' ) ) {
            return 'user';
        }
        if ( VKT_Tokens::has( 'service' ) ) {
            return 'service';
        }
        return VKT_Tokens::has( 'user' ) ? 'user' : '';
    }

    private static function token_data() {
        $slot = VKT_Tokens::has( 'service' ) ? 'service' : ( VKT_Tokens::has( 'user' ) ? 'user' : '' );
        if ( '' === $slot ) {
            return array();
        }
        return array_merge( self::empty_payload(), VKT_Tokens::get( $slot ), array( 'kind' => 'service' === $slot ? 'service' : 'user' ) );
    }

    public static function token() {
        $data = self::token_data();
        return (string) ( $data['access_token'] ?? '' );
    }

    /** Режим чтения: чем плагин собирает данные. Наличие прочих ключей смотрите в VKT_Tokens. */
    public static function mode() {
        $data = self::token_data();
        return '' === ( $data['access_token'] ?? '' ) ? '' : $data['kind'];
    }

    /** Огрызок токена: достаточно, чтобы сверить с тем, что вставляли, и мало для кражи. */
    private static function preview( $token ) {
        $token = (string) $token;
        return mb_strlen( $token ) < 24 ? str_repeat( '•', 8 ) : mb_substr( $token, 0, 12 ) . '…' . mb_substr( $token, -6 );
    }

    // Безопасный статус токена для интерфейса — без access/refresh_token и прочих секретов.
    public static function status() {
        $data = self::token_data();
        if ( '' === ( $data['access_token'] ?? '' ) ) {
            return array( 'has_token' => false, 'mode' => '', 'expires_in' => null, 'refreshable' => false, 'preview' => '', 'length' => 0 );
        }
        $expires_in = null;
        if ( 'user' === $data['kind'] && $data['expires_at'] ) {
            $expires_in = max( 0, (int) $data['expires_at'] - time() );
        }
        return array(
            'has_token' => true,
            'mode' => $data['kind'],
            'expires_in' => $expires_in,
            'refreshable' => 'user' === $data['kind'] && '' !== $data['refresh_token'] && '' !== $data['device_id'] && '' !== $data['client_id'],
            'preview' => self::preview( $data['access_token'] ),
            'length' => mb_strlen( (string) $data['access_token'] ),
            'scope' => (string) ( $data['scope'] ?? '' ),
        );
    }

    /**
     * Положительно распознаёт ключ сообщества перед сохранением. Ошибка сети или
     * отказ метода считаются неопределённым типом и не блокируют пользовательский
     * токен: только успешный groups.getTokenPermissions доказывает group-token.
     */
    /**
     * Живая проверка сохранённого токена: users.get отвечает и пользовательскому,
     * и сервисному ключу, поэтому годится как единая проба.
     */
    public static function check_token() {
        $data = self::token_data();
        $token = (string) ( $data['access_token'] ?? '' );
        if ( '' === $token ) {
            return new WP_Error( 'no_token', 'Токен не сохранён.', array( 'status' => 400 ) );
        }
        $response = wp_remote_post( 'https://api.vk.com/method/users.get', array(
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 65536,
            'body' => array( 'access_token' => $token, 'v' => VKT_Plugin::settings()['api_version'] ),
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'transport', 'Не удалось подключиться к VK.', array( 'status' => 502 ) );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $report = array(
            'preview' => self::preview( $token ),
            'length' => mb_strlen( $token ),
            'mode' => $data['kind'],
            'scope' => (string) ( $data['scope'] ?? '' ),
            'expires_in' => $data['expires_at'] ? max( 0, $data['expires_at'] - time() ) : null,
            'source' => defined( 'VKT_ACCESS_TOKEN' ) ? 'wp-config.php' : 'настройки',
        );
        if ( isset( $body['error'] ) ) {
            $report['ok'] = false;
            $report['vk_code'] = (int) ( $body['error']['error_code'] ?? 0 );
            $report['message'] = sanitize_text_field( (string) ( $body['error']['error_msg'] ?? '' ) );
            return $report;
        }
        $user = (array) ( $body['response'][0] ?? array() );
        $report['ok'] = ! empty( $user['id'] );
        $report['user_id'] = absint( $user['id'] ?? 0 );
        $report['name'] = sanitize_text_field( trim( ( $user['first_name'] ?? '' ) . ' ' . ( $user['last_name'] ?? '' ) ) );
        $report['message'] = $report['ok'] ? 'Токен принят VK.' : 'VK не вернул пользователя.';
        return $report;
    }

    /**
     * Живая проверка конкретного слота: спрашиваем VK ровно тем ключом, что лежит
     * в слоте, и возвращаем его дословный ответ. Результат закрепляется за слотом,
     * чтобы в настройках было видно, какой именно ключ отказал и почему.
     */
    public static function probe_slot( $slot ) {
        $definitions = VKT_Tokens::definitions();
        if ( ! isset( $definitions[ $slot ] ) ) {
            return new WP_Error( 'slot', 'Неизвестный слот ключа.', array( 'status' => 400 ) );
        }
        $token = VKT_Tokens::token( $slot );
        if ( '' === $token ) {
            return new WP_Error( 'no_token', 'Ключ в этом слоте не сохранён.', array( 'status' => 400 ) );
        }
        // Защищённый ключ не токен: его проверяет обмен, а не методы API.
        if ( 'app_secret' === $slot ) {
            return VKT_OAuth::check_secret();
        }
        // На своей группе из константы проверка была зелёной, пока публикация
        // падала: адресат в конструкторе выбирается другой. Проверяем те же
        // группы, куда уходят файлы.
        $photos = array();
        $targets = class_exists( 'VKT_Publisher' ) ? VKT_Publisher::upload_targets() : array();
        foreach ( $targets as $target ) {
            $photos[] = array(
                'photos.getWallUploadServer',
                array( 'group_id' => absint( $target['group_id'] ) ),
                'Загрузка фото в «' . $target['name'] . '» (право photos)',
            );
        }
        if ( ! $photos && VKT_Community::group_id() ) {
            $photos[] = array( 'photos.getWallUploadServer', array( 'group_id' => VKT_Community::group_id() ), 'Загрузка фото (право photos)' );
        }
        $checks = array(
            'service' => array( array( 'wall.get', array( 'owner_id' => -1, 'count' => 1 ), 'Чтение стены' ) ),
            'community' => array( array( 'groups.getTokenPermissions', array(), 'Права ключа сообщества' ) ),
            'user' => array_merge(
                array(
                    array( 'users.get', array(), 'Аккаунт' ),
                    array( 'groups.get', array( 'filter' => 'admin', 'count' => 1 ), 'Список сообществ (право groups)' ),
                ),
                $photos,
                array( array( 'wall.post', array(), 'Публикация (право wall)' ) )
            ),
        );
        $report = array( 'slot' => $slot, 'title' => $definitions[ $slot ]['title'], 'checks' => array(), 'ok' => true );
        foreach ( $checks[ $slot ] as $check ) {
            list( $method, $params, $label ) = $check;
            // wall.post проверяем заведомо пустым запросом: право видно по коду отказа,
            // а запись при этом не создаётся.
            $result = self::probe_call( $method, $params, $token );
            $granted = $result['ok'] || in_array( $result['code'], array( 100, 113, 104 ), true );
            // Публикацию пользовательским токеном VK разрешает только приложениям
            // типа Standalone. Когда настроен ключ сообщества, публикует он —
            // и этот отказ ничему не мешает, поэтому не считаем его провалом.
            $optional = ( 'wall.post' === $method && VKT_Community::configured() )
                // Отказ загрузки в чужое сообщество — это отсутствие в нём прав
                // администратора, а не изъян ключа: отчёт по токену из-за
                // такого адресата краснеть не должен.
                || ( 'photos.getWallUploadServer' === $method && absint( $params['group_id'] ?? 0 ) !== VKT_Community::group_id() );
            $tail = 'wall.post' === $method
                ? ' — это не мешает: на стене своего сообщества публикует ключ сообщества, а токен нужен только для загрузки фото.'
                : ' — в это сообщество файл не приложить: право загрузки даёт администрирование, а его у токена нет. Записи с файлами отправляйте в своё сообщество.';
            $report['checks'][] = array(
                'method' => $method,
                'label' => $label,
                'ok' => $granted,
                'optional' => $optional && ! $granted,
                'code' => $result['code'],
                'message' => $result['message'] . ( $optional && ! $granted ? $tail : '' ),
            );
            if ( ! $granted && ! $optional ) {
                $report['ok'] = false;
            }
        }
        $failed = array_values( array_filter( $report['checks'], static fn( $check ) => ! $check['ok'] && empty( $check['optional'] ) ) );
        VKT_Tokens::note( $slot, $failed ? $failed[0]['label'] . ': ' . $failed[0]['message'] : '' );
        return $report;
    }

    /**
     * Стенд постинга: один прогон вместо череды догадок. У каждого ключа своя
     * роль, и спрашивать у него чужую работу бессмысленно — VK ответит
     * отказом, который ничего не значит. photos.saveWallPhoto, video.save и
     * настоящий wall.post с текстом сюда не входят намеренно: они уже создают
     * объекты в сообществе.
     */
    public static function probe_matrix() {
        $targets = class_exists( 'VKT_Publisher' ) ? VKT_Publisher::upload_targets() : array();
        if ( ! $targets ) {
            return new WP_Error( 'no_targets', 'Стенду нужен хотя бы один адресат: включите сообщество в «Автопостинге» либо задайте VKT_COMMUNITY_ID.', array( 'status' => 400 ) );
        }
        $definitions = VKT_Tokens::definitions();
        $own = VKT_Community::group_id();
        $report = array(
            'title' => 'Стенд постинга',
            'note' => 'Каждый ключ опрошен только по своей работе. Вызваны методы, которые ничего не создают: ни записей, ни фотографий.',
            'verdict' => array(),
            'checks' => array(),
            // Уровень отчёта всегда ok: стенд ничего не закрепляет за ключами
            // через note(), а отказ в чужой группе — факт о группе, не о ключе.
            'ok' => true,
        );
        $uploads = array();
        $publishes = array();
        foreach ( array( 'user', 'community', 'service' ) as $slot ) {
            $token = VKT_Tokens::token( $slot );
            if ( '' === $token ) {
                continue;
            }
            $title = (string) ( $definitions[ $slot ]['title'] ?? $slot );
            foreach ( self::matrix_calls( $slot, $targets, $own ) as $call ) {
                $result = self::probe_call( $call['method'], $call['params'], $token );
                // Код 100 значит «право есть, параметров нет»: для wall.post это
                // и есть разрешение, а запись при этом не создаётся.
                $granted = $result['ok'] || in_array( $result['code'], array( 100, 113, 104 ), true );
                $optional = ! $granted && ! empty( $call['optional'] );
                $message = 'groups.getById' === $call['method'] && $result['ok']
                    ? self::group_rights( $result['response'] )
                    // Код 6 — это темп запросов, а не отсутствие права:
                    // без пояснения строка читается как настоящий отказ.
                    : $result['message'] . ( 6 === $result['code'] ? ' — это ограничение частоты запросов VK, а не отказ в праве. Повторите стенд.' : '' );
                $report['checks'][] = array(
                    'method' => $call['method'],
                    'label' => $title . ' · ' . $call['what'],
                    'ok' => $granted,
                    'optional' => $optional,
                    'code' => $result['code'],
                    'message' => $message . ( $optional ? (string) ( $call['tail'] ?? '' ) : '' ),
                );
                $group = absint( $call['group'] ?? 0 );
                if ( $group && 'photos.getWallUploadServer' === $call['method'] ) {
                    $uploads[ $group ] = $granted;
                }
                if ( $group && 'wall.post' === $call['method'] ) {
                    $publishes[ $group ] = $granted;
                }
            }
        }
        if ( ! $report['checks'] ) {
            return new WP_Error( 'no_tokens', 'Ни один ключ VK не сохранён — проверять нечем.', array( 'status' => 400 ) );
        }
        $report['verdict'] = self::matrix_verdict( $targets, $uploads, $publishes );
        return $report;
    }

    /**
     * Что спрашивать у конкретного ключа. Сервисный ключ по замыслу VK читает
     * стены и только: просить у него загрузку и публикацию — значит собирать
     * красные строки о том, что и не должно работать. Ключ сообщества
     * публикует на своей стене, а фото ему закрыты кодом 27. Загружать файлы
     * умеет один пользовательский токен, зато wall.post закрыт уже ему.
     */
    private static function matrix_calls( $slot, $targets, $own ) {
        if ( 'service' === $slot ) {
            return array( array(
                'method' => 'wall.get',
                'params' => array( 'owner_id' => -absint( $targets[0]['group_id'] ), 'count' => 1 ),
                'what' => 'чтение стены — вся его работа',
            ) );
        }
        if ( 'community' === $slot ) {
            $calls = array( array( 'method' => 'groups.getTokenPermissions', 'params' => array(), 'what' => 'выданные права' ) );
            foreach ( $targets as $target ) {
                if ( absint( $target['group_id'] ) !== $own ) {
                    continue;
                }
                $calls[] = array(
                    'method' => 'wall.post',
                    'params' => array( 'owner_id' => -$own ),
                    'what' => 'право публикации в «' . $target['name'] . '»',
                    'group' => $own,
                );
            }
            return $calls;
        }
        $calls = array(
            array( 'method' => 'users.get', 'params' => array(), 'what' => 'чей это ключ' ),
            array( 'method' => 'groups.get', 'params' => array( 'filter' => 'editor', 'count' => 1 ), 'what' => 'свои сообщества' ),
            array(
                'method' => 'wall.post',
                'params' => array(),
                'what' => 'право публикации',
                'optional' => VKT_Community::configured(),
                'tail' => ' — это не мешает: на стене своего сообщества публикует ключ сообщества, а токен нужен только для загрузки файлов.',
            ),
        );
        foreach ( $targets as $target ) {
            $group_id = absint( $target['group_id'] );
            $where = ' в «' . $target['name'] . '»';
            $calls[] = array(
                'method' => 'groups.getById',
                'params' => array( 'group_ids' => $group_id, 'fields' => 'can_post,is_admin,admin_level' ),
                'what' => 'права' . $where,
            );
            $calls[] = array(
                'method' => 'photos.getWallUploadServer',
                'params' => array( 'group_id' => $group_id ),
                'what' => 'адрес загрузки файлов' . $where,
                'group' => $group_id,
            );
        }
        return $calls;
    }

    /** Итог по строке на сообщество: уйдёт ли туда запись с файлом и чем именно. */
    private static function matrix_verdict( $targets, $uploads, $publishes ) {
        $lines = array();
        foreach ( $targets as $target ) {
            $group_id = absint( $target['group_id'] );
            $upload = ! empty( $uploads[ $group_id ] );
            $publish = ! empty( $publishes[ $group_id ] );
            if ( $upload && $publish ) {
                $lines[] = $target['name'] . ' — запись с файлами уйдёт: файл грузит пользовательский токен, публикует ключ сообщества.';
            } elseif ( $upload ) {
                $lines[] = $target['name'] . ' — файл загрузится, а публиковать нечем: нужен ключ сообщества именно этой группы, потому что пользовательскому токену wall.post закрыт для приложений не типа Standalone.';
            } elseif ( $publish ) {
                $lines[] = $target['name'] . ' — текст опубликуется, а файл не загрузится: пользовательскому токену закрыт photos.getWallUploadServer в этой группе.';
            } else {
                $lines[] = $target['name'] . ' — ни загрузки, ни публикации: нужен пользовательский токен с правом photos и ключ сообщества этой группы.';
            }
        }
        return $lines;
    }

    /** Ответ groups.getById словами. Догадок не строим: право загрузки проверено живым вызовом в соседней строке отчёта. */
    private static function group_rights( $response ) {
        $group = (array) ( $response['groups'][0] ?? $response[0] ?? array() );
        if ( ! $group ) {
            return 'VK не вернул данные сообщества.';
        }
        $levels = array( 0 => 'не администратор', 1 => 'модератор', 2 => 'редактор', 3 => 'администратор' );
        $admin = absint( $group['admin_level'] ?? 0 );
        return sprintf( '%s (admin_level %d), can_post %d', $levels[ $admin ] ?? 'неизвестно', $admin, absint( $group['can_post'] ?? 0 ) );
    }

    /** Пауза между пробными вызовами, микросекунды. Ноль ставится только offline-тестами. */
    public static $probe_pause_us = 350000;

    /**
     * Один пробный вызов. VK считает частоту, а стенд делает запросы десятками
     * подряд — без паузы часть проверок возвращала код 6 вместо настоящего
     * ответа. Одна повторная попытка после паузы отделяет ограничение частоты
     * от настоящего отказа в праве.
     */
    private static function probe_call( $method, $params, $token ) {
        $result = array( 'ok' => false, 'code' => 6, 'message' => 'VK ограничил частоту запросов.' );
        for ( $attempt = 1; $attempt <= 2; ++$attempt ) {
            if ( self::$probe_pause_us > 0 ) {
                usleep( 1 === $attempt ? (int) self::$probe_pause_us : 1200000 );
            }
            $result = self::probe_request( $method, $params, $token );
            if ( 6 !== $result['code'] ) {
                return $result;
            }
        }
        return $result;
    }

    /** Сам запрос: возвращает код и текст VK, ничего не логируя как ошибку публикации. */
    private static function probe_request( $method, $params, $token ) {
        $response = wp_remote_post( 'https://api.vk.com/method/' . $method, array(
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 131072,
            'body' => array_merge( $params, array( 'access_token' => $token, 'v' => VKT_Plugin::settings()['api_version'] ) ),
        ) );
        if ( is_wp_error( $response ) ) {
            return array( 'ok' => false, 'code' => 0, 'message' => 'Нет связи с VK.' );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return array( 'ok' => false, 'code' => 0, 'message' => 'VK вернул нечитаемый ответ.' );
        }
        if ( isset( $body['error'] ) ) {
            return array(
                'ok' => false,
                'code' => (int) ( $body['error']['error_code'] ?? 0 ),
                'message' => str_replace( $token, '[hidden]', sanitize_text_field( (string) ( $body['error']['error_msg'] ?? '' ) ) ),
            );
        }
        return array( 'ok' => true, 'code' => 0, 'message' => 'Метод доступен.', 'response' => $body['response'] ?? null );
    }

    public static function detect_token_kind( $token ) {
        $token = is_string( $token ) ? trim( $token ) : '';
        if ( '' === $token ) {
            return 'unknown';
        }
        $response = wp_remote_post( 'https://api.vk.com/method/groups.getTokenPermissions', array(
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => 65536,
            'body' => array(
                'access_token' => $token,
                'v' => VKT_Plugin::settings()['api_version'],
            ),
        ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return 'unknown';
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) && array_key_exists( 'response', $body ) ? 'community' : 'unknown';
    }


    // $kind: 'service' — обычный ключ доступа. 'user' — пользовательский токен;
    // для автообновления $extra должен целиком содержать refresh_token, device_id и client_id.
    /** Совместимость: старые вызовы кладут ключ в слот по его типу. */
    public static function save_token( $token, $kind = 'service', $extra = array() ) {
        return VKT_Tokens::save( 'user' === $kind ? 'user' : 'service', $token, $extra );
    }

    // Перед запросом обновляет пользовательскую пару токенов, если срок годности истекает
    // меньше чем через 5 минут. Сервисный ключ не истекает — обновлять нечего.
    public static function maybe_refresh() {
        $data = VKT_Tokens::get( 'user' );
        if ( '' === ( $data['access_token'] ?? '' ) ) {
            return true;
        }
        if ( ! $data['expires_at'] || $data['expires_at'] - time() > 300 ) {
            return true;
        }
        // Токен классического обмена кода приходит без пары обновления:
        // VKT_Tokens::save() пишет refresh_token, device_id и client_id
        // пустыми. Обновлять его через id.vk.ru нечем, а отказ останавливал
        // всю публикацию на ровном месте — работаем, пока VK его принимает.
        if ( '' === $data['refresh_token'] || '' === $data['client_id'] || '' === $data['device_id'] ) {
            return true;
        }
        // Блокировка у каждого своя: токены разных кабинетов обновляются независимо.
        $lock = 'token_refresh_' . VKT_Account::id();
        if ( ! VKT_Store::lock( $lock, 20 ) ) {
            // Токен уже обновляет другой запрос — работаем со старым значением, пока не истёк совсем.
            return true;
        }
        try {
            // Пока ждали блокировку, токен мог обновиться в другом потоке.
            $data = VKT_Tokens::get( 'user' );
            if ( '' === ( $data['access_token'] ?? '' ) || ! $data['expires_at'] || $data['expires_at'] - time() > 300 ) {
                return true;
            }
            if ( '' === $data['refresh_token'] || '' === $data['client_id'] || '' === $data['device_id'] ) {
                // Пара обновления исчезла, пока ждали блокировку: не повод
                // отказывать в запросе — ключ ещё действует.
                return true;
            }
            $response = wp_remote_post( 'https://id.vk.ru/oauth2/auth', array(
                'timeout' => 15, 'redirection' => 0, 'sslverify' => true,
                'body' => array(
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $data['refresh_token'],
                    'client_id' => $data['client_id'],
                    'device_id' => $data['device_id'],
                ),
            ) );
            if ( is_wp_error( $response ) ) {
                VKT_Store::log( 'oauth2.auth', 'refresh', 'error', 0, 'Нет связи с id.vk.ru для обновления токена', 0 );
                return new WP_Error( 'refresh_transport', 'Не удалось обновить пользовательский токен: нет связи с id.vk.ru.', array( 'status' => 502, 'retryable' => true ) );
            }
            $http = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( 200 !== $http || ! is_array( $body ) || empty( $body['access_token'] ) ) {
                VKT_Store::log( 'oauth2.auth', 'refresh', 'error', (int) $http, 'VK ID отклонил обновление токена', 0 );
                return new WP_Error( 'refresh_failed', 'VK ID отклонил обновление токена. Сохраните пользовательский токен заново.', array( 'status' => 401 ) );
            }
            $saved = self::save_token( $body['access_token'], 'user', array(
                'refresh_token' => $body['refresh_token'] ?? $data['refresh_token'],
                'expires_in' => $body['expires_in'] ?? 3600,
                'device_id' => $body['device_id'] ?? $data['device_id'],
                'client_id' => $data['client_id'],
            ) );
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }
            VKT_Store::log( 'oauth2.auth', 'refresh', 'ok', 0, 'Пользовательский токен обновлён', 0 );
            return true;
        } finally {
            VKT_Store::unlock( $lock );
        }
    }

    private static function message( $code ) {
        $messages = array(
            // Токен из Implicit Flow VK привязывает к IP браузера, а плагин ходит с IP сервера.
            5 => 'VK: авторизация не прошла. Токен просрочен, отозван либо получен на другом IP — полученный в браузере токен на сервере не работает.',
            6 => 'VK: слишком много запросов. Повтор будет доступен позже.',
            7 => 'VK: токену не хватает разрешений для этого метода.',
            9 => 'VK: временное ограничение частоты запросов.',
            10 => 'VK: внутренняя ошибка. Попробуйте позже.',
            14 => 'VK требует CAPTCHA. Автоматический обход не выполняется.',
            17 => 'VK требует дополнительного подтверждения пользователя.',
            15 => 'VK: доступ к объекту запрещён.',
            27 => 'VK: этот метод недоступен с токеном сообщества.',
            28 => 'VK: этот метод недоступен с сервисным токеном. Нужен пользовательский.',
            29 => 'VK: исчерпан лимит запросов. Повтор будет выполнен позже.',
            100 => 'VK: неверные или недостающие параметры. Сверьте их со справкой метода.',
            113 => 'VK: неверный идентификатор пользователя.',
            210 => 'VK: видео недоступно или удалено.',
            214 => 'VK: доступ к публикации на этой стене запрещён. Проверьте роль в сообществе и право wall у токена.',
            219 => 'VK: рекламная запись была опубликована недавно.',
            220 => 'VK: слишком много адресатов публикации.',
            222 => 'VK: сообщество запретило ссылки в записи.',
            224 => 'VK: достигнут лимит рекламных записей.',
            225 => 'VK: для этой записи недоступен VK Donut.',
            260 => 'VK: доступ к списку сообществ ограничен настройками приватности.',
            1117 => 'VK: срок действия токена истёк.',
        );
        return $messages[ $code ] ?? 'VK вернул ошибку ' . $code . '. Проверьте доступ и параметры метода.';
    }

    private static function redact( $value, $token ) {
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $key => $item ) {
                if ( preg_match( '/token|secret|access_key|request_params|authorization/i', (string) $key ) ) {
                    continue;
                }
                $clean[ $key ] = self::redact( $item, $token );
            }
            return $clean;
        }
        return is_string( $value ) ? str_replace( $token, '[hidden]', $value ) : $value;
    }

    /**
     * Запросы, которые нужны модулю публикаций, но принципиально не выдаются в
     * открытый каталог «Тест API». Список закрытый: произвольный метод или
     * access_token из браузера сюда передать нельзя.
     */
    public static function publishing_request( $method, $params = array() ) {
        $allowed = array(
            'groups.get' => array( 'extended', 'filter', 'fields', 'count', 'offset' ),
            'wall.post'  => array( 'owner_id', 'from_group', 'message', 'attachments', 'signed', 'close_comments', 'guid' ),
            // Загрузка медиа с нашего сервера: VK разрешает эти методы только
            // пользовательскому токену, ключ сообщества отвечает ошибкой 27.
            'photos.getWallUploadServer' => array( 'group_id' ),
            'photos.saveWallPhoto' => array( 'group_id', 'server', 'photo', 'hash', 'caption' ),
            'video.save' => array( 'group_id', 'name', 'description', 'wallpost', 'is_private' ),
        );
        if ( ! isset( $allowed[ $method ] ) ) {
            return new WP_Error( 'method', 'Метод не разрешён модулю публикаций.', array( 'status' => 400 ) );
        }
        if ( ! VKT_Tokens::has( 'user' ) ) {
            return new WP_Error( 'publishing_token', 'Для публикации нужен пользовательский токен с правами wall, photos и groups. Сохраните его в настройках.', array( 'status' => 400 ) );
        }
        if ( ! is_array( $params ) || count( $params ) > 12 ) {
            return new WP_Error( 'params', 'Неверные параметры публикации.', array( 'status' => 400 ) );
        }
        foreach ( $params as $key => &$value ) {
            $max_bytes = 'message' === $key ? 70000 : 20000;
            if ( ! in_array( $key, $allowed[ $method ], true ) || ! is_scalar( $value ) || strlen( (string) $value ) > $max_bytes ) {
                return new WP_Error( 'params', 'Неверный параметр запроса публикации.', array( 'status' => 400 ) );
            }
            if ( is_bool( $value ) ) {
                $value = $value ? 1 : 0;
            }
        }
        unset( $value );
        return self::dispatch( $method, $params, 'publisher' );
    }

    public static function request( $method, $params = array(), $context = 'test' ) {
        $methods = self::methods();
        if ( ! isset( $methods[ $method ] ) ) {
            return new WP_Error( 'method', 'Метод не входит в разрешённый список чтения.', array( 'status' => 400 ) );
        }
        if ( ! is_array( $params ) || count( $params ) > 40 ) {
            return new WP_Error( 'params', 'Параметры должны быть JSON-объектом.', array( 'status' => 400 ) );
        }
        $allowed = array_column( $methods[ $method ]['parameters'], 'name' );
        foreach ( $params as $key => &$value ) {
            if ( ! in_array( $key, $allowed, true ) || ! is_scalar( $value ) || strlen( (string) $value ) > 8000 ) {
                return new WP_Error( 'params', 'Неизвестный параметр или неверный формат. Списки передавайте строкой через запятую.', array( 'status' => 400 ) );
            }
            if ( is_bool( $value ) ) {
                $value = $value ? 1 : 0;
            }
        }
        unset( $value );
        foreach ( $methods[ $method ]['parameters'] as $definition ) {
            if ( ! empty( $definition['required'] ) && ( ! isset( $params[ $definition['name'] ] ) || '' === $params[ $definition['name'] ] ) ) {
                return new WP_Error( 'params', 'Обязательный параметр: ' . $definition['name'], array( 'status' => 400 ) );
            }
        }
        // Bound response size and forbid tokens/version/execute through custom parameters.
        if ( isset( $params['count'] ) ) {
            $params['count'] = max( 1, min( 100, (int) $params['count'] ) );
        }
        return self::dispatch( $method, $params, $context );
    }

    private static function dispatch( $method, $params, $context ) {
        $refreshed = self::maybe_refresh();
        if ( is_wp_error( $refreshed ) ) {
            return $refreshed;
        }
        $slot = self::slot_for( $method );
        $token = '' === $slot ? '' : VKT_Tokens::token( $slot );
        if ( '' === $token ) {
            return new WP_Error( 'no_token', 'Ни один ключ VK не сохранён. Добавьте его в настройках.', array( 'status' => 400 ) );
        }
        // Темп запросов к VK держится этой блокировкой: после каждого ответа
        // она отпускается на секунду вперёд. Раньше занятая блокировка сразу
        // возвращала отказ, и запись с фотографией не могла уйти в принципе:
        // её отправка — три запроса подряд (getWallUploadServer →
        // saveWallPhoto → wall.post), и второй всегда падал «Запрос уже
        // выполняется». Теперь ждём своей очереди.
        $waited = 0;
        while ( ! VKT_Store::lock( 'api', 30 ) ) {
            if ( ++$waited > 12 ) {
                return new WP_Error( 'rate_limit', 'Очередь запросов к VK не освободилась за пять секунд. Повторите попытку.', array( 'status' => 429, 'retryable' => true ) );
            }
            usleep( 400000 );
        }
        $started = microtime( true );
        try {
            $response = wp_remote_post( 'https://api.vk.com/method/' . $method, array(
                'timeout' => 15, 'redirection' => 0, 'sslverify' => true,
                'limit_response_size' => 2 * 1024 * 1024,
                'body' => array_merge( $params, array( 'access_token' => $token, 'v' => VKT_Plugin::settings()['api_version'] ) ),
            ) );
            $ms = (int) round( ( microtime( true ) - $started ) * 1000 );
            if ( is_wp_error( $response ) ) {
                $error = new WP_Error( 'transport', 'Не удалось подключиться к VK по HTTPS.', array( 'status' => 502, 'retryable' => true ) );
            } else {
                $http = wp_remote_retrieve_response_code( $response );
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( 200 !== $http ) {
                    $error = new WP_Error( 'http', 'VK вернул HTTP ' . $http . '.', array( 'status' => 502, 'retryable' => 429 === $http || $http >= 500 ) );
                } elseif ( ! is_array( $body ) || ( ! isset( $body['error'] ) && ! array_key_exists( 'response', $body ) ) ) {
                    $error = new WP_Error( 'json', 'Ответ VK не содержит корректный JSON или превышает 2 МБ. Уменьшите выборку.', array( 'status' => 502 ) );
                } elseif ( isset( $body['error'] ) ) {
                    $code = (int) ( $body['error']['error_code'] ?? 0 );
                    // Метод в тексте: без него по коду не понять, на каком шаге
                    // публикации отказал VK — на загрузке фото или на самой записи.
                    $detail = sanitize_text_field( (string) ( $body['error']['error_msg'] ?? '' ) );
                    $error = new WP_Error( 'vk_' . $code, self::message( $code ) . ' [' . $method . ( '' !== $detail ? ': ' . mb_substr( $detail, 0, 120 ) : '' ) . ']', array( 'status' => 422, 'vk_code' => $code, 'retryable' => in_array( $code, array( 1, 6, 9, 10, 29, 32, 36 ), true ) ) );
                } else {
                    VKT_Tokens::note( $slot, '' );
                    VKT_Store::log( $method, $context, 'ok', 0, 'Запрос выполнен', $ms );
                    return array( 'response' => self::redact( $body['response'], $token ), 'duration_ms' => $ms, 'method' => $method );
                }
            }
            $data = $error->get_error_data();
            $data['duration_ms'] = $ms;
            $error->add_data( $data );
            // Ошибка остаётся закреплённой за слотом: в настройках видно, какой ключ виноват.
            VKT_Tokens::note( $slot, $error->get_error_message() );
            VKT_Store::log( $method, $context, 'error', $data['vk_code'] ?? 0, $error->get_error_message(), $ms );
            return $error;
        } finally {
            // Retain the lock briefly after completion: at most one request per second.
            update_option( 'vkt_lock_api', time() + 1, false );
        }
    }
}

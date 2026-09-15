<?php
// Проверка разбора адреса из браузера в поле Access token — без WordPress и сети.
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

// Повторяем ровно ту логику, которую выполняет VKT_Plugin перед save_token.
function vkt_parse_token_input( array $data ) {
    $raw = is_string( $data['token'] ?? null ) ? trim( $data['token'] ) : '';
    if ( preg_match( '~[#?&]access_token=([a-zA-Z0-9._\-]{20,2048})~', $raw, $parsed ) ) {
        $data['token'] = $parsed[1];
        $data['token_kind'] = 'user';
        if ( empty( $data['expires_in'] ) && preg_match( '~[#?&]expires_in=(\d{1,7})~', $raw, $lifetime ) ) {
            $data['expires_in'] = (int) $lifetime[1];
        }
    }
    $ok = is_string( $data['token'] ) && preg_match( '/^[a-zA-Z0-9._\-]{20,2048}$/', trim( $data['token'] ) );
    return array( 'ok' => (bool) $ok, 'data' => $data );
}

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

$assert( str_contains( $source, "[#?&]access_token=" ), 'Разбор адреса действительно живёт в плагине, а не только в тесте' );

$token = 'vk1.a.vf37d_Xuf6OoJx27kgKxsu-QqezzY13fn-oe3w4Wwyd';
$url = 'https://oauth.vk.ru/blank.html#access_token=' . $token . '&expires_in=86400&user_id=38975563';
$result = vkt_parse_token_input( array( 'token' => $url ) );
$assert( $result['ok'], 'Адрес целиком принимается' );
$assert( $token === $result['data']['token'], 'Токен вырезается из фрагмента' );
$assert( 'user' === $result['data']['token_kind'], 'Тип определяется как пользовательский автоматически' );
$assert( 86400 === $result['data']['expires_in'], 'Срок жизни берётся из адреса' );

// Заданный вручную срок жизни важнее того, что в адресе.
$manual = vkt_parse_token_input( array( 'token' => $url, 'expires_in' => 3600 ) );
$assert( 3600 === $manual['data']['expires_in'], 'Явно указанный срок не перетирается' );

// Обычный токен без адреса работает как раньше и тип не меняет.
$plain = vkt_parse_token_input( array( 'token' => $token, 'token_kind' => 'service' ) );
$assert( $plain['ok'] && $token === $plain['data']['token'], 'Голый токен принимается' );
$assert( 'service' === $plain['data']['token_kind'], 'Для голого токена тип остаётся выбранным вручную' );

// Мусор по-прежнему отклоняется.
$assert( ! vkt_parse_token_input( array( 'token' => 'https://example.com/page' ) )['ok'], 'Адрес без токена отклоняется' );
$assert( ! vkt_parse_token_input( array( 'token' => '<script>alert(1)</script>' ) )['ok'], 'HTML отклоняется' );
$assert( ! vkt_parse_token_input( array( 'token' => 'короткий' ) )['ok'], 'Слишком короткая строка отклоняется' );

echo "All $checks offline token-input checks passed.\n";

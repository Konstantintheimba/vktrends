<?php
// Проверка огрызка токена: он должен опознавать сохранённое и не раскрывать его.
define( 'ABSPATH', __DIR__ . '/' );

function vkt_preview( $token ) {
    $token = (string) $token;
    return mb_strlen( $token ) < 24 ? str_repeat( '•', 8 ) : mb_substr( $token, 0, 12 ) . '…' . mb_substr( $token, -6 );
}

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-api.php' );
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

$assert( str_contains( $source, 'private static function preview(' ), 'Огрызок собирается в самом плагине' );
$assert( str_contains( $source, "'preview' => self::preview(" ), 'Статус отдаёт огрызок интерфейсу' );
$assert( ! preg_match( "/'access_token'\s*=>\s*\\\$data\['access_token'\]/", $source ), 'Полный токен в статус не попадает' );

$token = 'vk1.a.vf37d_Xuf6OoJx27kgKxsu-QqezzY13fn-oe3w4WwydOUXtSXW_myQXVAatmHPUkZSMKIUh6Mqmuf';
$preview = vkt_preview( $token );
$assert( 'vk1.a.vf37d_…6Mqmuf' === $preview, 'Показываются начало и конец' );
$assert( mb_strlen( $preview ) < 25, 'Огрызок сильно короче токена' );
$assert( ! str_contains( $token, $preview ), 'Огрызок не является куском токена целиком' );
$assert( str_starts_with( $token, mb_substr( $preview, 0, 12 ) ), 'Начало совпадает — по нему можно сверить вставленное' );
$assert( str_ends_with( $token, mb_substr( $preview, -6 ) ), 'Конец совпадает — видно, что токен не обрезан' );
$assert( '••••••••' === vkt_preview( 'короткий' ), 'Короткая строка не раскрывается вовсе' );
$assert( '••••••••' === vkt_preview( '' ), 'Пустое значение не ломает показ' );

// Два разных токена должны давать разные огрызки, иначе сверять бессмысленно.
$assert( vkt_preview( $token ) !== vkt_preview( str_replace( 'vf37d', 'ab99z', $token ) ), 'Разные токены различимы по огрызку' );

echo "All $checks offline token-preview checks passed.\n";

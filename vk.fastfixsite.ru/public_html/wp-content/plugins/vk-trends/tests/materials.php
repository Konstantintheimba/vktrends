<?php
// База ведения группы: библиотека файлов плагина, свои материалы, галочки
// назначения и то, что в итоге уходит в модель. Без сети, базы и WordPress.
define( 'ABSPATH', __DIR__ . '/' );
// Библиотека плагина сейчас пуста (методика VK Shops ушла в товарный пост),
// поэтому механизм проверяется на временной папке с одним файлом.
define( 'VKT_DIR', sys_get_temp_dir() . '/vkt-materials-' . getmypid() . '/' );
mkdir( VKT_DIR . 'materials', 0700, true );
copy( dirname( __DIR__ ) . '/prompts/vk-shops.md', VKT_DIR . 'materials/vk-shops.md' );
register_shutdown_function( static function () { @unlink( VKT_DIR . 'materials/vk-shops.md' ); @rmdir( VKT_DIR . 'materials' ); @rmdir( VKT_DIR ); } );
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

require dirname( __DIR__ ) . '/includes/class-materials.php';
require dirname( __DIR__ ) . '/includes/class-groups.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};

// Библиотека: файл методики читается, название и описание отделены от текста для модели.
$library = VKT_Materials::library();
$assert( isset( $library['vk-shops'] ) && 'Методика VK Shops' === $library['vk-shops']['title'], 'Файл из папки materials попадает в библиотеку' );
$shops = $library['vk-shops'];
$assert( '' !== $shops['description'] && ! str_contains( $shops['text'], $shops['description'] ) && ! str_contains( $shops['text'], '# Методика VK Shops' ), 'Название и описание в модель не дублируются' );
$assert( mb_strlen( $shops['text'] ) <= VKT_Materials::PROMPT_EACH, 'Методика помещается в запрос целиком' );
$assert( str_contains( $shops['text'], 'хук × подача' ) && str_contains( $shops['text'], 'Не выдумывай экспертов' ), 'В методике две оси и запрет на выдуманный опыт' );

// Подключение файла: по умолчанию — в посты, повторно не подключается.
$list = VKT_Materials::upsert( array(), array( 'file' => 'vk-shops' ) );
$assert( ! is_wp_error( $list ) && 1 === count( $list ) && $list[0]['posts'] && ! $list[0]['replies'] && preg_match( '/^m[a-f0-9]{8}$/', $list[0]['id'] ), 'Материал библиотеки подключён к постам' );
$assert( is_wp_error( VKT_Materials::upsert( $list, array( 'file' => 'vk-shops' ) ) ), 'Один файл дважды не подключается' );
$assert( is_wp_error( VKT_Materials::upsert( $list, array( 'file' => '../wp-config' ) ) ), 'Файла вне библиотеки не подключить' );

// Свой материал: нужны название и текст, теги вырезаются.
$assert( is_wp_error( VKT_Materials::upsert( $list, array( 'title' => '', 'text' => 'Правило' ) ) ) && is_wp_error( VKT_Materials::upsert( $list, array( 'title' => 'Пусто', 'text' => '' ) ) ), 'Без названия или текста материал не создаётся' );
$assert( is_wp_error( VKT_Materials::upsert( $list, array( 'title' => 'Длинный', 'text' => str_repeat( 'а', VKT_Materials::TEXT_MAX + 1 ) ) ) ), 'Слишком длинный текст не принимается' );
$list = VKT_Materials::upsert( $list, array( 'title' => "Ответы\n о цене ", 'text' => "Цену <1000 руб не называем,\r\nзовём в сообщения.\x00\x07", 'posts' => false, 'replies' => true ) );
$own = $list[1];
$assert( 'Ответы о цене' === $own['title'] && "Цену <1000 руб не называем,\nзовём в сообщения." === $own['text'] && '' === $own['file'], 'Свой материал: знак «меньше» не обрезает текст, управляющие символы убраны, название в одну строку' );
$assert( "a<b и <имя>\n\tс отступом" === VKT_Materials::plain( " a<b и <имя>\r\tс отступом " ) && '' === VKT_Materials::plain( array( 'x' ) ), 'Очистка сохраняет угловые скобки, переводы строк и табуляцию' );

// Хранение: текст файла в базу не копируется и после чтения берётся из файла.
$packed = VKT_Materials::pack( $list );
$assert( ! str_contains( $packed, 'хук × подача' ) && str_contains( $packed, 'зовём в сообщения' ), 'В базе — ссылка на файл и текст своего материала' );
$restored = VKT_Materials::normalize( $packed );
$assert( $restored == $list, 'Список восстанавливается из базы без потерь' );
$assert( array() === VKT_Materials::normalize( '[{"id":"m00000001","file":"removed-file","posts":true}]' ) && array() === VKT_Materials::normalize( 'мусор' ), 'Материал исчезнувшего файла и битый JSON не ломают список' );

// Назначение: посты и ответы получают разные материалы.
$posts = VKT_Materials::prompt( $restored, 'posts' );
$replies = VKT_Materials::prompt( $restored, 'replies' );
$assert( str_contains( $posts, '### Методика VK Shops' ) && ! str_contains( $posts, 'зовём в сообщения' ), 'В посты уходит методика, но не правила ответов' );
$assert( str_contains( $replies, '### Ответы о цене' ) && ! str_contains( $replies, 'хук × подача' ), 'В ответы уходит только материал для ответов' );

// Галочка переключается без пересылки текста; библиотечный текст правкой не подменить.
$list = VKT_Materials::upsert( $restored, array( 'id' => $restored[0]['id'], 'replies' => true, 'text' => 'подмена' ) );
$assert( $list[0]['replies'] && $list[0]['posts'] && $shops['text'] === $list[0]['text'], 'Галочка включена, текст файла плагина не изменён' );
$list = VKT_Materials::upsert( $list, array( 'id' => $own['id'], 'title' => 'Ответы о цене и доставке', 'text' => 'Новый текст' ) );
$assert( 'Новый текст' === $list[1]['text'] && $list[1]['replies'] && ! $list[1]['posts'], 'Правка своего материала не сбрасывает галочки' );
$assert( is_wp_error( VKT_Materials::upsert( $list, array( 'id' => 'mdeadbeef', 'posts' => true ) ) ), 'Правка несуществующего материала — ошибка' );
$list = VKT_Materials::upsert( $list, array( 'id' => $own['id'], 'replies' => false ) );
$assert( '' === VKT_Materials::prompt( array( $list[1] ), 'replies' ), 'Материал без галочек в модель не уходит' );

// Пределы: число материалов и объём в запросе.
$many = array();
for ( $i = 0; $i < VKT_Materials::MAX_ITEMS; $i++ ) {
    $many = VKT_Materials::upsert( $many, array( 'title' => 'Материал ' . $i, 'text' => str_repeat( 'я', 9000 ) ) );
}
$assert( VKT_Materials::MAX_ITEMS === count( $many ) && is_wp_error( VKT_Materials::upsert( $many, array( 'title' => 'Лишний', 'text' => 'текст' ) ) ), 'Больше предела материалов не добавить' );
$big = VKT_Materials::prompt( $many, 'posts' );
$assert( mb_strlen( $big ) <= VKT_Materials::PROMPT_TOTAL + 200 && str_contains( $big, '### Материал 1' ) && ! str_contains( $big, '### Материал 5' ), 'Объём материалов в запросе ограничен' );
$assert( 1 === count( VKT_Materials::remove( array_slice( $many, 0, 2 ), $many[0]['id'] ) ), 'Материал убирается по ID' );

// Контекст группы: материалы идут вместе с паспортом, а без паспорта — сами по себе.
$group = array( 'group_id' => 111, 'name' => 'Дом и уют', 'screen_name' => 'home', 'passport' => "# Паспорт\n- Обращение: на вы", 'materials' => $packed );
$context = VKT_Groups::context( $group, false );
$assert( str_contains( $context, 'на вы' ) && str_contains( $context, 'хук × подача' ) && str_contains( $context, 'главнее паспорт' ) && ! str_contains( $context, 'зовём в сообщения' ), 'Пост получает паспорт и методику' );
$assert( strpos( $context, 'на вы' ) < strpos( $context, 'хук × подача' ), 'Паспорт стоит раньше материалов' );
$context = VKT_Groups::context( $group, false, 'replies' );
$assert( str_contains( $context, 'зовём в сообщения' ) && ! str_contains( $context, 'хук × подача' ), 'Ответы на комментарии получают свои материалы' );
$group['passport'] = '';
$assert( str_contains( VKT_Groups::context( $group, false ), 'хук × подача' ), 'Без паспорта материалы всё равно уходят в модель' );
$group['materials'] = null;
$assert( '' === VKT_Groups::context( $group, false ), 'Группа без паспорта и материалов контекста не даёт' );

echo "PASS: $checks materials checks\n";

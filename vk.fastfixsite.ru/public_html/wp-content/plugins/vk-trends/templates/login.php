<?php
defined( 'ABSPATH' ) || exit;
// Экран вместо дашборда: гость, заявка на рассмотрении или закрытый кабинет.
$vkt_status = VKT_Account::status();
$vkt_user = wp_get_current_user();
$vkt_error = VKT_Login::notice();
$vkt_logout = wp_logout_url( VKT_Plugin::dashboard_url() );
$vkt_screens = array(
    'guest' => array( 'Вход в VK Trends', 'Радар трендов VK и автопостинг в свои сообщества. Войдите через VK ID — у каждого пользователя свой кабинет: источники, подборки и очередь публикаций.' ),
    'pending' => array( 'Заявка отправлена', 'Кабинет откроется, когда администратор одобрит заявку. Эту страницу можно обновить позже — входить заново не нужно.' ),
    'blocked' => array( 'Доступ закрыт', 'Администратор закрыл доступ к этому кабинету. Если это ошибка, свяжитесь с ним.' ),
    'denied' => array( 'Нет доступа', 'У этой учётной записи WordPress нет доступа к VK Trends. Выйдите и войдите через VK ID.' ),
);
list( $vkt_title, $vkt_text ) = $vkt_screens[ $vkt_status ] ?? $vkt_screens['guest'];
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <?php wp_head(); ?>
</head>
<body class="vkt-standalone">
<?php wp_body_open(); ?>
<main class="vkt-app vkt-login">
    <section class="vkt-login-card">
        <div class="vkt-brand"><span class="vkt-brand-icon">vk</span><span>trends<span class="vkt-beta">BETA</span></span></div>
        <h1><?php echo esc_html( $vkt_title ); ?></h1>
        <p class="vkt-muted"><?php echo esc_html( $vkt_text ); ?></p>
        <?php if ( '' !== $vkt_error ) : ?>
            <div class="vkt-info vkt-info-warning" role="alert"><?php echo esc_html( $vkt_error ); ?></div>
        <?php endif; ?>
        <?php if ( 'guest' === $vkt_status ) : ?>
            <?php if ( VKT_Login::available() ) : ?>
                <a class="vkt-button vkt-primary vkt-login-vk" href="<?php echo esc_url( VKT_Login::url() ); ?>">Войти через VK ID</a>
                <p class="vkt-help">Сайт получит только ваше имя, аватар и ID во ВКонтакте. Доступ к стенам и группам для публикации подключается отдельно, уже в кабинете.</p>
            <?php else : ?>
                <div class="vkt-info">Вход через VK ID ещё не настроен администратором.</div>
            <?php endif; ?>
            <a class="vkt-login-admin" href="<?php echo esc_url( wp_login_url( VKT_Plugin::dashboard_url() ) ); ?>">Вход для администратора</a>
        <?php else : ?>
            <div class="vkt-login-user">
                <?php $vkt_avatar = (string) get_user_meta( $vkt_user->ID, 'vkt_avatar', true ); ?>
                <?php if ( '' !== $vkt_avatar ) : ?><img src="<?php echo esc_url( $vkt_avatar ); ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
                <strong><?php echo esc_html( $vkt_user->display_name ); ?></strong>
            </div>
            <a class="vkt-button" href="<?php echo esc_url( $vkt_logout ); ?>">Выйти</a>
        <?php endif; ?>
    </section>
</main>
<?php wp_footer(); ?>
</body>
</html>

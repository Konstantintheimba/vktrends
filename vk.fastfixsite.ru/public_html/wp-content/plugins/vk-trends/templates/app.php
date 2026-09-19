<?php
defined( 'ABSPATH' ) || exit;
if ( ! VKT_Account::can_use() ) { return; }
// Общий сбор, журнал и ключи сайта — только администратору; остальное у каждого своё.
$vkt_admin = VKT_Account::is_admin();
$vkt_profile = VKT_Account::profile();
$vkt_initial = mb_substr( $vkt_profile['name'] ?: 'VK', 0, 1 );
$vkt_pending = $vkt_admin ? VKT_Account::pending_count() : 0;
?>
<div id="vkt-app" class="vkt-app">
    <aside class="vkt-sidebar">
        <a href="#overview" class="vkt-brand"><span class="vkt-brand-icon">vk</span><span>trends<span class="vkt-beta">BETA</span></span></a>
        <div class="vkt-workspace"><?php if ( $vkt_profile['avatar'] ) : ?><img class="vkt-workspace-photo" src="<?php echo esc_url( $vkt_profile['avatar'] ); ?>" alt="" referrerpolicy="no-referrer"><?php else : ?><span class="vkt-workspace-icon"><?php echo esc_html( $vkt_initial ); ?></span><?php endif; ?><div><strong><?php echo esc_html( $vkt_profile['name'] ); ?></strong><small><?php echo $vkt_admin ? 'Администратор' : 'Личный кабинет'; ?></small></div></div>
        <div class="vkt-nav-caption">РАБОЧЕЕ ПРОСТРАНСТВО</div>
        <nav aria-label="Разделы дашборда">
            <a href="#overview" data-nav="overview"><span data-icon="grid"></span>Обзор</a>
            <a href="#discover" data-nav="discover"><span data-icon="search"></span>Поиск трендов</a>
            <a href="#posts" data-nav="posts"><span data-icon="post"></span>Посты<span id="vkt-post-count" class="vkt-nav-count">0</span></a>
            <a href="#communities" data-nav="communities"><span data-icon="people"></span>Сообщества</a>
            <a href="#publishing" data-nav="publishing"><span data-icon="send"></span>Автопостинг</a>
            <a href="#series" data-nav="series"><span data-icon="clock"></span>Серия постов</a>
            <a href="#videos" data-nav="videos"><span data-icon="play"></span>Мои ролики<span id="vkt-video-count" class="vkt-nav-count">0</span></a>
            <a href="#products" data-nav="products"><span data-icon="bag"></span>Товары</a>
            <a href="#sources" data-nav="sources"><span data-icon="layers"></span>Источники</a>
        </nav>
        <div class="vkt-nav-caption">ПОДКЛЮЧЕНИЯ</div>
        <nav aria-label="Подключения">
            <?php if ( $vkt_admin ) : ?><a href="#reading" data-nav="reading"><span data-icon="eye"></span>Чтение постов<span id="vkt-reading-dot" class="vkt-nav-dot"></span></a><?php endif; ?>
            <a href="#posting" data-nav="posting"><span data-icon="post"></span>Публикация<span id="vkt-posting-dot" class="vkt-nav-dot"></span></a>
            <a href="#attachments" data-nav="attachments"><span data-icon="plus"></span>Что можно прикрепить</a>
        </nav>
        <?php if ( $vkt_admin ) : ?>
        <div class="vkt-nav-caption">ИНСТРУМЕНТЫ</div>
        <nav aria-label="Инструменты">
            <a href="#users" data-nav="users"><span data-icon="people"></span>Пользователи<span id="vkt-users-count" class="vkt-nav-count" <?php echo $vkt_pending ? '' : 'hidden'; ?>><?php echo (int) $vkt_pending; ?></span></a>
            <a href="#api" data-nav="api"><span data-icon="code"></span>Тест API<span class="vkt-nav-dot"></span></a>
            <a href="#collector" data-nav="collector"><span data-icon="refresh"></span>Сбор данных</a>
            <a href="#logs" data-nav="logs"><span data-icon="list"></span>Журнал</a>
            <a href="#settings" data-nav="settings"><span data-icon="settings"></span>Настройки</a>
        </nav>
        <?php endif; ?>
        <div class="vkt-sidebar-bottom"><div id="vkt-connection" class="vkt-connection">Проверяем подключение…</div><?php if ( $vkt_admin ) : ?><a href="<?php echo esc_url( admin_url() ); ?>" class="vkt-wp-link">↗ Админка WordPress</a><?php endif; ?><a href="<?php echo esc_url( wp_logout_url( VKT_Plugin::dashboard_url() ) ); ?>" class="vkt-wp-link">Выйти</a></div>
    </aside>
    <div class="vkt-main">
        <header class="vkt-topbar"><button class="vkt-icon-button vkt-menu" type="button" aria-label="Открыть меню" aria-expanded="false" data-command="menu"><span data-icon="menu"></span></button><div class="vkt-breadcrumb">Workspace <span>/</span> <strong id="vkt-breadcrumb">Обзор</strong></div><div class="vkt-topbar-right"><span class="vkt-private"><span data-icon="lock"></span>Личный кабинет</span><?php if ( $vkt_profile['avatar'] ) : ?><img class="vkt-avatar" src="<?php echo esc_url( $vkt_profile['avatar'] ); ?>" alt="<?php echo esc_attr( $vkt_profile['name'] ); ?>" referrerpolicy="no-referrer"><?php else : ?><span class="vkt-avatar"><?php echo esc_html( $vkt_initial ); ?></span><?php endif; ?></div></header>
        <main id="vkt-content" class="vkt-content" tabindex="-1"><div class="vkt-loading">Загружаем дашборд…</div></main>
        <footer class="vkt-footer"><span>VK Trends <span class="vkt-muted">/ <?php echo esc_html( VKT_VERSION ); ?></span></span><span>Находите видео. Наблюдайте за ростом.</span></footer>
    </div>
    <div id="vkt-toast" class="vkt-toast" role="status" aria-live="polite" hidden></div>
    <dialog id="vkt-dialog" class="vkt-dialog"><button type="button" class="vkt-dialog-close vkt-icon-button" data-command="close" aria-label="Закрыть">×</button><div id="vkt-dialog-content"></div></dialog>
    <noscript><p>Для работы дашборда включите JavaScript.</p></noscript>
</div>

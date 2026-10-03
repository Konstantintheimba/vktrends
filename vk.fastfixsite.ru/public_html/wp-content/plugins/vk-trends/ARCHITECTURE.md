# VK Trends — техническая документация

Версия: **0.33.0**. Плагин WordPress для наблюдения за постами/видео сообществ VK, личных кабинетов, автопостинга и генерации картинок. Требования: WP 6.6+, PHP 8.0+, MySQL/MariaDB (InnoDB), OpenSSL, исходящий HTTPS к `api.vk.com`, `id.vk.ru`, `oauth.vk.com`, `api.bfl.ai`.

> Это справочник по **коду**, а не по функциональности. Что умеет плагин по версиям — в `README.md`. Здесь — как он устроен внутри и куда смотреть при правке.

---

## 1. Общий принцип: статических классов, конструкторов нет

Во всём плагине **нет ни одного объекта с состоянием и ни одного `__construct`**. Все 26 классов — `final class X` из **статических методов**. Состояние живёт не в объектах, а в:

- **таблицах БД** (`wp_vkt_*`, префикс `VKT_Store::table()`);
- **опциях WordPress** (`vkt_settings`, `vkt_tokens`, `vkt_db_version`, `vkt_owner`, `vkt_page_*`, блокировки `vkt_lock_*`);
- **usermeta** (`vkt_community_id`, `vkt_publishing_review`, `vkt_status`, `vkt_limits`, `vkt_tokens`, `vkt_group_keys`, `vkt_ai_usage`, `vkt_vk_id`, `vkt_avatar`, `vkt_last_login`);
- **константах `wp-config.php`** (только для хозяина сайта).

«Конструктором» по смыслу служат три точки инициализации:

| точка | кто вызывает | что делает |
|---|---|---|
| `VKT_Plugin::boot()` | конец `vk-trends.php` | вешает хуки WP, cron, REST, шаблоны |
| `VKT_Plugin::activate()` | `register_activation_hook` | ставит таблицы, настройки, страницы, cron |
| `VKT_Store::install()` | `init` при смене версии | `dbDelta` обновляет схему + миграции |

Если ищете «где инициализация» — это `vk-trends.php:47` → `VKT_Plugin::boot()`.

---

## 2. Точка входа

`vk-trends.php` (47 строк) — только метаданные плагина + `require_once` всех классов в нужном порядке + `boot()`. Порядок подключения важен: классы не используют автозагрузку, а полагаются на порядок `require_once` в этом файле. Новый класс добавляется сюда же.

Три ключевых события cron (регистрируются в `boot()`):

- `vkt_collect` — раз в минуту → `VKT_Collector::run()` (сбор постов/видео);
- `vkt_publish` — раз в минуту → `VKT_Publisher::run_due()` (автопостинг) **и** `VKT_Replies::cron()` (ответы на комментарии — тем же событием, отдельное не заводится).

---

## 3. Каталог классов

Все в `includes/`. Для каждого — назначение и главные публичные методы.

### Ядро и хранение

- **`VKT_Store`** (`class-store.php`) — слой БД.
  - `table($name)` → `$wpdb->prefix . 'vkt_' . $name`;
  - `install()` — `dbDelta` создаёт/обновляет все таблицы (собирает схему из `VKT_Posts::schema()`, `VKT_Links::schema()`, `VKT_Publisher::schema()`, `VKT_Subscriptions::schema()`, `VKT_Replies::schema()`), плюс миграции версий;
  - `lock($name,$ttl)` / `unlock($name)` — атомарные блокировки через `add_option` (`vkt_lock_<name>`, TTL в секундах). Работают между PHP-воркерами;
  - `log($method,$context,$status,$code,$message,$ms)` — журнал (без токенов/параметров);
  - `state($page,$search,$sort)` — агрегат главного экрана;
  - `parse_video`, `videos_from_posts`, `save_video` — работа с роликами.

- **`VKT_Plugin`** (`class-plugin.php`, ~784 стр.) — диспетчер всего фронтенда.
  - `boot()` — хуки; `activate()` / `deactivate()` / `defaults()` / `settings()` / `public_settings()`;
  - `routes()` — регистрирует REST `vk-trends/v1/*`;
  - `action($request)` — **главный switch** на ~70 действий из фронта (см. §5);
  - `is_dashboard()`, `dashboard_url()`, `assets()` — определение дашборда и подключение ассетов.

### Аккаунты и ключи

- **`VKT_Account`** (`class-account.php`) — личные кабинеты.
  - Роль `vkt_member`, право `vkt_access`; статусы `pending/active/blocked` (+ `guest/denied` вычисляемые);
  - `id()`, `act_as($user_id, callable)` — смена исполнителя (важно для cron);
  - `is_admin()`, `owner()`, `is_owner()` — администратор и хозяин (`vkt_owner` = первый админ);
  - `get()` / `set()` — личные настройки в `usermeta`;
  - `limit($key)`, `set_limits()`, `limits_view()` — лимиты кабинета (ключи `text/media/sources/replies_queue` в `vkt_limits`);
  - `ai_allow()` / `ai_spend()` — суточный лимит генерации (списывается только удачная);
  - `members()`, `set_status()`, `purge()` — раздел «Пользователи».

- **`VKT_Tokens`** (`class-tokens.php`) — хранилище ключей по слотам.
  - Слоты: `service` (читает стены), `user` (грузит файлы/публикует), `community` (публикует текст своей группы), `bfl` (BFL), `app_secret` (защищённый ключ приложения);
  - `get()`, `token()`, `has()`, `alive()`, `save()`, `forget()`, `note($slot,$msg,$dead)`;
  - `seal()` / `unseal()` — AES-256-GCM на ключе от auth salt; `group_keys()` / `save_group_key()` — ключи сообществ (usermeta `vkt_group_keys`);
  - `migrate_to_owner()` — перенос общих ключей в кабинет хозяина.

- **`VKT_Community`** (`class-community.php`) — ключ сообщества + Callback API.
  - `group_id()`, `token()`, `configured()`, `keys()`, `has_key($group_id)`, `add_key()`, `forget_key()`;
  - `check()` — проверка ключа (`groups.getTokenPermissions`);
  - `publish($params)` / `comment($params)` — запись/комментарий ключом сообщества;
  - `callback()` / `process($raw)` — приём событий Callback API (проверка `secret`, `confirmation`).

- **`VKT_Login`** (`class-login.php`) — вход участника через VK ID (PKCE, подписанная HttpOnly-cookie `vkt_login`, 15 мин). `boot()`, `start()`, `maybe_capture()`, `finish()`.

- **`VKT_VKID`** (`class-vkid.php`) — OAuth VK ID для получения **личного** пользовательского токена. `start()`, `finish()`, `maybe_capture()`, `client_id()`, `redirect_uri()`.

- **`VKT_OAuth`** (`class-oauth.php`) — классический обмен кода на токен (`oauth.vk.com`, приложение из `dev.vk.ru`). `authorize_url()`, `exchange($code)`, `check_app()`, `check_secret()`, `parse_code()`.

### API и данные VK

- **`VKT_API`** (`class-api.php`, ~715 стр.) — клиент API VK.
  - `methods()` — каталог разрешённых методов из `includes/methods.json`;
  - `slot_for($method)` — выбор слота под метод (`USER_ONLY`, `USER_FIRST`, иначе `service`);
  - `request($method,$params,$context)` — чтение с валидацией параметров по каталогу;
  - `publishing_request()` — **закрытый** список методов для модуля публикаций (произвольный метод сюда не пробросить);
  - `dispatch()` — сам запрос: обновление токена, блокировка темпа `vkt_lock_api` (1 запрос/сек, ожидание до 5 сек), маппинг кодов ошибок `message()`;
  - `check_token()`, `probe_slot()`, `probe_matrix()` — «Стенд постинга» и проверка ключей;
  - `maybe_refresh()` — автообновление пользовательской пары за 5 мин до истечения (`id.vk.ru/oauth2/auth`).

### Сбор, посты, подписки

- **`VKT_Collector`** (`class-collector.php`) — обход источников.
  - `run($manual)`, `enqueue($kind,$id)`, `process($job)`, `tick()`, `measure_video_point()`, `resolve_links()`, `save_posts()`.

- **`VKT_Posts`** (`class-posts.php`) — посты + агрегаты динамики.
  - `schema()`, `save($item,$source_id,$members)`, `query($args)`, `communities()`, `history($id)`, `deltas()` (окна `g1..g30`, `velocity`, `err`, `viral`).

- **`VKT_Subscriptions`** (`class-subscriptions.php`) — кто что видит.
  - `subscribe()`, `toggle()`, `unsubscribe()`, `sees_post()`, `sees_video()`, `count()`, `claim_video()`, `track_own()`.

### Публикация и комментарии

- **`VKT_Publisher`** (`class-publisher.php`, ~1050 стр.) — автопостинг и серии.
  - `create($data)`, `create_series()`, `cancel_series()`, `update()`, `get()`, `cancel()`, `approve()`, `retry()`, `run_due($limit,$post_id,$user_id)`, `attempt($delivery)`, `sync_groups()`, `state()`, `upload_targets()`;
  - `use_community_key($group_id,$media)` — решение, каким ключом публиковать (ключ сообщества для своей группы, пользовательский токен грузит файлы).

- **`VKT_Replies`** (`class-replies.php`, ~905 стр.) — ответы на комментарии.
  - `scan()`, `inbox()`, `posts()`, `thread()`, `enqueue()`, `run_due()`, `attempt()`, `cron()`, `ingest()`, `forget_comment()`.

### Группы, генерация, материалы

- **`VKT_Groups`** (`class-groups.php`) — «Мои сообщества».
  - `group()`, `by_vk()`, `sync_tracking()`, `digest()`, `refresh_stats()`, `context($group,$with_history,$purpose)` (сборка контекста для модели), `save_passport()`, `save_material()`, `save_news()`, `collect_news()`.

- **`VKT_AI`** (`class-ai.php`, ~810 стр.) — генерация текста/картинок/видео + поиск.
  - `chat()`, `generate_text()`, `generate_series()`, `generate_replies()`, `generate_shop_post()`, `generate_news()`, `draft_passport()`;
  - `search()` / `search_news()` — интернет-поиск (xAI `web_search`, OpenRouter `web`);
  - `generate_image()`, `start_video()`, `video_status()`;
  - реестр моделей `vkt_text_models` (`save_model`, `set_default_model`, `check_model`).

- **`VKT_Flux`** (`class-flux.php`) — BFL (Black Forest Labs) генерация фото. `start()`, `status()`, `credits()`, `probe()`, `moderation()`.

- **`VKT_Images`** (`class-images.php`) — реестр поставщиков картинок. `providers()`, `start($id,$prompt,$ratio)`, `status($id)`.

- **`VKT_Materials`** (`class-materials.php`) — база ведения группы (`materials/*.md`). `library()`, `normalize()`, `pack()`, `upsert()`, `prompt($list,$purpose)`.

- **`VKT_News`** (`class-news.php`) — новостная группа (RSS/поиск). `settings()`, `discover()`, `parse()`, `collect()`, `search()`, `compose()`.

- **`VKT_Shops`** (`class-shops.php`) — методика VK Shops («Товарный пост»). `brief($data)`, `options()`.

### Товары и ссылки

- **`VKT_Commerce`** (`class-commerce.php`) — извлечение товара из текста поста. `extract($item)`, `from_text()`, `candidate()`, `market_id()`.

- **`VKT_Product_Page`** (`class-product-page.php`) — парсер страницы магазина (JSON-LD Product, микроданные, OpenGraph). `parse($html,$url)`.

- **`VKT_Links`** (`class-links.php`) — справочник ссылок магазинов. `register()`, `resolve($id,$context)`, `parse()`, `proxy()`, `due()`, `schema()` (таблица `shop_links`), `is_guard_page()`.

- **`VKT_Media`** (`class-media.php`) — медиатека WP как источник вложений + загрузка в VK. `library()`, `handle_upload()`, `sideload()`, `prepare_for_vk()`, `upload_photo()`, `upload_video()`.

- **`VKT_Health`** (`class-health.php`) — неполадки и «где чинить». `issues()`, `fix_for($slot,$code)`.

---

## 4. База данных

Все таблицы с префиксом `wp_vkt_` (создаёт `VKT_Store::install()` через `dbDelta`). Сводка:

| таблица | назначение | ключ |
|---|---|---|
| `videos` | ролики | `(owner_id, video_id)` |
| `snapshots` | замеры счётчиков роликов | `(video_id, measured_at)` |
| `posts` | посты сообществ + агрегаты (`g1..g30`, `err`, `viral`) | `(owner_id, post_id)` |
| `post_products` | товары, привязанные к посту | `(post_id, item_index)` |
| `post_snapshots` | история счётчиков постов | `(post_id, measured_at)` |
| `products` | товары кабинета (user_id) | `id` |
| `links` | связь ролик ↔ товар | `(video_id, product_id)` |
| `sources` | источники (общие) | `(kind, value)` |
| `jobs` | очередь заданий сборщика | `job_key` |
| `logs` | журнал запросов (30 дней) | `id` |
| `shop_links` | справочник товарных ссылок | `id` |
| `publishing_groups` | группы автопостинга (паспорт, материалы, новости, callback) | `id` |
| `outbound_posts` | записи очереди публикации | `id` |
| `outbound_deliveries` | доставка записи по адресатам (группам) | `id` |
| `subscriptions` | подписки кабинета на источники | `(user_id, source_id)` |
| `user_videos` | ролики кабинета | `(user_id, video_id)` |
| `comment_inbox` | лента комментариев (есть колонка `media` для вложений) | `id` |
| `comment_replies` | очередь/статусы ответов на комментарии | `id` |

Миграции версий выполняются **внутри `install()`** по `version_compare(vkt_db_version, …)` — см. `class-store.php` (переходы 0.10.1, 0.11.2, 0.22.0). Новые колонки описываются в соответствующем `schema()` и подхватываются `dbDelta` автоматически.

---

## 5. REST API и диспетчер действий

Фронтенд (`assets/dashboard.js`, ~2900 строк) общается с сервером двумя путями:

**REST GET** (`VKT_Plugin::routes()`, namespace `vk-trends/v1`): `/state`, `/posts`, `/communities`, `/publishing`, `/groups`, `/comments`, `/media`, `/media-upload` (POST multipart), `/post-history/{id}`, `/history/{id}`, `/users` (только админ). Доступ — `VKT_Account::can_use()` + nonce `X-WP-Nonce`.

**REST POST `/action`** → `VKT_Plugin::action()`. Это **единый switch** на ~70 действий. Список действий и их обработчиков — прямо в `class-plugin.php:316-754`. Ключевые группы:

- настройки/токены: `settings`, `token_save`, `token_forget`, `token_probe`, `token_check`, `oauth_check`, `oauth_exchange`;
- сбор: `collect`, `retry`, `source`, `sources_import`, `source_toggle`, `search`, `save_video`;
- публикация: `publishing_sync`, `publishing_create`, `publishing_run`, `publishing_approve/retry/cancel`, `publishing_update/get`, `series_generate/queue/cancel`;
- комментарии: `comments_inbox`, `comments_scan`, `comments_generate`, `comments_reply`, `comments_queue`, `comments_run/cancel/retry`, `comments_posts`, `comments_thread`;
- группы: `group_detail`, `group_hide`, `group_passport`, `group_passport_draft`, `group_material_save/delete`, `group_news_*`, `group_stats`;
- генерация: `ai_text`, `ai_image`, `ai_video_start/status`, `image_start/status`, `shop_post`, `flux_start/status/credits`;
- админ: `user_status`, `user_limits`, `ai_model_*`, `vkid_start`;
- прочее: `product`, `link`, `enqueue`, `delete`, `resolve_link`.

**Как добавить новое действие:** дописать `case 'имя':` в `action()` + обработчик в `assets/dashboard.js`. Если действие админское — добавить его имя в `VKT_Plugin::ADMIN_ACTIONS`.

---

## 6. Ключи и «кто каким публикует»

Три слота VK (см. `VKT_Tokens::definitions()`):

| слот | что делает | константа в `wp-config.php` |
|---|---|---|
| `service` | читает стены (`wall.get`, счётчики) | `VKT_ACCESS_TOKEN` |
| `user` | грузит фото/видео, список своих групп, публикует текст | — |
| `community` | публикует текст на стене **своей** группы | `VKT_COMMUNITY_ACCESS_TOKEN` |

Загрузка медиа в VK идёт только пользовательским токеном (`photos.getWallUploadServer` → multipart → `photos.saveWallPhoto`). Ключ сообщества медиа не грузит (ошибка 27). Поэтому запись с файлами = **файл грузит `user`, публикует `community`** (`VKT_Publisher::use_community_key()`). Константы действуют только для хозяина (`VKT_Account::owner()`), чтобы чужой кабинет не публиковал ключом хозяина.

---

## 7. Cron и очереди

- WP-cron крутится **только при заходах на сайт** — на боевом хостинге нужен системный cron раз в минуту на `wp-cron.php` (см. README, раздел «Расписание на хостинге»).
- У cron нет пользователя → очередь публикаций выполняет каждое задание через `VKT_Account::act_as(автор)`, сборщик — от имени хозяина. **Это центральная идея личных кабинетов**: любой фоновый код, читающий личные ключи, обязан обернуться в `act_as()`.
- Темп запросов к VK — блокировка `vkt_lock_api` (1 запрос/сек). Сборщик — `vkt_lock_collector` (120 сек). Обновление токена — `vkt_lock_token_refresh_<id>`.
- Очередь ответов на комментарии: не больше одного ответа в одну группу за проход + пауза по группе; при флуд-контроле VK (коды 6/9/14/29) пачка сдвигается на 30 мин → час → два → четыре без списания попыток (`VKT_Replies::hold()`).

---

## 8. Как править (порядок действий)

1. **Найти класс** — по таблице §3. Практически всё — статические методы, прямых зависимостей мало.
2. **Правка** — сразу в файл плагина (`vk.fastfixsite.ru/public_html/wp-content/plugins/vk-trends/`). Архив не собирать, `.zip` пользователю мешает.
3. **Новый класс** — файл `includes/class-*.php` + одна строка `require_once` в `vk-trends.php`.
4. **Новая колонка/таблица** — описать в `schema()` соответствующего класса; `dbDelta` подхватит при смене версии в заголовке `vk-trends.php`.
5. **Новое действие фронта** — `case` в `VKT_Plugin::action()` + обработчик в `dashboard.js`.
6. **Прогнать тесты** (см. §9) и перечислить изменённые файлы (новые — отдельно).
7. Комментарии и интерфейс — **на русском**, комментарий объясняет *почему*, а не пересказывает код.

Константы для хозяина сайта добавляются в `wp-config.php` (`VKT_*`) и читаются через `VKT_Tokens::constant_value()`.

---

## 9. Тесты

Офлайн (без сети и WordPress):

```
php tests/<имя>.php            # любой офлайн-тест
node tests/dashboard-commerce.cjs
```

Что есть: `commerce.php`, `community.php`, `flux.php`, `materials.php`, `media.php`, `news.php`, `oauth.php`, `publisher.php`, `replies.php`, `replies-ai.php`, `settings-token.php`, `shops.php`, `token-preview.php`, `tokens.php`, `vkid.php`, `matrix.php`.

Интеграционные (`*integration.php`, `accounts-integration.php`) — только в изолированном WordPress на `http://127.0.0.1:8097` (ядро + wp-cli в scratchpad, база `vkt_test` в локальном MySQL). `tests/integration.php` устарел с 0.19.2 и падает ещё до правок.

Линт: `php -l` на PHP-файлах, `node --check assets/dashboard.js`.

---

## 10. Самые «узкие» места, где чаще всего ломается

- **`VKT_API::dispatch()`** — темп запросов, слоты, маппинг ошибок. Любая проблема «почему VK вернул X» ведёт сюда.
- **`VKT_Plugin::action()`** — один switch на весь фронт; осиротевший `case` = молча неработающая кнопка.
- **`VKT_Publisher::attempt()` / `VKT_Replies::attempt()`** — отправка в VK и повторы; правки очередей и живучести — здесь.
- **`VKT_Store::install()`** — миграции схемы; ошибка тут ломает весь плагин при обновлении.
- **`VKT_Account::act_as()`** — контекст исполнителя; фоновый код без него не видит личных ключей.

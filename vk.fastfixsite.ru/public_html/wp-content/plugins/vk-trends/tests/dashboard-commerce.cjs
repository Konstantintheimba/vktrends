// Offline template/event tests in Node; no browser, DOM engine or network.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const listeners = {};
const requests = [];
const element = {innerHTML: '', textContent: '', classList: {toggle() {}}, setAttribute() {}, querySelector() { return null; }};
const root = {querySelector: () => element, querySelectorAll: () => [], addEventListener: (name, handler) => { listeners[name] = handler; }};
const communities = [{id: 1, kind: 'domain', value: 'first', title: 'Первое'}, {id: 2, kind: 'owner', value: '-2', title: 'Второе <script>alert(1)</script>'}];
const state = {sources: communities, stats: {videos: 0, posts: 0}, settings: {has_token: false, paused: false}};
const data = {posts: [], communities, summary: {recognized_posts: 0}, total: 0, pages: 1};
const uploaded = {id: 42, type: 'image', name: 'promo.jpg', title: 'promo', size: 204800, url: 'https://example.test/promo.jpg', thumbnail: 'https://example.test/promo-medium.jpg'};
const context = {
    window: {vktConfig: {initialView: 'posts', rest: 'https://example.test/wp-json/vk-trends/v1/', account: {id: 1, name: 'Админ', is_admin: true, status: 'active'}}, addEventListener() {}},
    document: {getElementById: () => root}, location: {hash: '#posts'}, URL, URLSearchParams, Intl, Date,
    setTimeout: () => 0, clearTimeout() {}, FormData: function (form) { if (form) return Object.entries(form.values); this.append = () => {}; },
    fetch: async url => {
        requests.push(new URL(url));
        if (String(url).includes('media-upload')) return {ok: true, json: async () => uploaded};
        return {ok: true, json: async () => String(url).includes('/state?') ? state : data};
    },
};
const source = fs.readFileSync(require.resolve('../assets/dashboard.js'), 'utf8');
// Replace only the initial async load with access to the real template functions.
const tail = source.lastIndexOf('    load().catch(');
assert(tail > 0);
vm.runInNewContext(source.slice(0, tail) + `
    window.testAPI = {postProduct, postRow, posts, viewData, mediaChip, publishing, settings, reading, posting, attachments, users, flux, overview, initial, seriesPlan, series, seriesCalendar, setSeries(slots) {seriesSlots=slots;}, getSeries() {return seriesSlots;}, setPublishing(value) {publishingData=value;}, setUsers(value) {usersData=value;}, setAccount(value) {account=value;}, init(s,d) {state=s;postsData=d;}, getSource() {return postsSource;}, getMedia() {return composerMedia;}, comments, setFeed(d, feed) {commentsMode='feed';commentsData=d;commentsGroup=Number(d.groups[0]?.group_id||0);commentsFeed=feed;indexThread();}, setComments(d, p, post, thread) {commentsMode='posts';commentsData=d;commentsGroup=Number(d.groups[0]?.group_id||0);commentsPosts=p;commentsPost=post;commentsThread=thread;indexThread();}, pick(key) {commentsSelected.set(key, commentPayload(key));}, selected() {return commentsSelected;}, healthBanner, toast, fixFor, hasSlot};
})();`, context);
const api = context.window.testAPI;
api.init(state, data);
const post = {id: 17, product_items: [
    {url: 'https://ozon.ru/product/1234567?x=1&y=2', title: 'Подсказка', price: 600, source: 'text', recognized: false, link_id: 8, page: {status: 'ok', title: 'Футболка <script>alert(1)</script>', price: 799, shop: 'Ozon'}},
    {url: 'https://vk.ru/market-1_2', title: 'Лампа', price: 1999, source: 'market', recognized: true, market_id: '-1_2'},
]};
let checks = 0;
function check(value, message) { assert(value, message); checks++; }
let html = api.postProduct(post);
check(html.includes('Футболка &lt;script&gt;') && !html.includes('<script>'), 'Untrusted product title is escaped');
check(html.includes('x=1&amp;y=2'), 'Attribution query is preserved and escaped');
check(html.includes('799') && !html.includes('>600'), 'Page price belongs to the matching product');
check(html.includes('<details') && html.includes('Ещё ссылок: 1') && html.includes('https://vk.ru/market-1_2'), 'Secondary products have separate names and links');
check(html.includes('со страницы магазина') && html.includes('из вложения VK'), 'Source labels distinguish page data from VK');
html = api.postProduct({id: 17, product_items: [{url: 'javascript:alert(1)', title: '', link_id: 8, page: {status: 'blocked'}}]});
check(!html.includes('href="javascript:'), 'Unsafe product URL is not rendered');
check(html.includes('Название товара не определено') && html.includes('Магазин закрыл доступ'), 'Unknown title and blocked page remain explicit');
check(html.includes('data-link="8"') && html.includes('data-id="17"'), 'Retry targets the selected product within its post');
html = api.posts();
check(html.includes('name="source"') && html.includes('Все сообщества'), 'Community selector is visible above the feed');
check(html.includes('Второе &lt;script&gt;') && !html.includes('<script>'), 'Community option titles are escaped');

(async () => {
    let submitted = false;
    listeners.change({target: {matches: selector => selector.includes('posts-search'), form: {requestSubmit() {submitted = true;}}}});
    check(submitted, 'Changing the community submits the filter');
    await listeners.change({target: {matches: selector => selector.includes('data-media-upload'), files: [{name: 'promo.jpg'}], value: 'promo.jpg', closest: () => null}});
    check(context.window.testAPI.getMedia().length === 1 && context.window.testAPI.getMedia()[0].id === 42, 'Загруженный файл попадает в конструктор записи');
    check(requests.some(url => url.pathname.endsWith('/media-upload')), 'Файл уходит отдельным multipart-маршрутом');
    const chip = api.mediaChip(uploaded);
    check(chip.includes('promo-medium.jpg') && chip.includes('200 КБ') && chip.includes('data-command="media-remove"'), 'Чип файла показывает превью, размер и кнопку удаления');
    check(!api.mediaChip({...uploaded, thumbnail: 'javascript:alert(1)'}).includes('javascript:'), 'Небезопасный адрес превью не выводится');
    const form = {dataset: {form: 'posts-search'}, values: {search: 'лампа', sort: 'views', source: '2'}, querySelector: () => null};
    await listeners.submit({target: {closest: () => form}, preventDefault() {}});
    check(api.getSource() === 2, 'Selected community ID stored');
    const query = requests.find(url => url.pathname.endsWith('/posts'));
    check(query.searchParams.get('source') === '2' && query.searchParams.get('page') === '1' && query.searchParams.get('search') === 'лампа', 'Request combines community, search, sort and reset pagination');
    check(api.posts().includes('value="2" selected'), 'Selection survives re-render');
    const button = {dataset: {command: 'posts-filters-clear'}, disabled: false};
    await listeners.click({target: {closest: () => button}});
    check(api.getSource() === 0, 'Clear filters restores all communities');
    // Раздел автопостинга целиком: ошибка в шаблоне ломает всю вкладку.
    api.setPublishing({
        groups: [{id: 3, group_id: 987, name: 'Моя группа', screen_name: 'my_group', enabled: 1, can_post: 1, photo: 'https://vk.test/g.jpg'}],
        posts: [{id: 5, message: 'Текст', attachments: '', media_items: [uploaded], origin: 'manual', status: 'published', scheduled_at: '2026-09-14 04:00:00', deliveries: []}],
        status: {token_ready: true, community_only: false, media_native: true, media_limit: 10, ai: {configured: true, media_configured: true, models: [{id: 'xai', title: 'xAI Grok', model: 'grok-4.6'}, {id: 'deepseek-1', title: 'DeepSeek <b>', model: 'deepseek-chat'}], default_model: 'xai', text_model: 'grok-4.6', image_model: 'grok-imagine-image-2.0', video_model: 'grok-imagine-video-1.5', image_ratios: ['portrait'], video_ratios: ['story']}, next: 0, last: null},
    });
    state.settings.publishing_review = false;
    const view = api.publishing();
    check(view.includes('id="vkt-composer-media"') && view.includes('data-media-upload'), 'Конструктор показывает файлы и загрузку с компьютера');
    check(view.includes('data-command="ai-text"') && view.includes('data-command="ai-image"') && view.includes('data-command="ai-video"'), 'Кнопки генерации доступны при настроенном ключе');
    check(view.includes('promo-medium.jpg'), 'История показывает миниатюру приложенного файла');
    // Ошибка доставки — протокол прошлой попытки, поэтому рядом всегда её время.
    api.setPublishing({
        groups: [{id: 3, group_id: 987, name: 'Моя группа', screen_name: 'my_group', enabled: 1, can_post: 1, photo: ''}],
        posts: [{id: 6, message: 'Текст', attachments: '', media_items: [], origin: 'manual', status: 'failed', scheduled_at: '2026-09-14 04:00:00',
            deliveries: [{id: 9, group_id: 987, status: 'failed', error: 'VK: авторизация не прошла.', updated_at: '2026-09-15 03:42:00', name: 'Моя группа'}]}],
        status: {token_ready: true, community_only: false, media_native: true, media_limit: 10, ai: {configured: false}},
    });
    const failed = api.publishing();
    check(failed.includes('VK: авторизация не прошла.') && failed.includes('попытка'), 'Рядом с ошибкой видно, когда была попытка');
    // Ключ сообщества: VK запрещает медиа, поэтому блок файлов заменяется объяснением.
    api.setPublishing({groups: [{id: 3, group_id: 987, name: 'Моя группа', screen_name: 'my_group', enabled: 1, can_post: 1, photo: ''}], posts: [], status: {token_ready: true, community_only: true, media_native: false, media_limit: 10, ai: {configured: true, media_configured: true, models: [{id: 'xai', title: 'xAI Grok', model: 'grok-4.6'}, {id: 'deepseek-1', title: 'DeepSeek <b>', model: 'deepseek-chat'}], default_model: 'xai', text_model: 'grok-4.6', image_model: 'i', video_model: 'v', image_ratios: ['portrait'], video_ratios: ['story']}}});
    const communityOnly = api.publishing();
    check(!communityOnly.includes('data-media-upload') && !communityOnly.includes('data-command="ai-image"'), 'Без пользовательского токена кнопки файлов и картинок не показываются');
    check(communityOnly.includes('ошибкой 27') && communityOnly.includes('кодом 100'), 'Причина запрета названа конкретными кодами VK');
    api.setPublishing({groups: [], posts: [], status: {token_ready: true, community_only: false, media_native: true, media_limit: 10, ai: {configured: false}}});
    const plain = api.publishing();
    check(!plain.includes('data-command="ai-image"'), 'Без ключа xAI кнопки генерации скрыты');
    // Регрессия: при заданной константе VKT_ACCESS_TOKEN ID приложения всё равно
    // должен сохраняться — иначе подключение VK ID не начать в принципе.
    Object.assign(state.settings, {
        has_token: true, token_mode: 'service', token_source: 'wp-config.php', token_expires_in: null,
        token_refreshable: false, source_hours: 1, video_hours: 6, proxy_host: '',
        community: {configured: false}, ai: {configured: true, text_model: 't', image_model: 'i', video_model: 'v'},
        vkid: {configured: true, client_id: 54770323, locked: false, redirect_uri: 'https://example.test/wp-admin/admin-post.php?action=vkt_vkid', scope: 'wall photos groups video', has_secret: false, blocked_by_constant: true},
    });
    const blocked = api.posting();
    check(blocked.includes('name="vkid_client_id"') && blocked.includes('value="54770323"'), 'Поле ID приложения показывает сохранённое значение');
    // Слово disabled встречается в подсказке про выключенное приложение,
    // поэтому смотрим именно на тег кнопки.
    const vkidForm = blocked.slice(blocked.indexOf('data-form="vkid"'));
    const vkidButton = vkidForm.slice(vkidForm.indexOf('<button'), vkidForm.indexOf('</button>'));
    check(vkidButton.length > 0 && !vkidButton.includes('disabled'), 'Кнопка формы VK ID не блокируется константой — иначе ID не сохранить');
    check(!vkidForm.slice(0, vkidForm.indexOf('</form>')).includes('input') || !/<input[^>]*\sdisabled/.test(vkidForm.slice(0, vkidForm.indexOf('</form>'))), 'Поле ID приложения остаётся доступным для ввода');
    check(blocked.includes('Сохранить ID приложения'), 'Пока константа на месте, кнопка честно называется сохранением');
    state.settings.vkid.blocked_by_constant = false;
    check(api.posting().includes('Подключить VK ID'), 'Без константы кнопка запускает подключение');

    // Подключения разведены по страницам: ключ чтения не должен появляться
    // среди ключей публикации, иначе разделение теряет смысл.
    state.settings.tokens = [
        {slot: 'service', title: 'Сервисный ключ приложения', hint: 'Читает стены', has_token: true, preview: 'vk1.a.TU2Z…JJufS', length: 220},
        {slot: 'user', title: 'Пользовательский токен', hint: 'Грузит файлы', has_token: false},
        {slot: 'community', title: 'Ключ сообщества', hint: 'Публикует', has_token: true, preview: 'vk1.a.7dZ2…9Wpks', length: 220},
    ];
    const readingView = api.reading();
    check(readingView.includes('Сервисный ключ приложения') && readingView.includes('name="source_hours"'), 'Чтение собрано в одном месте: ключ и расписание обхода');
    check(!readingView.includes('name="publishing_review"') && !readingView.includes('data-form="oauth"'), 'Настроек публикации на странице чтения нет');
    const postingView = api.posting();
    check(postingView.includes('Пользовательский токен') && postingView.includes('data-form="oauth"'), 'Публикация собрана в одном месте: ключи и обмен кода');
    check(!postingView.includes('Сервисный ключ приложения</h3>') && !postingView.includes('name="source_hours"'), 'Расписание обхода на страницу публикации не попадает');
    check(postingView.includes('data-command="probe-matrix"'), 'Стенд постинга доступен со страницы публикации');
    const attachmentsView = api.attachments();
    for (const word of ['Музыка', 'Документ', 'Опрос', 'Товар', 'Ссылка']) {
        check(attachmentsView.includes(word), `Справка вложений описывает: ${word}`);
    }
    check(attachmentsView.includes('link_photo_sizing_rule'), 'Справка предупреждает про прямую ссылку на файл');
    // ——— Серия постов ———
    // 21 сентября 2026 — понедельник; «сейчас» на день раньше, чтобы ни один
    // слот не отсеялся как прошедший.
    const monday = '2026-09-21';
    const now = new Date('2026-09-20T12:00').getTime();
    const week = api.seriesPlan({span: 'week', start: monday, weekdays: ['1','2','3','4','5'], times: '10:00, 19:00'}, now);
    check(week.length === 10, 'Неделя по будням и двум временам даёт десять слотов');
    check(week[0].at === '2026-09-21T10:00' && week[1].at === '2026-09-21T19:00', 'Слоты идут по возрастанию времени');
    check(week.every(slot => !['2026-09-26', '2026-09-27'].includes(slot.at.slice(0, 10))), 'Суббота и воскресенье пропущены');
    const month = api.seriesPlan({span: 'month', start: monday, weekdays: ['1','2','3','4','5'], times: '10:00, 19:00'}, now);
    check(month.length === 44, 'Месяц по будням даёт 44 слота: 22 рабочих дня на два времени');
    const capped = api.seriesPlan({span: 'month', start: monday, weekdays: ['1','2','3','4','5'], times: '09:00, 13:00, 19:00'}, now);
    check(capped.length === 60, 'Сетка обрезается на шестидесяти слотах — столько же принимает сервер');
    check(api.seriesPlan({span: 'week', start: monday, weekdays: [], times: '10:00'}, now).length === 0, 'Без дней недели сетки нет');
    check(api.seriesPlan({span: 'week', start: monday, weekdays: ['1'], times: 'в обед'}, now).length === 0, 'Время не по формату отбрасывается');
    // Прошедшее время не попадает в сетку: сервер такой слот всё равно
    // отклонит, чтобы пакет не ушёл в VK залпом вместо расписания.
    const sameDay = api.seriesPlan({span: 'week', start: '2026-09-20', weekdays: ['0'], times: '08:00, 23:00'}, now);
    check(sameDay.length === 1 && sameDay[0].at === '2026-09-20T23:00', 'Утренний слот сегодняшнего дня пропущен, вечерний остался');

    api.setSeries(week);
    const calendar = api.seriesCalendar();
    check(calendar.includes('21 сен') && calendar.includes('data-command="series-slot"'), 'Календарь рисует дни и слоты кнопками');
    check(calendar.indexOf('21 сен') < calendar.indexOf('22 сен'), 'Дни идут по порядку');
    check((calendar.match(/vkt-cal-day/g) || []).length % 7 === 0, 'Сетка кратна семи колонкам, поэтому дни не разъезжаются');
    week[0].message = 'Готовый текст';
    check(api.seriesCalendar().includes('is-filled'), 'Заполненный слот отмечен');
    state.settings.ai = {configured: true};
    api.setPublishing({groups: [{id: 3, group_id: 241464933, name: 'Своя группа', enabled: 1, can_post: 1}], posts: [], status: {}});
    const seriesView = api.series();
    check(seriesView.includes('data-form="series-setup"') && seriesView.includes('data-form="series-prompt"'), 'Вкладка содержит настройку периода и промпт на серию');
    // Модель для текста выбирается там же, где пишется текст; чужие названия экранируются.
    state.settings.ai = {...(state.settings.ai || {}), configured: true, models: [{id: 'xai', title: 'xAI Grok', model: 'grok-4.6'}, {id: 'deepseek-1', title: 'DeepSeek <b>', model: 'deepseek-chat'}], default_model: 'deepseek-1'};
    const pickerView = api.series();
    check(pickerView.includes('data-ai-model') && pickerView.includes('value="deepseek-1" selected') && pickerView.includes('DeepSeek &lt;b&gt;'), 'В серии есть выбор модели, по умолчанию — выбранная в настройках');
    state.settings.ai.presets = {deepseek: {title: 'DeepSeek', base: 'https://api.deepseek.com', model: 'deepseek-chat'}, qwen: {title: 'Qwen', base: 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', model: 'qwen-plus'}};
    state.settings.ai.models[1].preview = 'sk-abc…1234'; state.settings.ai.models[1].host = 'api.deepseek.com';
    const settingsView = api.settings();
    check(settingsView.includes('data-form="ai-model"') && settingsView.includes('value="https://api.deepseek.com"') && settingsView.includes('data-command="ai-model-check"') && settingsView.includes('по умолчанию'), 'В настройках список моделей, проверка и добавление с готовыми адресами');
    check(seriesView.includes('data-command="series-queue"'), 'Есть кнопка отправки всей серии');
    check(seriesView.includes('name="weekdays"') && seriesView.includes('name="times"'), 'Дни недели и время выбираются в форме');
    state.settings.ai = {configured: false};
    check(!api.series().includes('data-form="series-prompt"'), 'Без модели для текстов промпт не показывается');
    api.setSeries([]);

    // Личный кабинет участника: ключей сайта и запасных способов у него нет.
    Object.assign(state.settings, {
        oauth: {app_id: 54770323, configured: true, scope: 'wall,photos,groups,video'},
        tokens: [{slot: 'user', area: 'user', title: 'Пользовательский токен', hint: '', has_token: false}, {slot: 'community', area: 'user', title: 'Ключ сообщества', hint: '', has_token: false}],
        ai: {configured: true, quota: {text: {limit: 30, used: 28, left: 2}, media: {limit: 5, used: 5, left: 0}}},
        limits: {sources: 100, sources_used: 3},
    });
    api.setAccount({id: 7, name: 'Участник', is_admin: false, status: 'active'});
    const memberPosting = api.posting();
    check(!memberPosting.includes('name="app_id"'), 'Участник не меняет ID приложения сайта');
    check(memberPosting.includes('54770323') && memberPosting.includes('data-command="oauth-open"'), 'Участник получает токен через приложение сайта');
    check(!memberPosting.includes('Запасные способы') && !memberPosting.includes('data-form="vkid"'), 'Запасные способы и VK ID-токен — только администратору');
    check(!memberPosting.includes('Защищённый ключ приложения'), 'Защищённого ключа участник не видит');
    check(memberPosting.includes('текстов 2 из 30') && memberPosting.includes('картинок и видео 0 из 5'), 'Остаток лимита xAI виден в кабинете');
    context.location.hash = '#logs';
    check(api.initial() === 'overview', 'Журнал участнику не открывается даже по прямой ссылке');
    context.location.hash = '#users';
    check(api.initial() === 'overview', 'Раздел «Пользователи» участнику не открывается');
    context.location.hash = '#posting';
    check(api.initial() === 'posting', 'Свою публикацию участник открывает');
    state.videos = []; state.products = [];
    check(api.overview().includes('href="#posting"') && !api.overview().includes('href="#reading"'), 'Первый шаг участника — своя публикация, а не ключ сбора');
    api.setAccount({id: 1, name: 'Админ', is_admin: true, status: 'active'});
    context.location.hash = '#users';
    check(api.initial() === 'users', 'Администратор открывает раздел «Пользователи»');
    api.setUsers([
        {id: 7, name: 'Иван <b>', avatar: '', vk_id: 38975563, status: 'pending', registered: '2026-09-18 10:00:00', last_login: '', sources: 0, groups: 0, posts: 0},
        {id: 8, name: 'Мария', avatar: 'https://sun.userapi.com/a.jpg', vk_id: 1, status: 'active', registered: '2026-09-17 10:00:00', last_login: '2026-09-18 09:00:00', sources: 12, groups: 2, posts: 5},
    ]);
    const usersView = api.users();
    check(usersView.includes('Иван &lt;b&gt;') && !usersView.includes('Иван <b>'), 'Имя из VK экранируется');
    check(usersView.includes('data-status="active"') && usersView.includes('Одобрить') && usersView.includes('Заблокировать'), 'Заявку можно одобрить, активного — заблокировать');
    check(usersView.includes('vk.com/id38975563') && usersView.includes('name="member_sources"'), 'Ссылка на профиль VK и форма лимитов на месте');
    context.location.hash = '#posts';

    // Стенд генерации фото: только администратору, ключ и ответы сервиса на виду.
    state.settings.flux = {configured: true, max_references: 4, max_tolerance: 5, models: {'flux-2-pro': {title: 'FLUX.2 [pro]', hint: 'правки и генерация'}, 'flux-2-max': {title: 'FLUX.2 [max]', hint: 'качество'}}};
    state.settings.tokens = [{slot: 'bfl', area: 'site', title: 'Ключ BFL (FLUX)', hint: '', has_token: true, preview: 'bfl_5mIgtpGqM…seVu', length: 36}];
    const fluxView = api.flux();
    check(fluxView.includes('name="safety_tolerance"') && fluxView.includes('data-command="flux-media"') && fluxView.includes('data-form="flux"'), 'В стенде есть строгость, образцы и форма отправки');
    check(fluxView.includes('Content Moderated') && fluxView.includes('Request Moderated'), 'Стенд объясняет разницу между отказом на входе и на выходе');
    check(fluxView.includes('FLUX.2 [pro]') && fluxView.includes('bfl_5mIgtpGqM…seVu'), 'Видны модели и огрызок ключа');
    state.settings.flux = {configured: false};
    check(api.flux().includes('Сохраните ключ BFL'), 'Без ключа стенд предупреждает и не даёт отправить');
    context.location.hash = '#flux';
    check(api.initial() === 'flux', 'Администратор открывает стенд');
    api.setAccount({id: 7, name: 'Участник', is_admin: false, status: 'active'});
    check(api.initial() === 'overview', 'Участнику стенд не открывается');
    api.setAccount({id: 1, name: 'Админ', is_admin: true, status: 'active'});
    context.location.hash = '#posts';

    // Каждой команде в разметке должен отвечать обработчик: вырезав соседний
    // блок кода, легко осиротить кнопку, и она молча перестаёт работать.
    const commands = new Set();
    for (const m of source.matchAll(/data-command="([a-z-]+)"/g)) commands.add(m[1]);
    for (const m of source.matchAll(/button\(`?[^`']*`?,\s*'([a-z-]+)'/g)) commands.add(m[1]);
    const orphans = [...commands].filter(name => !source.includes(`command==='${name}'`));
    check(orphans.length === 0, `У каждой кнопки есть обработчик (осиротели: ${orphans.join(', ') || 'нет'})`);
    check(source.includes("window.open('', '_blank')"), 'Вкладка согласия открывается синхронно по клику, иначе её блокирует браузер');
    // Вкладка «Комментарии»: чужой текст экранируется, отвеченное не выбирается.
    const commentsState = {groups: [{group_id: 100, name: 'Своя <b>группа</b>', screen_name: 'own', sender: 'user'}], queue: [{id: 1, group_id: 100, post_id: 10, comment_id: 30, author_name: 'Анна', comment_text: 'Вопрос', message: 'Ответ <img src=x onerror=alert(1)>', origin: 'ai', status: 'failed', error: 'VK: доступ запрещён', available_at: '2026-09-27 10:00:00'}], status: {reading: true, ai: {configured: true}, min_gap: 60, max_length: 2000}};
    const commentsPost = {id: 10, date: '2026-09-27 09:00:00', text: 'Пост', comments: 3, can_comment: true};
    const commentsThread = {post_id: 10, total: 3, comments: [
        {id: 30, from_id: 42, author: 'Анна <script>x</script>', text: 'Сколько стоит?', thread: [{id: 31, from_id: -100, author: 'Своя', text: 'Ответили', is_group: true, thread: []}], thread_count: 1, answered: true, queued: ''},
        {id: 32, from_id: 43, author: 'Олег', text: 'Есть доставка?', thread: [], thread_count: 0, answered: false, queued: ''},
        {id: 33, from_id: 44, author: 'Ира', text: 'А цвет?', thread: [], thread_count: 0, answered: false, queued: 'pending'},
    ]};
    api.setComments(commentsState, {posts: [commentsPost], total: 1}, commentsPost, commentsThread);
    html = api.comments();
    check(html.includes('Анна &lt;script&gt;') && !html.includes('<script>x') && !html.includes('<img src=x'), 'Имена и тексты комментариев и ответов экранируются');
    check(html.includes('data-comments-pick="10_32"') && !html.includes('data-comments-pick="10_33"') && !html.includes('data-comments-pick="10_31"'), 'Ответ группы и комментарий в очереди нельзя выбрать повторно');
    check(html.includes('Есть ответ группы') && html.includes('Ошибка') && html.includes('VK: доступ запрещён'), 'Видны ответ группы в ветке и причина ошибки очереди');
    check(html.includes('data-command="comments-retry"') && html.includes('Черновик нейросети'), 'Неудачный ответ можно повторить, источник текста подписан');
    check(html.includes('пользовательским токеном'), 'Вкладка объясняет, чем уйдёт ответ');
    api.pick('10_32');
    check(api.selected().get('10_32').comment_text === 'Есть доставка?' && api.comments().includes('Ответить выбранным'), 'Выбранный комментарий попадает в пачку, появляется панель ответа');
    // Неполадки: над разделом, со ссылкой на вкладку, где чинить.
    state.health = [
        {level: 'error', title: 'Пользовательский токен VK не действует', text: 'Срок истёк <b>', view: 'posting', action: 'Переподключить'},
        {level: 'warning', title: 'Сбор простаивал', text: 'wget …', view: 'collector', action: 'Сбор данных'},
    ];
    html = api.healthBanner();
    check(html.includes('href="#posting"') && html.includes('Переподключить') && html.includes('is-error'), 'Ошибка ключа ведёт на «Публикацию»');
    check(html.includes('&lt;b&gt;') && !html.includes('Срок истёк <b>'), 'Текст неполадки экранируется');
    api.setAccount({id: 7, name: 'Участник', is_admin: false, status: 'active'});
    check(!api.healthBanner().includes('#collector'), 'Участник не видит ссылок в разделы администратора');
    check(api.fixFor('Сервисный ключ не действует') === null, 'Участнику не предлагают чинить общий ключ');
    api.setAccount({id: 1, name: 'Админ', is_admin: true, status: 'active'});
    api.toast('VK: авторизация не прошла. Токен просрочен… [groups.get: User authorization failed: access_token has expired.]', true);
    check(element.innerHTML.includes('href="#posting"') && element.innerHTML.includes('Переподключить токен'), 'Ошибка токена в сообщении сразу ведёт туда, где его заменить');
    api.toast('Что-то сломалось', true, {view: 'comments', label: 'Очередь ответов'});
    check(element.innerHTML.includes('href="#comments"') && element.innerHTML.includes('Очередь ответов'), 'Подсказка сервера важнее догадки по тексту');
    api.toast('Готово <script>', false);
    check(!element.innerHTML.includes('<script>') && !element.innerHTML.includes('href='), 'Обычное сообщение без ссылки и экранировано');
    check(!api.hasSlot({tokens: [{slot: 'user', has_token: true, alive: false}]}, 'user') && api.hasSlot({tokens: [{slot: 'user', has_token: true, alive: true}]}, 'user'), 'Точка в меню зелёная только у живого ключа');
    state.health = [];
    // Серия: уже поставленные записи видны в сетке со статусом, запущенные серии — списком.
    api.setPublishing({groups: [{id: 3, group_id: 987, name: 'Моя группа', enabled: 1, can_post: 1}], posts: [], status: {ai: {}}, series: {
        list: [{series_id: 'sabc123def', title: 'Неделя <про> осень', total: 3, waiting: 2, published: 1, failed: 0, cancelled: 0, first_at: '2026-10-01 07:00:00', last_at: '2026-10-03 07:00:00'}],
        posts: [{id: 1, series_id: 'sabc123def', series_title: 'Неделя <про> осень', scheduled_at: '2026-10-01 07:00:00', status: 'published', message: 'Первый', groups_names: 'Моя группа', group_ids: [3], editable: false, media_count: 0}, {id: 2, series_id: 'sabc123def', scheduled_at: '2026-10-02 07:00:00', status: 'scheduled', message: 'Второй <b>', groups_names: 'Моя группа', group_ids: [3], editable: true, media_count: 2}, {id: 5, series_id: '', scheduled_at: '2026-10-02 09:00:00', status: 'scheduled', message: 'Чужая группа', groups_names: 'Другая', group_ids: [4], editable: true, media_count: 0}],
    }});
    const running = api.series();
    check(running.includes('is-queued is-editable is-scheduled') && running.includes('по расписанию') && running.includes('Второй &lt;b&gt;'), 'Записи запущенной серии видны в сетке со статусом');
    check(running.includes('data-command="series-post" data-id="2"') && !running.includes('data-command="series-post" data-id="1"'), 'Ждущая запись открывается на правку, опубликованная — нет');
    check(!running.includes('Чужая группа'), 'В сетке только записи выбранного сообщества');
    check(running.includes('data-series-group') && !running.includes('name="groups"') && running.includes('value="sabc123def"'), 'Серия привязана к одному сообществу, запущенную можно выбрать для дополнения');
    check(running.includes('data-command="series-open"') && running.includes('Фото к записям'), 'Серию можно открыть в сетке, есть блок фото');
    check(running.includes('Подключить токен'), 'Без пользовательского токена фото не предлагаются');
    check(running.includes('Запущенные серии') && running.includes('Неделя &lt;про&gt; осень') && running.includes('data-command="series-cancel"') && running.includes('ждут: 2'), 'Список запущенных серий с отменой оставшихся');
    // Лента комментариев группы.
    api.setFeed({groups: [{group_id: 100, name: 'Своя', sender: 'community', callback_ready: 0}], queue: [], status: {reading: true, ai: {}}}, {total: 1, comments: [{id: 60, post_id: 5, post_text: 'Пост <i>', from_id: 44, author: 'Ира', text: 'Вопрос?', answered: false, queued: '', thread: []}]});
    html = api.comments();
    check(html.includes('Лента комментариев') && html.includes('data-comments-pick="5_60"') && html.includes('Пост &lt;i&gt;'), 'Лента показывает комментарии всей группы с записью, к которой они оставлены');
    check(html.includes('data-command="group-settings"') && html.includes('подключите Callback'), 'Без Callback лента подсказывает, где его подключить');
    console.log(`All ${checks} offline dashboard checks passed.`);
})().catch(error => {console.error(error); process.exitCode = 1;});

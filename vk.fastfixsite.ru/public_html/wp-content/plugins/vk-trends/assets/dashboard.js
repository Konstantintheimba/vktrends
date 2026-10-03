(() => {
    'use strict';
    const config = window.vktConfig;
    const root = document.getElementById('vkt-app');
    if (!root || !config) return;
    const $ = (selector, parent = root) => parent.querySelector(selector);
    const content = $('#vkt-content');
    const dialog = $('#vkt-dialog');
    const names = {flux: 'Генерация фото', overview: 'Обзор', discover: 'Поиск трендов', posts: 'Посты', communities: 'Сообщества', publishing: 'Автопостинг', groups: 'Мои сообщества', series: 'Серия постов', videos: 'Мои ролики', products: 'Товары', sources: 'Источники', reading: 'Чтение постов', comments: 'Комментарии', posting: 'Публикация', attachments: 'Что можно прикрепить', users: 'Пользователи', api: 'Тест API', collector: 'Сбор данных', logs: 'Журнал', settings: 'Настройки'};
    // Кабинет пользователя: общий сбор, журнал и ключи сайта видит только администратор.
    // Сервер эти разделы участнику всё равно не отдаст — здесь их просто не рисуем.
    let account = config.account || {};
    const isAdmin = () => !!account.is_admin;
    const adminViews = ['reading', 'users', 'api', 'collector', 'logs', 'settings'];
    const paths = {
        grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        play: '<rect x="3" y="4" width="18" height="16" rx="4"/><path d="m10 8 6 4-6 4z"/>',
        bag: '<path d="M5 7h14l2 14H3L5 7Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>',
        layers: '<path d="m12 3 10 5-10 5L2 8l10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5"/>',
        code: '<path d="m8 7-5 5 5 5m8-10 5 5-5 5M14 4l-4 16"/>',
        refresh: '<path d="M20 10a8 8 0 0 0-14-5L3 8m0-5v5h5M4 14a8 8 0 0 0 14 5l3-3m0 5v-5h-5"/>',
        list: '<path d="M8 5h13M8 12h13M8 19h13M3 5h.01M3 12h.01M3 19h.01"/>',
        settings: '<path d="m9 3-1 3-3 1-2 3 2 2-1 3 3 2 2 3h4l1-3 3-1 2-3-2-2 1-3-3-2-2-3H9Z"/><circle cx="11" cy="12" r="3"/>',
        lock: '<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>',
        arrow: '<path d="M4 17 10 11l4 3 6-9m-6 0h6v6"/>',
        eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        menu: '<path d="M3 6h18M3 12h18M3 18h18"/>',
        check: '<path d="m5 12 4 4L19 6"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        post: '<rect x="3" y="4" width="18" height="17" rx="3"/><path d="M7 9h10M7 13h10M7 17h6"/>',
        people: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.5a3.5 3.5 0 0 1 0 7M17.5 20a6 6 0 0 0-3-5.2"/>',
        filter: '<path d="M3 5h18l-7 8v6l-4 2v-8L3 5Z"/>',
        chart: '<path d="M4 20V10m5 10V4m5 16v-7m5 7V8"/>',
        heart: '<path d="M12 20s-7-4.4-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.6-7 9-7 9Z"/>',
        share: '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.3m0 11-7.6-4.3"/>',
        comment: '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 21l1.9-5.2A8 8 0 1 1 21 12Z"/>',
        fire: '<path d="M12 3s5 4 5 9a5 5 0 0 1-10 0c0-2 1-3 1-3s.5 2 2 2c0-3 2-6 2-8Z"/>',
        cart: '<path d="M3 4h2l2.2 11h10.4L20 7H6"/><circle cx="9" cy="19" r="1.6"/><circle cx="17" cy="19" r="1.6"/>',
        table: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 10v10M15 10v10"/>',
        send: '<path d="m22 2-7 20-4-9-9-4 20-7Z"/><path d="M22 2 11 13"/>',
    };
    const icon = name => `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.grid}</svg>`;
    root.querySelectorAll('[data-icon]').forEach(el => { el.innerHTML = icon(el.dataset.icon); });
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[c]));
    const safeUrl = value => { try { const u = new URL(value); return ['https:', 'http:'].includes(u.protocol) ? esc(u.href) : ''; } catch { return ''; } };
    const num = value => value === null || value === undefined ? '—' : new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 0}).format(Number(value));
    const date = value => !value ? 'Ещё не было' : new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z').toLocaleString('ru-RU', {day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'});
    // Компактная запись больших чисел: 10.99M, 852.5k — как в карточках витрины.
    const short = value => {
        if (value === null || value === undefined || value === '') return '—';
        const n = Number(value);
        if (!isFinite(n)) return '—';
        const abs = Math.abs(n);
        // parseFloat срезает только дробный хвост: 11.00 → 11, но 120 остаётся 120.
        if (abs >= 1e6) return parseFloat((n / 1e6).toFixed(abs >= 1e8 ? 0 : 2)) + 'M';
        if (abs >= 1e3) return parseFloat((n / 1e3).toFixed(abs >= 1e5 ? 0 : 1)) + 'k';
        return num(Math.round(n));
    };
    const signed = value => value === null || value === undefined ? '—' : (Number(value) > 0 ? '+' : '') + short(value);
    const decimal = (value, digits = 2) => value === null || value === undefined ? '—' : new Intl.NumberFormat('ru-RU', {maximumFractionDigits: digits}).format(Number(value));
    const money = value => value === null || value === undefined ? '' : new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 0}).format(Number(value)) + ' ₽';
    const parseDate = value => value ? new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z') : null;
    // Возраст поста человеческим языком: витрина показывает его рядом с датой публикации.
    const ago = value => {
        const at = parseDate(value);
        if (!at) return '';
        const minutes = Math.max(0, Math.round((Date.now() - at.getTime()) / 60000));
        if (minutes < 60) return minutes + ' мин. назад';
        if (minutes < 1440) return Math.round(minutes / 60) + ' ч. назад';
        const days = Math.round(minutes / 1440);
        return days + ' ' + (days === 1 ? 'день' : days < 5 ? 'дня' : 'дн.') + ' назад';
    };
    const empty = (title, text, link = '', label = '') => `<div class="vkt-empty"><span class="vkt-empty-icon">${icon('layers')}</span><h3>${title}</h3><p>${text}</p>${link ? `<a class="vkt-button vkt-primary" href="#${link}">${label}</a>` : ''}</div>`;
    // Частоты сбора: те же значения принимает и проверяет сервер.
    const hourLabels = {1: 'Раз в час', 2: 'Раз в 2 часа', 3: 'Раз в 3 часа', 4: 'Раз в 4 часа', 6: 'Раз в 6 часов', 12: 'Раз в 12 часов', 24: 'Раз в сутки'};
    const hourOptions = current => Object.entries(hourLabels).map(([value, label]) => `<option value="${value}" ${Number(current) === Number(value) ? 'selected' : ''}>${label}</option>`).join('');
    const modeLabel = mode => ({service: 'Сервисный ключ', user: 'Пользовательский VK ID'}[mode] || '');
    const minutesLeft = seconds => seconds === null || seconds === undefined ? null : Math.max(0, Math.round(seconds / 60));
    const heading = (title, subtitle, extra = '') => `<div class="vkt-heading"><div><div class="vkt-eyebrow">VK TRENDS / WORKSPACE</div><h1>${title}</h1><p>${subtitle}</p></div>${extra}</div>`;
    const badge = (text, kind = '') => `<span class="vkt-badge ${kind}">${text}</span>`;
    const button = (label, command, extra = '', primary = false) => `<button type="button" class="vkt-button ${primary ? 'vkt-primary' : ''}" data-command="${command}" ${extra}>${label}</button>`;
    const initial = () => {
        const wanted = names[location.hash.slice(1)] ? location.hash.slice(1) : config.initialView;
        return adminViews.includes(wanted) && !isAdmin() ? 'overview' : wanted;
    };
    let view = initial(), state = null, page = 1, localSearch = '', sort = 'velocity', searchResults = null, searchQuery = '', searchOffset = 0, searchShort = true, apiResult = null, apiMethod = 'wall.get', apiDraft = null, toastTimer, requestSequence = 0;
    // Витрина постов: своя пагинация, фильтры и режим отображения, независимые от роликов.
    let postsData = null, postsPage = 1, postsSearch = '', postsSort = 'velocity', postsSource = 0, postsMode = 'grid', postsFiltersOpen = false, postsFilters = {}, communitiesData = null, communitiesSort = 'views', publishingData = null, usersData = null;
    // Файлы, выбранные в конструкторе записи: ID вложений WordPress, не VK.
    let composerMedia = [], mediaLibraryItems = [], aiPrompt = '';
    // Медиатека одна на всех, поэтому у выбора есть цель: конструктор, слот серии или стенд.
    let mediaTarget = 'composer', fluxMedia = [], fluxRuns = [], fluxBusy = false;
    // Серия постов: сетка слотов живёт в памяти до нажатия «Поставить в очередь».
    // Список неполадок свёрнут до трёх: остальные открываются по кнопке.
    let healthOpen = false;
    // Выбранная модель для текстов: пусто — модель по умолчанию из «Настроек».
    let aiModel = '';
    let seriesSetup = {span: 'week', start: '', weekdays: ['1','2','3','4','5'], times: '10:00, 19:00'}, seriesSlots = [], seriesSlotTarget = null, seriesPrompt = '';
    // Серия привязана к одному сообществу: его сетку и видно в календаре.
    // seriesTarget — ID серии, которую дополняем; пусто — новая серия.
    let seriesGroup = 0, seriesTarget = '';
    // Мои сообщества: список, открытая группа и несохранённый паспорт.
    let groupsData = null, groupOpen = 0, groupDetail = null, groupPassportDraft = null, groupShowHidden = false;
    // База ведения группы: какой материал открыт на правку ('new' — новый) и его несохранённый текст.
    let groupMaterialEdit = null, groupMaterialDraft = null;
    // Новости группы: несохранённые настройки, итог проверки источников и собранные записи.
    // Собранное привязано к ID группы и переживает уход в «Серию» — туда оно и раскладывается.
    let groupNewsDraft = null, groupNewsCheck = null, groupNewsResult = null;
    // Открытая на правку запись из очереди: черновик живёт до «Сохранить».
    let seriesEdit = null;
    // Фото к записям серии: общий стиль, чем рисуем и идущий пакет.
    let seriesImage = {style: '', provider: 'xai', ratio: 'portrait', slots: true, queued: true}, seriesImageRun = null;
    // Комментарии: выбранная группа, её записи, открытая запись и отмеченные комментарии.
    // Выбор и черновики живут в памяти до постановки в очередь; смена группы их сбрасывает.
    let commentsData = null, commentsGroup = 0, commentsPosts = null, commentsPost = null, commentsThread = null, commentsOnlyOpen = false, commentsReplyKey = '';
    // Лента — все комментарии группы сразу (Callback и обход записей); «По записям» — выбор записи и её ветки.
    let commentsMode = 'feed', commentsFeed = null, commentsFeedFilter = 'open';
    let commentsBulk = {common: '', instruction: '', interval: 3, jitter: true, start: ''};
    const commentsSelected = new Map(), commentsDrafts = new Map(), commentsIndex = new Map(), commentsAiKeys = new Set();
    // ——— Черновики: всё, что человек набрал и ещё не отправил, переживает перерисовку, уход в другой раздел и перезагрузку страницы ———
    // Лежат в хранилище браузера, отдельно для каждого кабинета. Ключи и пароли сюда не попадают.
    const draftStorage = (() => { try { const store = window.localStorage; store.getItem('vkt'); return store; } catch { return null; } })();
    const draftKey = `vkt-drafts:${Number(account.id) || 0}`;
    // Формы, чьи поля нигде больше не запоминаются: их значения сохраняем по мере ввода и возвращаем после перерисовки.
    const DRAFT_FORMS = ['publishing', 'series-setup', 'flux'];
    const DRAFT_DAYS = 30;
    let fieldDrafts = {}, groupDraftStore = {}, draftTimer = 0;
    // Несохранённые правки группы привязаны к ней: открыли другую или ушли из раздела — они ждут возвращения.
    function groupDraftsSave() {
        if (!groupOpen) return;
        const draft = {passport: groupPassportDraft, news: groupNewsDraft, materialEdit: groupMaterialEdit, material: groupMaterialDraft};
        if (Object.values(draft).some(value => value !== null)) groupDraftStore[groupOpen] = draft; else delete groupDraftStore[groupOpen];
    }
    function groupDraftsLoad(id) {
        const draft = groupDraftStore[id] || {};
        groupPassportDraft = typeof draft.passport === 'string' ? draft.passport : null;
        groupNewsDraft = draft.news && typeof draft.news === 'object' ? draft.news : null;
        groupMaterialEdit = draft.materialEdit || null;
        groupMaterialDraft = draft.material && typeof draft.material === 'object' ? draft.material : null;
    }
    function persistDrafts() {
        if (!draftStorage) return;
        groupDraftsSave();
        const snapshot = {v: 1, at: Date.now(), seriesSetup, seriesSlots, seriesPrompt, seriesGroup, seriesTarget, seriesImage, aiPrompt, aiModel, commentsBulk, commentsGroup, commentsDrafts: [...commentsDrafts], composerMedia, news: groupNewsResult, fields: fieldDrafts, groups: groupDraftStore};
        // Хранилище может быть переполнено или запрещено — тогда работаем как раньше, в памяти вкладки.
        try { draftStorage.setItem(draftKey, JSON.stringify(snapshot)); } catch {}
    }
    function scheduleDrafts() {
        clearTimeout(draftTimer);
        draftTimer = setTimeout(persistDrafts, 400);
    }
    function restoreDrafts() {
        let saved = null;
        try { saved = JSON.parse(draftStorage?.getItem(draftKey) || 'null'); } catch {}
        if (!saved || saved.v !== 1 || Date.now() - Number(saved.at) > DRAFT_DAYS * 86400000) return;
        const text = value => typeof value === 'string' ? value : '';
        const plain = value => value && typeof value === 'object' && !Array.isArray(value) ? value : {};
        const list = value => Array.isArray(value) ? value : [];
        if (plain(saved.seriesSetup).times !== undefined) seriesSetup = {span: saved.seriesSetup.span === 'month' ? 'month' : 'week', start: text(saved.seriesSetup.start), weekdays: list(saved.seriesSetup.weekdays).map(String), times: text(saved.seriesSetup.times)};
        seriesSlots = list(saved.seriesSlots).filter(slot => slot && typeof slot.at === 'string').map(slot => ({...slot, message: text(slot.message), attachments: text(slot.attachments), media: list(slot.media)}));
        seriesPrompt = text(saved.seriesPrompt);
        seriesGroup = Number(saved.seriesGroup) || 0;
        seriesTarget = text(saved.seriesTarget);
        seriesImage = {...seriesImage, ...plain(saved.seriesImage)};
        aiPrompt = text(saved.aiPrompt);
        aiModel = text(saved.aiModel);
        commentsBulk = {...commentsBulk, ...plain(saved.commentsBulk)};
        commentsGroup = Number(saved.commentsGroup) || 0;
        list(saved.commentsDrafts).forEach(entry => { if (Array.isArray(entry) && typeof entry[1] === 'string') commentsDrafts.set(String(entry[0]), entry[1]); });
        composerMedia = list(saved.composerMedia).filter(item => item && item.id);
        groupNewsResult = saved.news && Array.isArray(saved.news.posts) ? saved.news : null;
        fieldDrafts = plain(saved.fields);
        groupDraftStore = plain(saved.groups);
    }
    // Поле формы из DRAFT_FORMS запоминается при вводе. У флажков ключ включает значение: в группе «дни недели» имя общее.
    function draftInput(field) {
        const form = field?.closest?.('[data-form]');
        if (!form || !DRAFT_FORMS.includes(form.dataset.form) || !field.name || ['password', 'file', 'hidden'].includes(field.type)) return;
        const bucket = fieldDrafts[form.dataset.form] || (fieldDrafts[form.dataset.form] = {});
        if (field.type === 'checkbox' || field.type === 'radio') bucket[`${field.name}=${field.value}`] = field.checked; else bucket[field.name] = field.value;
    }
    // После перерисовки форма пустая — возвращаем в неё набранное.
    function applyFieldDrafts() {
        DRAFT_FORMS.forEach(name => {
            const bucket = fieldDrafts[name];
            if (!bucket) return;
            (content.querySelectorAll?.(`[data-form="${name}"]`) || []).forEach(form => [...form.elements].forEach(field => {
                if (!field.name || ['password', 'file', 'hidden'].includes(field.type)) return;
                if (field.type === 'checkbox' || field.type === 'radio') { const key = `${field.name}=${field.value}`; if (key in bucket) field.checked = !!bucket[key]; return; }
                if (!(field.name in bucket)) return;
                // Время публикации, которое уже прошло, не возвращаем: сервер такую запись всё равно отклонит.
                if (field.type === 'datetime-local' && bucket[field.name] && new Date(bucket[field.name]).getTime() < Date.now()) return;
                field.value = bucket[field.name];
            }));
        });
    }
    // Где чинить ошибку, если сервер не подсказал сам: по тексту узнаём, какой ключ или раздел виноват.
    const fixRules = [
        [/авторизация не прошла|срок действия токена|access_token|токен(?:у)? не хватает|пользовательский токен|VK ID отклонил/i, 'posting', 'Переподключить токен — «Публикация»'],
        [/ключ(?:а|у|ом)? сообщества|запрос сообщества/i, 'posting', 'Ключ сообщества — «Публикация»'],
        [/сервисн|ключ сбора/i, 'reading', 'Ключ сбора — «Чтение постов»'],
        [/xAI/i, 'settings', 'Ключ xAI — «Настройки»'],
        // Раздел виден всем, но ключ в нём меняет только администратор.
        [/BFL/i, 'flux', 'Ключ BFL — «Генерация фото»', true],
        [/Сообщество выключено|право публикации отозвано|право записи отозвано|не найдено среди ваших|Обновите их в «Автопостинге»/i, 'publishing', 'Мои сообщества — «Автопостинг»'],
    ];
    const fixFor = message => {
        const rule = fixRules.find(([pattern, view, adminOnly]) => pattern.test(String(message || '')) && (isAdmin() || !(adminOnly || adminViews.includes(view))));
        return rule ? {view: rule[1], label: rule[2]} : null;
    };
    function toast(message, error = false, fix = null) {
        const el = $('#vkt-toast');
        const link = error ? (fix && fix.view ? fix : fixFor(message)) : fix;
        el.innerHTML = esc(message) + (link && names[link.view] ? ` <a class="vkt-toast-fix" href="#${esc(link.view)}">${esc(link.label || names[link.view])} →</a>` : '');
        el.classList.toggle('vkt-error', error);
        el.hidden = false;
        clearTimeout(toastTimer);
        // Со ссылкой сообщение живёт дольше: его надо успеть прочитать и нажать.
        toastTimer = setTimeout(() => { el.hidden = true; }, error ? (link ? 20000 : 9000) : (link ? 12000 : 5000));
    }
    // WordPress also supports plain permalinks: ?rest_route=/namespace/route.
    function restUrl(path) {
        const url = new URL(config.rest);
        const [resource, query = ''] = path.split('?');
        if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route') + resource);
        else url.pathname += resource;
        new URLSearchParams(query).forEach((value,key) => url.searchParams.set(key,value));
        return url.href;
    }
    async function parse(response) {
        let payload;
        try { payload = await response.json(); } catch { throw new Error('Сервер вернул неожиданный ответ. Проверьте журнал PHP и доступность REST API.'); }
        if (!response.ok) {
            const error = new Error(payload.message || 'Не удалось выполнить запрос.');
            error.payload = payload;
            error.fix = payload.data?.fix || null;
            throw error;
        }
        return payload;
    }
    async function request(path, data) {
        return parse(await fetch(restUrl(path), {method: data ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, ...(data ? {body: JSON.stringify(data)} : {})}));
    }
    // Файл уходит multipart/form-data, поэтому Content-Type ставит сам браузер.
    async function upload(file) {
        const body = new FormData();
        body.append('file', file);
        return parse(await fetch(restUrl('media-upload'), {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce': config.nonce}, body}));
    }
    const act = (action, values = {}) => request('action', {action, ...values});
    async function load(renderView = true) {
        const sequence = ++requestSequence;
        const result = await request(`state?page=${page}&search=${encodeURIComponent(localSearch)}&sort=${sort}`);
        if (sequence !== requestSequence) return;
        state = result;
        $('#vkt-video-count').textContent = num(state.stats.videos);
        $('#vkt-post-count').textContent = num(state.stats.posts);
        if (state.settings.account) account = state.settings.account;
        const modeNote = state.settings.has_token && isAdmin() ? modeLabel(state.settings.token_mode) : '';
        const connection = isAdmin() ? (state.settings.has_token ? 'Токен сохранён' : 'API не подключён') : (state.settings.has_token ? 'Сбор подключён' : 'Сбор не настроен администратором');
        $('#vkt-connection').innerHTML = `<span class="vkt-status-dot ${state.settings.has_token ? 'is-ready' : ''}"></span>${connection}${modeNote ? ` · ${modeNote}` : ''}<small>${state.settings.paused ? 'Автосбор на паузе' : 'Автосбор включён'}</small>`;
        const pendingBadge = $('#vkt-users-count');
        if (pendingBadge) { pendingBadge.textContent = num(state.settings.pending_users || 0); pendingBadge.hidden = !Number(state.settings.pending_users); }
        if (renderView) {
            await viewData();
            if (sequence !== requestSequence) return;
            render();
        }
    }
    // Посты и сводка по сообществам живут на своих маршрутах: тянем их только для своей вкладки.
    async function viewData() {
        try {
            if (view === 'posts') {
                const query = new URLSearchParams({page: postsPage, search: postsSearch, sort: postsSort, source: postsSource, filters: JSON.stringify(postsFilters)});
                postsData = await request('posts?' + query.toString());
            }
            if (view === 'communities') communitiesData = (await request('communities')).communities || [];
            // Серия берёт группы и уже запущенные записи из тех же данных, что и автопостинг.
            if (view === 'publishing' || view === 'series') publishingData = await request('publishing');
            // Серии нужны паспорт и лучшие часы групп — те же данные, что у «Моих сообществ».
            if (view === 'groups' || view === 'series') groupsData = await request('groups');
            if (view === 'groups' && groupOpen) groupDetail = await act('group_detail', {id: groupOpen});
            if (view === 'comments') await loadComments();
            if (view === 'users' && isAdmin()) usersData = (await request('users')).users || [];
        } catch (error) { toast(error.message, true, error.fix); }
    }
    function stat(label, value, note, name, color = '') {
        return `<div class="vkt-stat"><div class="vkt-stat-top">${label}<span class="vkt-stat-icon ${color}">${icon(name)}</span></div><strong>${value}</strong><small>${note}</small></div>`;
    }
    function videoCard(video, discovery = false) {
        const id = discovery ? `${video.owner_id}_${video.id}` : `${video.owner_id}_${video.video_id}`;
        const thumbnail = discovery ? [...(video.image || []), ...(video.first_frame || [])].sort((a,b) => b.width-a.width)[0]?.url : video.thumbnail;
        const thumbnailUrl = safeUrl(thumbnail);
        const views = typeof video.views === 'object' && video.views ? video.views.count : video.views;
        const duration = `${Math.floor(Number(video.duration || 0)/60)}:${String(Number(video.duration || 0)%60).padStart(2, '0')}`;
        const clip = 'short_video' === (discovery ? video.type : video.kind);
        return `<article class="vkt-video-card"><a class="vkt-thumbnail" href="https://vk.com/video${esc(id)}" target="_blank" rel="noopener noreferrer">${thumbnailUrl ? `<img src="${thumbnailUrl}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="vkt-thumbnail-placeholder">${icon('play')}</span>`}<span class="vkt-duration">${duration}</span><span class="vkt-play-overlay">${icon('play')}</span></a><div class="vkt-video-body"><div class="vkt-video-meta">${clip ? 'VK Клип' : 'VK Видео'} <span>·</span> ${esc(video.owner_id)}</div><h3><a href="https://vk.com/video${esc(id)}" target="_blank" rel="noopener noreferrer">${esc(video.title || 'Без названия')}</a></h3><div class="vkt-video-metrics"><span>${icon('eye')} ${num(views)}</span>${!discovery && video.velocity !== null ? `<span class="vkt-growth">${icon('arrow')} ${num(video.velocity)}/ч</span>` : `<span class="vkt-muted">${discovery ? 'Со стены сообщества' : 'Нужен второй замер'}</span>`}</div>${!discovery && video.products?.length ? `<div class="vkt-product-tags">${video.products.map(p => badge(esc(p.title))).join('')}</div>` : ''}<div class="vkt-card-bottom">${discovery ? button(`${icon('plus')} Отслеживать`, 'save-result', `data-id="${esc(id)}"`) : `${button('Динамика', 'history', `data-id="${video.id}"`)}${button('···', 'video-detail', `data-id="${video.id}" aria-label="Настройки ролика"`)}`}</div></div></article>`;
    }
    function overview() {
        const s = state.stats;
        return heading('Ваш радар трендов', 'Видео, которые набирают обороты. Товары, за которыми стоит следить.', `<a href="#discover" class="vkt-button vkt-primary">${icon('search')} Найти видео</a>`) +
            `<div class="vkt-stats">${stat('Роликов в мониторинге', num(s.videos), 'Сохранённые видео VK', 'play')}${stat('Суммарные просмотры', num(s.views), 'По последним доступным замерам', 'eye', 'purple')}${stat('Товаров в подборке', num(state.products.length), 'Связаны с роликами вручную', 'bag', 'orange')}${stat('Роликов с динамикой', num(s.measured), 'Есть замеры с интервалом ≥ 1 мин.', 'arrow', 'green')}</div>` +
            `<div class="vkt-stats">${stat('Постов собрано', num(s.posts), 'Со стен отслеживаемых сообществ', 'post')}${stat('Просмотры постов', short(s.post_views), 'Сумма последних замеров', 'eye', 'purple')}${stat('Прирост постов за сутки', signed(s.post_day_growth), 'Сумма приростов за 24 часа', 'arrow', 'green')}${stat('Средний ERR постов', s.post_err === null || s.post_err === undefined ? '—' : decimal(s.post_err, 2) + '%', 'Вовлечение к охвату', 'heart', 'orange')}</div>` +
            `<section class="vkt-welcome"><div class="vkt-welcome-copy"><span class="vkt-pill">${state.settings.has_token ? 'РАБОЧЕЕ ПРОСТРАНСТВО' : 'НАЧНИТЕ ЗДЕСЬ'}</span><h2>${state.settings.has_token ? 'От первого видео — к растущей подборке' : 'Большие тренды начинаются с первого видео'}</h2><p>${state.settings.has_token ? 'Добавьте источники, сохраните интересные ролики и включите сбор. Новые замеры покажут, какие видео растут быстрее.' : 'Подключите VK, найдите интересные ролики и наблюдайте, как меняется их популярность.'}</p><a href="#${state.settings.has_token || !isAdmin() ? 'sources' : 'reading'}" class="vkt-button vkt-dark">${state.settings.has_token || !isAdmin() ? 'Добавить источник' : 'Подключить VK API'} <span>↗</span></a></div><div class="vkt-welcome-art" aria-hidden="true"><div class="vkt-art-orbit"></div><div class="vkt-art-card"><span>СЛЕДИТЕ ЗА РОСТОМ</span><div class="vkt-art-chart"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><small>От замера к замеру ↗</small></div><div class="vkt-art-float">${icon('arrow')}</div></div></section>` +
            `<div class="vkt-overview-grid"><section class="vkt-panel"><div class="vkt-panel-heading"><div><h2>В фокусе</h2><p>Сортировка по скорости прироста просмотров</p></div><a href="#videos">Все ролики →</a></div>${state.videos.length ? `<div class="vkt-video-grid compact">${state.videos.slice(0,3).map(v => videoCard(v)).join('')}</div>` : empty('Пока здесь тихо', 'Добавьте первое видео — здесь появится ваша подборка и её динамика.', 'discover', 'Перейти к поиску')}</section><section class="vkt-panel vkt-start-panel"><h2>Быстрый старт</h2>${[isAdmin() ? ['reading','Подключите VK API','Сервисный ключ для сбора',state.settings.has_token] : ['posting','Подключите публикацию','Свой токен и ключ сообщества',readyPosting(state.settings)],['discover','Найдите первые видео','Поиск или прямая ссылка',Number(s.videos)>0],['sources','Настройте мониторинг','Добавьте запрос или автора',state.sources.length>0]].map((x,i)=>`<a href="#${x[0]}" class="vkt-step"><span class="vkt-step-number ${x[3]?'done':''}">${x[3]?icon('check'):String(i+1).padStart(2,'0')}</span><div><strong>${x[1]}</strong><small>${x[2]}</small></div><span>↗</span></a>`).join('')}<div class="vkt-note">${icon('clock')}<div>Последний замер<strong>${date(s.last_measurement)}</strong></div></div></section></div>`;
    }
    function discover() {
        const items = searchResults?.response?.items || [];
        return heading('Поиск трендов', 'Смотрите свежие ролики сообщества и добавляйте интересные в мониторинг.') +
            `<div class="vkt-info">Читаем стену сообщества через wall.get — до 100 постов за запрос. Фильтр «Только клипы» опирается на тип short_video, который присылает сам VK. Глобальный поиск по видео требует пользовательского токена и здесь недоступен.</div><section class="vkt-panel"><form data-form="discover" class="vkt-search-form"><label class="vkt-search-input">${icon('search')}<input name="q" required maxlength="200" placeholder="Короткое имя или ID: team, -22822305" value="${esc(searchQuery)}" aria-label="Сообщество"></label><label class="vkt-check"><input name="short" type="checkbox" ${searchShort?'checked':''}>Только клипы</label><button class="vkt-button vkt-primary">Показать ролики</button></form></section>` +
            `<div class="vkt-section-title"><h2>${searchResults ? `Роликов на странице: ${num(items.length)} · постов у сообщества: ${num(searchResults.response?.count ?? 0)}` : 'Исследуйте свою нишу'}</h2>${button(`${icon('plus')} Добавить по ссылке`, 'add-video')}</div>` +
            (items.length ? `<div class="vkt-video-grid">${items.map(v => videoCard(v,true)).join('')}</div><div class="vkt-pagination">${button('← Назад', 'search-prev', searchOffset===0?'disabled':'')}<span>Посты ${searchOffset+1}–${searchOffset+100}</span>${button('Далее →', 'search-next', searchOffset+100 >= Number(searchResults.response.count)?'disabled':'')}</div>` : empty(searchResults ? 'В этих постах нет видео' : 'Что сейчас набирает популярность?', searchResults ? 'Пролистайте дальше или снимите фильтр клипов — на этой странице стены роликов не нашлось.' : 'Введите короткое имя сообщества из адреса vk.com или его числовой ID. Просмотр не сохраняет ролики автоматически.'));
    }
    function videos() {
        return heading('Мои ролики', 'Ваша подборка и скорость прироста между последними доступными замерами.', button(`${icon('plus')} Добавить ролик`, 'add-video', '', true)) +
            `<form data-form="filter" class="vkt-filters"><input name="search" placeholder="Поиск по сохранённым" value="${esc(localSearch)}" aria-label="Поиск по сохранённым"><select name="sort" aria-label="Сортировка"><option value="velocity" ${sort==='velocity'?'selected':''}>По приросту в час</option><option value="views" ${sort==='views'?'selected':''}>По просмотрам</option><option value="measured_at" ${sort==='measured_at'?'selected':''}>По последнему замеру</option></select><button class="vkt-button">Применить</button><span class="vkt-muted">${num(state.total)} роликов</span></form>` +
            (state.videos.length ? `<div class="vkt-video-grid">${state.videos.map(v=>videoCard(v)).join('')}</div><div class="vkt-pagination">${button('← Назад','page-prev',page<=1?'disabled':'')}<span>Страница ${page} из ${Math.ceil(state.total/24)}</span>${button('Далее →','page-next',page*24>=state.total?'disabled':'')}</div>` : empty('Ролики не найдены', 'Добавьте ролик по ссылке или выберите его в поиске.', 'discover','Найти видео'));
    }
    function products() {
        return heading('Товары', 'Объединяйте связанные видео и сравнивайте их суммарную динамику.', button(`${icon('plus')} Добавить товар`, 'add-product', '', true)) +
            `<div class="vkt-info">Это ваша подборка товаров. Связи с роликами задаются вручную в меню ролика. Просмотры отражают интерес к видео и не означают продажи.</div>` +
            (state.products.length ? `<section class="vkt-panel vkt-table-wrap"><table><thead><tr><th>Товар</th><th>Ролики</th><th>Просмотры</th><th>Прирост / час</th><th></th></tr></thead><tbody>${state.products.map(p=>`<tr><td><strong>${esc(p.title)}</strong>${p.url?`<small><a href="${safeUrl(p.url)}" target="_blank" rel="noopener noreferrer">Открыть товар ↗</a></small>`:''}</td><td>${num(p.videos)}</td><td>${num(p.views)}</td><td class="vkt-growth">${num(p.velocity)}</td><td>${button('Удалить','delete',`data-entity="products" data-id="${p.id}"`)}</td></tr>`).join('')}</tbody></table></section>` : empty('Соберите свою подборку товаров', 'Добавьте название и ссылку, затем привяжите сохранённые ролики через их меню.'));
    }
    function sources() {
        return heading('Источники', 'Сообщества, чьи стены плагин обходит ради новых роликов и свежих замеров.', button(`${icon('plus')} Добавить источник`,'add-source','',true) + button('Импорт списка','import-sources') + (state.sources.length?button('Выгрузить','export-sources'):'')) +
            `<div class="vkt-info">За один обход читаем до 100 постов сообщества и берём все видео из них — вместе со счётчиками просмотров. Каждый обход добавляет новый замер уже сохранённым роликам.${state.settings.limits ? ` В кабинете ${num(state.settings.limits.sources_used)} из ${num(state.settings.limits.sources)} источников.` : ''} Пауза и удаление касаются только вашего списка: если сообщество ведёт кто-то ещё, его обход продолжится.</div>` +
            (state.sources.length ? `<section class="vkt-panel vkt-table-wrap"><table><thead><tr><th>Источник</th><th>Тип</th><th>Состояние</th><th>Следующая постановка в очередь</th><th></th></tr></thead><tbody>${state.sources.map(s=>`<tr><td><strong>${esc(s.title || s.value)}</strong>${s.title?`<small class="vkt-muted">${esc(s.value)}</small>`:''}</td><td>${s.kind==='domain'?'Короткое имя':(s.kind==='owner'?'Числовой ID':'Поисковый запрос — не поддерживается')}</td><td>${badge(Number(s.enabled)?'Активен':'На паузе',Number(s.enabled)?'green':'')}</td><td>${state.settings.paused?'Автосбор на паузе':date(s.next_run)}</td><td class="vkt-table-actions">${button(Number(s.enabled)?'Пауза':'Включить','source-toggle',`data-id="${s.id}" data-enabled="${Number(s.enabled)?0:1}"`)}${button('Удалить','delete',`data-id="${s.id}" data-entity="sources"`)}</td></tr>`).join('')}</tbody></table></section>` : empty('Откуда будем искать тренды?', 'Добавьте сообщество: короткое имя из адреса vk.com или числовой ID.'));
    }
    const examples = {
        'video.search': {q:'товары для дома',filters:'short',sort:0,count:20,adult:0}, 'video.get':{videos:'-123_456',extended:1}, 'video.getComments':{owner_id:-123,video_id:456,count:20,need_likes:1}, 'video.getAlbums':{owner_id:-123,count:20},
        'market.get':{owner_id:-123,count:20}, 'market.search':{owner_id:-123,q:'лампа',count:20}, 'market.getById':{item_ids:'-123_456'}, 'market.getCategories':{count:20}, 'users.get':{user_ids:'1',fields:'screen_name'}, 'groups.getById':{group_ids:'1'}, 'groups.search':{q:'товары для дома',count:20}, 'wall.get':{domain:'team',count:100}, 'wall.search':{owner_id:-123,query:'товары',count:20}, 'wall.getById':{posts:'-123_456'}, 'utils.resolveScreenName':{screen_name:'apiclub'}, 'likes.getList':{type:'video',owner_id:-123,item_id:456,count:20}
    };
    function api() {
        const method = config.methods[apiMethod];
        return heading('Тест API', 'Проверьте методы VK и изучите ответы перед подключением к сборщику.', `<a class="vkt-button" href="${safeUrl(config.apiUrl)}">Открыть отдельную страницу ↗</a>`) +
            `<div class="vkt-info">Тесты не добавляют ролики и замеры в мониторинг. В журнал попадают только метод, статус и время. Доступность метода зависит от токена и прав приложения.</div><div class="vkt-api-grid"><section class="vkt-panel"><div class="vkt-panel-heading"><h2>Запрос</h2>${badge('Только чтение','green')}</div><form data-form="api" class="vkt-form"><label>Метод<select name="method" id="vkt-method">${Object.keys(config.methods).sort().map(m=>`<option ${m===apiMethod?'selected':''}>${m}</option>`).join('')}</select></label><label>Параметры · JSON<textarea name="params" id="vkt-params" class="vkt-code-input" rows="11" spellcheck="false">${esc(apiDraft ?? JSON.stringify(examples[apiMethod] || {},null,2))}</textarea></label><p class="vkt-help">Списки — строкой через запятую. Access token и версия подставляются сервером. Примерные ID замените своими.</p><button class="vkt-button vkt-primary">${icon('play')} Выполнить запрос</button></form></section><section class="vkt-panel vkt-response-panel"><div class="vkt-panel-heading"><h2>Ответ VK</h2><span class="vkt-muted">${apiResult ? `${num(apiResult.duration_ms ?? apiResult.data?.duration_ms)} мс` : 'JSON'}</span></div><pre id="vkt-api-response">${esc(apiResult ? JSON.stringify(apiResult,null,2) : '// Здесь появится ответ API\n// Выполните запрос в форме слева.')}</pre></section></div><section class="vkt-panel vkt-method-help"><div class="vkt-panel-heading"><h2>Параметры ${esc(apiMethod)}</h2><a href="https://dev.vk.com/ru/method/${esc(apiMethod)}" target="_blank" rel="noopener noreferrer">Документация VK ↗</a></div><p class="vkt-muted">Тип токена по схеме VK: ${esc(method.tokens.join(', '))}. Каталог: 16 методов для видео, товаров, авторов и сообществ.</p><div class="vkt-table-wrap"><table><thead><tr><th>Параметр</th><th>Тип</th><th>Описание</th></tr></thead><tbody>${method.parameters.map(p=>`<tr><td><code>${esc(p.name)}</code>${p.required?'<span class="vkt-required"> *</span>':''}</td><td>${esc(p.type)}</td><td>${esc(p.description || (p.enum ? p.enum.join(', ') : '—'))}</td></tr>`).join('')}</tbody></table></div></section>`;
    }
    const jobStatus = {pending:'В очереди', running:'Выполняется', done:'Готово', failed:'Ошибка'};
    function collector() {
        return heading('Сбор данных', 'Небольшие порции запросов, расписание и история выполнения.', button(`${icon('refresh')} Запустить порцию`,'collect','',true)) +
            `<div class="vkt-stats">${stat('Автосбор',state.settings.paused?'Пауза':'Включён',`Сообщества: ${(hourLabels[state.settings.source_hours] || '').toLowerCase()} · ролики: ${(hourLabels[state.settings.video_hours] || '').toLowerCase()}`, 'clock')}${stat('В очереди',num(state.queue.find(q=>q.status==='pending')?.count || 0),'До 2 заданий за запуск','layers')}${stat('С ошибкой',num(state.queue.find(q=>q.status==='failed')?.count || 0),'Доступен ручной повтор','list')}${stat('Последний запуск',state.cron.last?date(state.cron.last):'—','Время запуска сборщика','refresh')}</div>` +
            `<section class="vkt-panel vkt-collector-controls"><div><h2>${state.settings.paused?'Автоматический сбор приостановлен':'Автоматический сбор работает'}</h2><p>Ручной запуск обработает до двух готовых заданий даже на паузе.</p></div>${button(state.settings.paused?'Включить автосбор':'Поставить на паузу','pause')}</section><div class="vkt-info">Обход сообществ идёт в очереди первым: свежие посты важнее переизмерения старого ролика. WP-Cron запускается при посещениях сайта. Для стабильного расписания настройте cron хостинга раз в минуту — команда есть в README плагина. Временные ошибки: до 4 попыток с задержкой.</div>` +
            (state.jobs.length ? `<section class="vkt-panel vkt-table-wrap"><table><thead><tr><th>Задание</th><th>Статус</th><th>Попытки</th><th>Доступно с</th><th>Результат</th><th></th></tr></thead><tbody>${state.jobs.map(j=>`<tr><td>${j.kind==='video'?'Ролик':'Источник'} #${j.entity_id}${(j.source_title || j.video_title)?`<small class="vkt-muted">${esc(j.source_title || j.video_title)}</small>`:''}</td><td>${badge(jobStatus[j.status] || esc(j.status), j.status==='done'?'green':j.status==='failed'?'red':'')}</td><td>${j.attempts}</td><td>${date(j.available_at)}</td><td>${esc(j.message)}</td><td>${j.status==='failed'?button('Повторить','retry',`data-id="${j.id}"`):''}</td></tr>`).join('')}</tbody></table></section>` : empty('Очередь пока пуста', 'Добавьте источники или ролики, затем запустите сбор.'));
    }
    function logs() {
        return heading('Журнал', 'Последние 100 запросов. Журнал хранится 30 дней.', button(`${icon('refresh')} Обновить`, 'reload')) +
            (state.logs.length ? `<section class="vkt-panel vkt-table-wrap"><table><thead><tr><th>Время</th><th>Метод</th><th>Откуда</th><th>Статус</th><th>Время ответа</th><th>Сообщение</th></tr></thead><tbody>${state.logs.map(l=>`<tr><td>${date(l.created_at)}</td><td><code>${esc(l.method)}</code></td><td>${({test:'Тест API',collector:'Сборщик',manual:'Вручную',search:'Поиск',publisher:'Автопостинг',auth:'Вход'})[l.context] || esc(l.context)}</td><td>${badge(l.status==='ok'?'Успешно':`Ошибка ${Number(l.code)||''}`,l.status==='ok'?'green':'red')}</td><td>${num(l.duration_ms)} мс</td><td>${esc(l.message)}</td></tr>`).join('')}</tbody></table></section>` : empty('Запросов ещё не было', 'Выполните тест API или найдите первое видео.', 'api','Открыть тест API'));
    }
    const slotExtras = {
        user: '<div class="vkt-form-row"><label>refresh_token · необязательно<input name="refresh_token" autocomplete="off" maxlength="2048"></label><label>device_id · необязательно<input name="device_id" autocomplete="off" maxlength="2048"></label><label>client_id · необязательно<input name="client_id" autocomplete="off" maxlength="2048"></label><label>Срок жизни, сек.<input type="number" name="expires_in" min="60" max="31536000" placeholder="86400"></label></div>',
    };
    function tokenReport(report) {
        const rows = (report.checks || []).map(check => `<tr><td>${esc(check.label)}<small class="vkt-muted"><code>${esc(check.method)}</code></small></td><td>${badge(check.ok ? 'доступен' : check.optional ? 'не нужен' : 'отказ', check.ok ? 'green' : check.optional ? '' : 'red')}</td><td>${check.code ? `код ${Number(check.code)}: ` : ''}${esc(check.message)}</td></tr>`).join('');
        modal(`<h2>Проверка · ${esc(report.title || '')}</h2><p class="vkt-muted">${esc(report.note || 'Плагин вызвал методы VK этим ключом и показывает дословные ответы. Записи при этом не создаются.')}</p>${(report.verdict || []).length ? `<div class="vkt-info"><strong>Что из этого следует</strong><ul>${report.verdict.map(line => `<li>${esc(line)}</li>`).join('')}</ul></div>` : ''}<div class="vkt-table-wrap"><table><thead><tr><th>Что проверяли</th><th>Итог</th><th>Ответ VK</th></tr></thead><tbody>${rows}</tbody></table></div>${report.ok ? '' : '<div class="vkt-info vkt-info-warning">Отказы остаются видны в карточке ключа, пока не будут исправлены.</div>'}`);
    }
    function tokenCard(slot) {
        const life = slot.expires_in === null || slot.expires_in === undefined ? '' : ` · осталось ~${Math.round(slot.expires_in / 60)} мин.`;
        const facts = slot.has_token
            ? `<code>${esc(slot.preview)}</code> <span class="vkt-muted">${Number(slot.length) || 0} символов${life}${slot.scope ? ` · права: ${esc(slot.scope)}` : ''}${slot.refreshable ? ' · автообновление' : ''}</span>`
            : '<span class="vkt-muted">Ключ не сохранён.</span>';
        return `<section class="vkt-token-card">
            <div class="vkt-panel-heading"><div><h3>${esc(slot.title)}</h3><p class="vkt-muted">${esc(slot.hint)}</p></div>${badge(slot.has_token ? 'Подключён' : 'Нет ключа', slot.has_token ? 'green' : '')}</div>
            <div class="vkt-token-preview">${facts}</div>
            ${slot.error ? `<div class="vkt-info vkt-info-warning">Последняя ошибка · ${date(slot.error.at)}<br>${esc(slot.error.message)}</div>` : ''}
            ${slot.locked
                ? `<p class="vkt-help">Задан константой <code>${esc(slot.constant)}</code> в wp-config.php — здесь не меняется.</p>`
                : `<form data-form="token" class="vkt-form"><input type="hidden" name="slot" value="${esc(slot.slot)}">
                    <label>${slot.slot === 'community' ? 'Ключ доступа сообщества' : 'Ключ или адрес из браузера'}<input type="password" name="token" autocomplete="new-password" maxlength="2048" placeholder="${slot.has_token ? 'Оставьте пустым, чтобы не менять' : slot.slot === 'community' ? 'vk1.a.…' : 'Вставьте ключ'}"></label>
                    ${slotExtras[slot.slot] || ''}
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">Сохранить и проверить</button>${slot.has_token ? button('Проверить', 'token-probe', `data-slot="${esc(slot.slot)}"`) + button('Удалить', 'token-forget', `data-slot="${esc(slot.slot)}"`) : ''}</div>
                </form>`}
        </section>`;
    }
    // ——— Серия постов: календарь слотов и один промпт на весь период ———
    const monthShort = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    const weekdayNames = [['1', 'Пн'], ['2', 'Вт'], ['3', 'Ср'], ['4', 'Чт'], ['5', 'Пт'], ['6', 'Сб'], ['0', 'Вс']];
    const pad = value => String(value).padStart(2, '0');
    // Метка без часового пояса: в VK время уходит через toISOString, как и у
    // одиночной записи, а здесь она нужна только для сетки и подписей.
    const localStamp = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;

    /** Сетка серии из настроек. Чистая функция: её проверяет offline-тест. */
    function seriesPlan(setup, now = Date.now()) {
        const times = String(setup.times || '').split(/[,;\n]+/).map(value => value.trim()).filter(value => /^\d{1,2}:\d{2}$/.test(value));
        const weekdays = (setup.weekdays || []).map(String);
        const days = 'month' === setup.span ? 30 : 7;
        const start = setup.start ? new Date(`${setup.start}T00:00`) : new Date(now);
        if (!times.length || !weekdays.length || Number.isNaN(start.getTime())) return [];
        const slots = [];
        for (let shift = 0; shift < days; ++shift) {
            const day = new Date(start.getFullYear(), start.getMonth(), start.getDate() + shift);
            if (!weekdays.includes(String(day.getDay()))) continue;
            for (const time of times) {
                const [hour, minute] = time.split(':').map(Number);
                if (hour > 23 || minute > 59) continue;
                const at = new Date(day.getFullYear(), day.getMonth(), day.getDate(), hour, minute);
                // Прошедшее время серии не нужно: сервер такой слот всё равно
                // отклонит, чтобы пакет не ушёл в VK залпом вместо расписания.
                if (at.getTime() < now + 60000) continue;
                slots.push({at: localStamp(at), message: '', attachments: '', media: []});
            }
        }
        return slots.sort((a, b) => a.at < b.at ? -1 : 1).slice(0, 60);
    }

    const seriesFilled = () => seriesSlots.filter(slot => slot.message.trim() || slot.media.length || slot.attachments.trim());

    // Записи, которые уже в очереди: серии и одиночные запланированные. В сетке они рядом с новыми слотами.
    const queuedStatuses = {scheduled: ['по расписанию', ''], queued: ['в очереди', ''], draft: ['ждёт проверки', ''], published: ['опубликовано', 'green'], partial: ['частично', 'red'], failed: ['ошибка', 'red'], cancelled: ['отменено', '']};
    const seriesPosts = () => (publishingData?.series?.posts || []).filter(post => post.status !== 'cancelled');
    const seriesGroupsList = () => (publishingData?.groups || []).filter(group => Number(group.enabled) && Number(group.can_post));
    // В календаре — только выбранное сообщество: у каждой группы своя сетка.
    const seriesGroupPosts = () => seriesPosts().filter(post => (post.group_ids || []).map(Number).includes(Number(seriesGroup)));
    // Сообщество серии по её записям. У серий до 0.26 групп могло быть несколько — тогда привязки нет.
    function seriesGroupOf(seriesId) {
        const ids = new Set();
        seriesPosts().filter(post => post.series_id === seriesId).forEach(post => (post.group_ids || []).forEach(id => ids.add(Number(id))));
        return 1 === ids.size ? [...ids][0] : 0;
    }
    const seriesTitle = seriesId => (publishingData?.series?.list || []).find(item => item.series_id === seriesId)?.title || '';
    const validStamp = value => /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(String(value || ''));

    function seriesCalendar() {
        const queued = seriesGroupPosts();
        if (!seriesSlots.length && !queued.length) return `<div class="vkt-info">Сетки пока нет. Выберите период, дни и время — и нажмите «Построить сетку».</div>`;
        const byDay = new Map();
        const put = (key, item) => { if (!byDay.has(key)) byDay.set(key, []); byDay.get(key).push(item); };
        seriesSlots.forEach((slot, index) => put(slot.at.slice(0, 10), {slot, index, at: slot.at.slice(11)}));
        queued.forEach(post => { const at = localStamp(parseDate(post.scheduled_at)); put(at.slice(0, 10), {post, at: at.slice(11)}); });
        byDay.forEach(items => items.sort((a, b) => a.at.localeCompare(b.at)));
        const keys = [...byDay.keys()].sort();
        const first = new Date(`${keys[0]}T00:00`);
        const last = new Date(`${keys[keys.length - 1]}T00:00`);
        // Сетка начинается с понедельника недели первого слота, иначе дни
        // разъезжаются по колонкам и календарь перестаёт читаться.
        const gridStart = new Date(first.getFullYear(), first.getMonth(), first.getDate() - ((first.getDay() + 6) % 7));
        const span = Math.round((last - gridStart) / 86400000) + 1;
        const total = Math.min(70, Math.max(7, Math.ceil(span / 7) * 7));
        const files = count => Number(count) ? `<small>${icon('layers')} ${Number(count)}</small>` : '';
        const cells = [];
        for (let i = 0; i < total; ++i) {
            const cursor = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i);
            const items = byDay.get(localStamp(cursor).slice(0, 10)) || [];
            cells.push(`<div class="vkt-cal-day${items.length ? '' : ' is-empty'}"><span class="vkt-cal-date">${cursor.getDate()} ${monthShort[cursor.getMonth()]}</span>${items.map(({slot, index, post, at}) => {
                if (post) {
                    const [label, tone] = queuedStatuses[post.status] || [post.status, ''];
                    // При дополнении серии чужие записи приглушены: видно, что именно правим.
                    const other = seriesTarget && post.series_id !== seriesTarget ? ' is-other' : '';
                    const inner = `<strong>${esc(at)}</strong><span>${esc(String(post.message || 'Запись с вложением').slice(0, 70))}</span>${files(post.media_count)}${badge(esc(label), tone)}`;
                    const title = `${esc(post.series_title || 'Запланированная запись')}${post.groups_names ? ' → ' + esc(post.groups_names) : ''}`;
                    return post.editable
                        ? `<button type="button" class="vkt-cal-slot is-queued is-editable is-${esc(post.status)}${other}" data-command="series-post" data-id="${Number(post.id)}" title="${title}">${inner}</button>`
                        : `<div class="vkt-cal-slot is-queued is-${esc(post.status)}${other}" title="${title}">${inner}</div>`;
                }
                const filled = slot.message.trim() || slot.media.length || slot.attachments.trim();
                return `<button type="button" class="vkt-cal-slot${filled ? ' is-filled' : ''}" data-command="series-slot" data-index="${Number(index)}"><strong>${esc(slot.at.slice(11))}</strong><span>${slot.message.trim() ? esc(slot.message.trim().slice(0, 70)) : 'пусто'}</span>${files(slot.media.length)}</button>`;
            }).join('')}</div>`);
        }
        return `<div class="vkt-calendar"><div class="vkt-cal-head">${weekdayNames.map(([, label]) => `<span>${label}</span>`).join('')}</div><div class="vkt-cal-grid">${cells.join('')}</div></div>`;
    }

    function runningSeries() {
        const list = publishingData?.series?.list || [];
        if (!list.length) return '';
        const groups = seriesGroupsList();
        const cards = list.map(item => {
            const counts = [
                Number(item.waiting) ? badge(`ждут: ${num(item.waiting)}`) : '',
                Number(item.published) ? badge(`опубликовано: ${num(item.published)}`, 'green') : '',
                Number(item.failed) ? badge(`с ошибкой: ${num(item.failed)}`, 'red') : '',
                Number(item.cancelled) ? badge(`отменено: ${num(item.cancelled)}`) : '',
            ].join('');
            const next = seriesPosts().find(post => post.series_id === item.series_id && ['scheduled', 'queued', 'draft'].includes(post.status));
            const names = [...new Set(seriesPosts().filter(post => post.series_id === item.series_id).map(post => post.groups_names).filter(Boolean))].join(', ');
            const group = seriesGroupOf(item.series_id);
            const open = group && groups.some(row => Number(row.id) === group) ? button(item.series_id === seriesTarget ? 'Открыта в сетке' : 'Открыть в сетке', 'series-open', `data-id="${esc(item.series_id)}" ${item.series_id === seriesTarget ? 'disabled' : ''}`) : '';
            return `<div class="vkt-series-run"><div><strong>${esc(item.title)}</strong><small class="vkt-muted">${num(item.total)} записей · ${date(item.first_at)} — ${date(item.last_at)}${names ? ` · ${esc(names)}` : ''}${next ? ` · следующая ${date(next.scheduled_at)}` : ''}</small><div class="vkt-series-counts">${counts}</div></div><div class="vkt-table-actions">${open}${Number(item.failed) ? `<a class="vkt-button" href="#publishing">Ошибки — «Автопостинг»</a>` : ''}${Number(item.waiting) ? button('Отменить оставшиеся', 'series-cancel', `data-id="${esc(item.series_id)}"`) : ''}</div></div>`;
        }).join('');
        return `<div class="vkt-section-title"><h2>Запущенные серии</h2><span class="vkt-muted">«Открыть в сетке» — дополнить серию, поправить или убрать её записи.</span></div><section class="vkt-panel vkt-series-runs">${cards}</section>`;
    }

    // ——— Фото к записям серии: по одной из окна слота или пачкой ———
    // Чем рисовать: список подключённых моделей отдаёт сервер (VKT_Images), один для всех кабинетов.
    function imageProviders() {
        const list = (state?.settings?.images || []).map(item => [item.id, item.title]);
        if (list.length && !list.some(([id]) => id === seriesImage.provider)) seriesImage.provider = list[0][0];
        return list;
    }
    // Фото в VK кладёт только пользовательский токен: без него рисовать к записи бессмысленно.
    const imagesReady = () => !!publishingData?.status?.media_native && imageProviders().length > 0;
    // Стороны кратны 32 — иначе BFL отвечает 422.
    const fluxSizes = {portrait: [768, 1024], square: [1024, 1024], landscape: [1344, 768], story: [768, 1344]};
    function seriesImagePrompt(text, own = '') {
        const style = seriesImage.style.trim();
        return [
            own.trim() || `Иллюстрация к посту в соцсети. Содержание поста: ${String(text || '').trim().slice(0, 1800)}`,
            style ? `Стиль: ${style}` : '',
            'Без текста, надписей и логотипов на изображении.',
        ].filter(Boolean).join('\n\n').slice(0, 5000);
    }
    async function drawImage(prompt, report = null) {
        const started = await act('image_start', {provider: seriesImage.provider, prompt, ratio: seriesImage.ratio});
        // Одни поставщики отдают картинку сразу, другие — задачу, которую надо дождаться.
        return started.status === 'done' ? started.media : (await fluxAwait(started.id, report, 'image_status')).media;
    }
    const IMAGE_VARIANTS = 10;
    const variantOptions = (max = IMAGE_VARIANTS) => Array.from({length: Math.max(1, Math.min(IMAGE_VARIANTS, max))}, (_, index) => `<option value="${index + 1}">${index + 1}</option>`).join('');
    // Несколько вариантов одного запроса. Каждый — отдельная задача и одна картинка из суточного лимита.
    async function drawVariants(count, one, report) {
        const run = {total: count, media: [], errors: [], stop: false};
        let next = 0;
        const worker = async () => {
            while (next < count && !run.stop) {
                const index = next++;
                try { run.media.push(await one(index)); }
                catch (error) {
                    run.errors.push(error);
                    // Кончился лимит, кредиты или ключ — следующие упадут так же.
                    if (429 === Number(error.payload?.data?.status) || 402 === Number(error.payload?.data?.status) || /лимит|не настроен|не сохранён|кредит/i.test(error.message)) run.stop = true;
                }
                if (count > 1) report(`Готово ${run.media.length} из ${count}${run.errors.length ? `, не вышло ${run.errors.length}` : ''}. Не закрывайте вкладку.`);
            }
        };
        // Два потока: быстрее одного и не упирается в лимит одновременных задач BFL.
        await Promise.all([worker(), worker()]);
        return run;
    }
    // Пачкой идут только записи с текстом и без файлов: готовые фото не перерисовываем.
    const seriesImageTargets = () => [
        ...(seriesImage.slots ? seriesSlots.filter(slot => slot.message.trim() && !slot.media.length).map(slot => ({slot})) : []),
        ...(seriesImage.queued ? seriesGroupPosts().filter(post => post.editable && !Number(post.media_count) && String(post.message || '').trim() && (!seriesTarget || post.series_id === seriesTarget)).map(post => ({post})) : []),
    ];
    const seriesImageProgress = run => `Готово ${run.done} из ${run.total}${run.failed ? `, не вышло ${run.failed}` : ''}${run.stop ? ' — останавливаемся' : ''}. Не закрывайте вкладку.`;
    function seriesImagesPanel() {
        if (!publishingData?.status?.media_native) return `<div class="vkt-info">Фото к записям прикладывает только пользовательский токен VK ID: ключу сообщества VK запрещает загрузку файлов. <a href="#posting">Подключить токен — «Публикация» →</a></div>`;
        const providers = imageProviders();
        if (!providers.length) return `<div class="vkt-info">Рисовать нечем: не подключена ни одна модель для картинок. ${isAdmin() ? 'Ключ BFL (FLUX) сохраняется в «Генерации фото», ключ xAI — константой VKT_XAI_API_KEY.' : 'Генерацию подключает администратор.'}</div>`;
        const ai = state.settings.ai || {};
        const ratios = Object.keys(fluxSizes);
        if (!ratios.includes(seriesImage.ratio)) seriesImage.ratio = ratios[0];
        const run = seriesImageRun;
        return `<form data-form="series-images" class="vkt-form">
            <label>Общий стиль<textarea data-series-image="style" rows="3" maxlength="1500" placeholder="Например: фотореалистично, мягкий дневной свет, тёплые тона">${esc(seriesImage.style)}</textarea><small class="vkt-help">Добавляется к каждой картинке. Сюжет берётся из текста записи.</small></label>
            <div class="vkt-form-row">
                <label>Чем рисуем<select data-series-image="provider">${providers.map(([id, label]) => `<option value="${esc(id)}" ${id === seriesImage.provider ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label>
                <label>Формат<select data-series-image="ratio">${ratios.map(value => `<option value="${esc(value)}" ${value === seriesImage.ratio ? 'selected' : ''}>${esc(ratioLabels[value] || value)}</option>`).join('')}</select></label>
            </div>
            <div class="vkt-form-row"><label class="vkt-check"><input type="checkbox" data-series-image="slots" ${seriesImage.slots ? 'checked' : ''}>Новые слоты</label><label class="vkt-check"><input type="checkbox" data-series-image="queued" ${seriesImage.queued ? 'checked' : ''}>Записи в очереди</label></div>
            <div class="vkt-form-actions">${run ? button('Остановить', 'series-images-stop') : `<button class="vkt-button">${icon('fire')} Нарисовать фото · <span data-series-images-count>${seriesImageTargets().length}</span></button>`}</div>
            <p class="vkt-help" id="vkt-series-image-progress">${run ? seriesImageProgress(run) : 'Берутся записи с текстом и без файлов. Готовое фото сразу прикрепляется к записи; убрать или заменить его можно в окне записи.'}</p>
            ${quotaNote(ai)}
        </form>`;
    }
    async function seriesImagesRun() {
        if (seriesImageRun) return;
        const targets = seriesImageTargets();
        if (!targets.length) { toast('Нечего рисовать: нужны записи с текстом и без файлов.', true); return; }
        if (!confirm(`Нарисовать ${targets.length} картинок и прикрепить их к записям?`)) return;
        const run = seriesImageRun = {total: targets.length, done: 0, failed: 0, stop: false, error: ''};
        render();
        const progress = () => { const el = $('#vkt-series-image-progress'); if (el) el.textContent = seriesImageProgress(run); };
        const queue = targets.slice();
        const worker = async () => {
            while (queue.length && !run.stop) {
                const target = queue.shift();
                try {
                    if (target.slot) {
                        const media = await drawImage(seriesImagePrompt(target.slot.message, target.slot.imagePrompt || ''));
                        // Пока рисовали, слот могли убрать или приложить к нему файл руками.
                        if (seriesSlots.includes(target.slot) && !target.slot.media.length) target.slot.media.push(media);
                    } else {
                        // В сетке только начало текста — для сюжета нужен весь.
                        const full = await act('publishing_get', {id: Number(target.post.id)});
                        if (full.editable && !(full.media_items || []).length) {
                            const media = await drawImage(seriesImagePrompt(full.message));
                            await act('publishing_update', {id: Number(target.post.id), post: {media: [Number(media.id)]}});
                            target.post.media_count = 1;
                        }
                    }
                    run.done += 1;
                } catch (error) {
                    run.failed += 1;
                    run.error = error.message;
                    // Кончился лимит, кредиты или ключ — следующие упадут так же.
                    if (429 === Number(error.payload?.data?.status) || 402 === Number(error.payload?.data?.status) || /лимит|не настроен|не сохранён|кредит/i.test(error.message)) run.stop = true;
                }
                progress();
            }
        };
        // Два потока: быстрее одного и не упирается в лимит одновременных задач BFL.
        await Promise.all([worker(), worker()]);
        seriesImageRun = null;
        toast(run.failed ? `Нарисовано ${run.done} из ${run.total}. Ошибка: ${run.error}` : `Готово: ${run.done} картинок прикреплены к записям.`, run.failed > 0);
        await load();
    }

    function series() {
        if (!publishingData) return heading('Серия постов', 'Загружаем свои сообщества…') + '<div class="vkt-loading">Загружаем…</div>';
        const groups = seriesGroupsList();
        const aiReady = !!state.settings.ai?.configured;
        const filled = seriesFilled().length;
        const today = localStamp(new Date()).slice(0, 10);
        const subtitle = 'График на неделю или месяц в одно сообщество: даты, время, текст и фото — и всё сразу в очередь.';
        if (!groups.length) {
            return heading('Серия постов', subtitle) +
                empty('Нет доступных сообществ', 'Включите хотя бы одно сообщество в «Автопостинге»: серия отправляется туда же, куда и одиночная запись.', 'publishing', 'Открыть автопостинг');
        }
        if (!groups.some(group => Number(group.id) === Number(seriesGroup))) { seriesGroup = Number(groups[0].id); seriesTarget = ''; }
        // Дополнять можно только серию этого сообщества.
        const own = (publishingData.series?.list || []).filter(item => seriesGroupOf(item.series_id) === Number(seriesGroup));
        if (seriesTarget && !own.some(item => item.series_id === seriesTarget)) seriesTarget = '';
        // Своя группа серии в «Моих сообществах»: паспорт и лучшие часы.
        const bound = (groupsData?.groups || []).find(group => Number(group.id) === Number(seriesGroup));
        const queueLabel = seriesTarget ? `${icon('send')} Добавить в серию · ${filled}` : `${icon('send')} Поставить в очередь · ${filled}`;
        const waiting = seriesGroupPosts().filter(post => ['scheduled', 'queued', 'draft'].includes(post.status)).length;
        return heading('Серия постов', subtitle, button(`${icon('plus')} Добавить запись`, 'series-slot-add') + (seriesSlots.length ? button(queueLabel, 'series-queue', '', true) + button('Очистить сетку', 'series-clear') : '')) +
            `<div class="vkt-series-layout"><section class="vkt-panel">
                <div class="vkt-panel-heading"><div><h2>Сообщество и серия</h2><p>Серия привязана к одному сообществу. Выберите запущенную, чтобы дополнить её новыми слотами.</p></div></div>
                <form data-form="series-bind" class="vkt-form">
                    <label>Сообщество<select data-series-group>${groups.map(group => `<option value="${Number(group.id)}" ${Number(group.id) === Number(seriesGroup) ? 'selected' : ''}>${esc(group.name || `club${group.group_id}`)}</option>`).join('')}</select></label>
                    <label>Серия<select data-series-target><option value="">Новая серия</option>${own.map(item => `<option value="${esc(item.series_id)}" ${item.series_id === seriesTarget ? 'selected' : ''}>${esc(item.title)} · ${num(item.total)} зап.</option>`).join('')}</select></label>
                </form>
                <hr class="vkt-settings-sep">
                <h2>Период и время</h2>
                <p class="vkt-muted">Слоты создаются на выбранные дни недели в указанные часы. Прошедшее время пропускается.</p>
                <form data-form="series-setup" class="vkt-form">
                    <div class="vkt-form-row">
                        <label>Период<select name="span"><option value="week" ${'month' === seriesSetup.span ? '' : 'selected'}>Неделя — 7 дней</option><option value="month" ${'month' === seriesSetup.span ? 'selected' : ''}>Месяц — 30 дней</option></select></label>
                        <label>Начать с<input type="date" name="start" value="${esc(seriesSetup.start || today)}" min="${esc(today)}"></label>
                    </div>
                    <fieldset class="vkt-weekdays"><legend>Дни недели</legend>${weekdayNames.map(([value, label]) => `<label class="vkt-check"><input type="checkbox" name="weekdays" value="${value}" ${seriesSetup.weekdays.includes(value) ? 'checked' : ''}>${label}</label>`).join('')}</fieldset>
                    <label>Время публикации<input name="times" value="${esc(seriesSetup.times)}" placeholder="10:00, 19:00"><small class="vkt-help">Через запятую. Каждое время даёт по слоту в каждый выбранный день, всего не больше 60 слотов.</small></label>
                    ${bound?.best_times?.length ? `<p class="vkt-help">Лучше всего у группы заходили посты в ${esc(bestTimes(bound.best_times))}. ${button('Подставить это время', 'series-best-times')}</p>` : ''}
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">${icon('clock')} Построить сетку</button></div>
                </form>
                ${aiReady ? `<hr class="vkt-settings-sep">
                <h2>Промпт на всю серию</h2>
                <p class="vkt-muted">Один запрос на весь период: модель видит, сколько нужно текстов, и не повторяет себя. Тексты разложатся по пустым слотам по порядку.</p>
                <form data-form="series-prompt" class="vkt-form">
                    <p class="vkt-help">${bound?.has_passport ? `Модель учтёт паспорт группы «${esc(bound.name)}» и её последние посты — темы не повторятся.` : `Модель учтёт последние посты группы. Паспорта у неё нет — ${button('Заполнить паспорт', 'group-open', `data-id="${Number(seriesGroup)}"`)}`}${Number(bound?.materials_count) ? ` Пишет по базе ведения группы — материалов: ${num(bound.materials_count)}.` : ''}</p>
                    ${modelPicker(state.settings.ai)}
                    <label>Тема серии<textarea name="prompt" data-series-prompt rows="4" maxlength="5000" placeholder="Например: неделя про доставку запчастей — каждый пост об одном возражении клиента">${esc(seriesPrompt)}</textarea></label>
                    <div class="vkt-form-actions"><button class="vkt-button" ${seriesSlots.length ? '' : 'disabled'}>${icon('fire')} Сгенерировать ${seriesSlots.length || ''} текстов</button></div>
                    ${quotaNote(state.settings.ai)}
                    <p class="vkt-help">${seriesSlots.length ? 'Заполнятся только пустые слоты — написанное руками останется.' : 'Сначала постройте сетку: модели нужно знать количество.'}</p>
                </form>` : `<hr class="vkt-settings-sep"><div class="vkt-info">Не подключена ни одна модель для текстов, поэтому промпта на серию нет. ${isAdmin() ? '<a href="#settings">Добавить модель — «Настройки» →</a>' : 'Её подключает администратор.'} Текст можно вписать в каждый слот руками.</div>`}
                <hr class="vkt-settings-sep">
                <h2>Фото к записям</h2>
                <p class="vkt-muted">Когда тексты утверждены — картинки ко всей сетке одной кнопкой. К отдельной записи — из её окна.</p>
                ${seriesImagesPanel()}
                <hr class="vkt-settings-sep">
                <h2>Публикация</h2>
                <form data-form="series-groups" class="vkt-form">
                <div class="vkt-form-row"><label class="vkt-check"><input type="checkbox" name="signed">Подписать записи моим именем</label><label class="vkt-check"><input type="checkbox" name="close_comments">Закрыть комментарии</label></div>
                <p class="vkt-help">${state.settings.publishing_review ? 'Включена ручная проверка: записи серии сохранятся черновиками и будут ждать подтверждения.' : 'Каждая запись уйдёт в своё время. Слоты в прошлом сервер отклонит.'}</p></form>
            </section>
            <section class="vkt-panel">
                <div class="vkt-panel-heading"><div><h2>Календарь${seriesTarget ? ` · ${esc(seriesTitle(seriesTarget))}` : ''}</h2><p>${seriesSlots.length ? `${seriesSlots.length} новых слотов, заполнено ${filled}. ` : 'Новых слотов пока нет. '}${waiting ? `В очереди этого сообщества: ${waiting} — нажмите на запись, чтобы поправить текст, фото или время либо убрать её.` : ''}</p></div></div>
                ${seriesCalendar()}
            </section></div>` + runningSeries();
    }

    // Окно слота и окно записи перерисовываются после выбора файла или
    // генерации — набранное сначала сохраняем, иначе оно пропадёт.
    function captureSeriesDialog() {
        const form = $('#vkt-dialog-content [data-form="series-slot"], #vkt-dialog-content [data-form="series-post"]');
        if (!form) return;
        const target = form.dataset.form === 'series-slot' ? seriesSlots[Number(form.elements.index.value)] : seriesEdit?.draft;
        if (!target) return;
        target.message = form.elements.message.value;
        target.attachments = form.elements.attachments.value;
        target.imagePrompt = form.elements.image_prompt?.value || '';
        if (form.elements.shop_title) {
            const field = name => form.elements[name]?.value || '';
            target.shop = {product: field('shop_product'), title: field('shop_title'), url: field('shop_url'), hook: field('shop_hook'), format: field('shop_format'), facts: field('shop_facts')};
            target.shopOpen = !!form.querySelector('.vkt-shop-box')?.open;
        }
        if (validStamp(form.elements.at?.value)) target.at = form.elements.at.value;
    }
    const imageField = value => imagesReady() ? `<label>Промпт для фото<input name="image_prompt" value="${esc(value || '')}" maxlength="3000" placeholder="Пусто — по тексту записи и общему стилю"></label>` : '';

    // Товарный пост по методике VK Shops: нужен одной-двум записям серии, поэтому живёт в окне записи.
    function shopBox(target, attr = '') {
        const ai = state.settings.ai || {};
        if (!ai.configured) return '';
        const shop = target.shop || {};
        const options = state.settings.shops || {hooks: {}, formats: {}};
        const pick = (name, map, current, auto) => `<select name="${name}"><option value="">${auto}</option>${Object.entries(map).map(([key, label]) => `<option value="${esc(key)}" ${key === current ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select>`;
        const products = (state.products || []).map(product => `<option value="${Number(product.id)}" ${String(product.id) === String(shop.product) ? 'selected' : ''}>${esc(product.title)}</option>`).join('');
        return `<details class="vkt-shop-box" ${target.shopOpen ? 'open' : ''}><summary>${icon('bag')} Товарный пост · VK Shops</summary>
            <p class="vkt-help">Превращает запись в продающий пост по методике VK Shops: хук, ситуация, товар как решение, ссылка в конце. Текст записи станет основой; пустая запись напишется с нуля.</p>
            <label>Товар<select name="shop_product"><option value="">— вписать название и ссылку ниже —</option>${products}</select></label>
            <div class="vkt-form-row"><label>Название товара<input name="shop_title" maxlength="255" value="${esc(shop.title || '')}" placeholder="Если товара нет в списке"></label><label>Ссылка на товар<input name="shop_url" type="url" value="${esc(shop.url || '')}" placeholder="https://…"></label></div>
            <div class="vkt-form-row"><label>Хук${pick('shop_hook', options.hooks, shop.hook, 'на выбор модели')}</label><label>Подача${pick('shop_format', options.formats, shop.format, 'на выбор модели')}</label></div>
            <label>Что известно по факту<textarea name="shop_facts" rows="3" maxlength="3000" placeholder="Цена, свойства, ваш реальный опыт и результат. Чего здесь нет — модель утверждать не будет.">${esc(shop.facts || '')}</textarea></label>
            <div class="vkt-ai-row">${modelPicker(ai)}${button(`${icon('fire')} Сделать товарным`, 'series-shop', attr, true)}</div>
        </details>`;
    }
    async function seriesDialogShop(trigger) {
        captureSeriesDialog();
        const slot = trigger.dataset.index !== undefined ? seriesSlots[Number(trigger.dataset.index)] : null;
        const target = slot || seriesEdit?.draft;
        if (!target) return;
        const shop = target.shop || {};
        if (!Number(shop.product) && !String(shop.title || '').trim()) { toast('Выберите товар из списка или впишите его название.', true); return; }
        const note = $('#vkt-series-dialog-progress');
        if (note) { note.hidden = false; note.textContent = 'Пишем товарный пост… Обычно это до минуты.'; }
        trigger.disabled = true;
        try {
            const result = await act('shop_post', {group_id: Number(seriesGroup), model: aiModel, product_id: Number(shop.product) || 0, title: shop.title, url: shop.url, hook: shop.hook, format: shop.format, facts: shop.facts, current: target.message});
            target.message = result.text;
            toast(slot ? 'Запись стала товарной — проверьте текст и сохраните слот.' : 'Запись стала товарной. Нажмите «Сохранить», чтобы очередь получила новый текст.');
        } catch (error) { toast(error.message, true, error.fix); }
        finally { trigger.disabled = false; }
        if (!dialog.open) return;
        if (slot) seriesSlotDialog(seriesSlots.indexOf(slot)); else seriesPostDialog(0, false);
    }
    function seriesSlotDialog(index) {
        const slot = seriesSlots[index];
        if (!slot) return;
        seriesSlotTarget = index;
        seriesEdit = null;
        mediaTarget = 'series';
        const [day, time] = slot.at.split('T');
        modal(`<h2>Слот · ${esc(day)} в ${esc(time)}</h2>
            <form data-form="series-slot" class="vkt-form">
                <input type="hidden" name="index" value="${Number(index)}">
                <label>Время публикации<input type="datetime-local" name="at" value="${esc(slot.at)}" min="${esc(localStamp(new Date()))}" required></label>
                <label>Текст записи<textarea name="message" rows="8" maxlength="16000" placeholder="Текст этого поста">${esc(slot.message)}</textarea></label>
                ${shopBox(slot, `data-index="${Number(index)}"`)}
                <label>Вложения VK или ссылка<input name="attachments" class="vkt-code-input" value="${esc(slot.attachments)}" placeholder="photo-123_456 или https://example.com"></label>
                ${imageField(slot.imagePrompt)}
                <div class="vkt-media-chips">${slot.media.length ? slot.media.map(mediaChip).join('') : '<p class="vkt-muted">Файлы не выбраны.</p>'}</div>
                <div class="vkt-media-actions">${button(`${icon('layers')} Из медиатеки`, 'series-media', `data-index="${Number(index)}"`)}${imagesReady() ? button(`${icon('fire')} Нарисовать фото`, 'series-slot-image', `data-index="${Number(index)}"`) : ''}${slot.media.length ? button('Эти файлы во все слоты', 'series-apply-media', `data-index="${Number(index)}"`) : ''}${button('Очистить слот', 'series-slot-clear', `data-index="${Number(index)}"`)}${button('Убрать слот', 'series-slot-remove', `data-index="${Number(index)}"`)}</div>
                <p class="vkt-help" id="vkt-series-dialog-progress" hidden></p>
                <div class="vkt-form-actions"><button class="vkt-button vkt-primary">Сохранить слот</button></div>
            </form>`);
    }

    // Запись из очереди: правка текста, фото и времени до отправки.
    async function seriesPostDialog(id, fresh = true) {
        if (fresh) {
            modal('<h2>Запись серии</h2><p class="vkt-muted">Загружаем запись…</p>');
            const post = await act('publishing_get', {id: Number(id)});
            seriesEdit = {id: Number(post.id), post, draft: {message: post.message || '', attachments: post.attachments || '', media: post.media_items || [], at: localStamp(parseDate(post.scheduled_at)), imagePrompt: ''}};
        }
        if (!seriesEdit) return;
        const {post, draft} = seriesEdit;
        seriesSlotTarget = null;
        mediaTarget = 'series-post';
        const [label, tone] = queuedStatuses[post.status] || [post.status, ''];
        if (!post.editable) {
            modal(`<h2>${esc(post.series_title || 'Запланированная запись')}</h2><p>${badge(esc(label), tone)}</p><div class="vkt-info">Запись уже уходит в VK или опубликована — править её поздно.</div><div class="vkt-review-copy">${esc(post.message || 'Без текста')}</div>`);
            return;
        }
        modal(`<h2>${esc(post.series_title || 'Запланированная запись')}</h2>
            <p class="vkt-muted">${badge(esc(label), tone)} Правка сохранится в очереди и уйдёт в VK в указанное время.${post.media_missing ? ' Часть файлов удалена из медиатеки — выберите их заново.' : ''}</p>
            <form data-form="series-post" class="vkt-form">
                <label>Время публикации<input type="datetime-local" name="at" value="${esc(draft.at)}" min="${esc(localStamp(new Date()))}" required></label>
                <label>Текст записи<textarea name="message" rows="8" maxlength="16000" placeholder="Текст этого поста">${esc(draft.message)}</textarea></label>
                ${shopBox(draft)}
                <label>Вложения VK или ссылка<input name="attachments" class="vkt-code-input" value="${esc(draft.attachments)}" placeholder="photo-123_456 или https://example.com"></label>
                ${imageField(draft.imagePrompt)}
                <div class="vkt-media-chips">${draft.media.length ? draft.media.map(mediaChip).join('') : '<p class="vkt-muted">Файлы не выбраны.</p>'}</div>
                <div class="vkt-media-actions">${publishingData?.status?.media_native ? button(`${icon('layers')} Из медиатеки`, 'series-post-media') : ''}${imagesReady() ? button(`${icon('fire')} ${draft.media.length ? 'Нарисовать ещё' : 'Нарисовать фото'}`, 'series-post-image') : ''}</div>
                <p class="vkt-help" id="vkt-series-dialog-progress" hidden></p>
                <div class="vkt-form-actions"><button class="vkt-button vkt-primary">Сохранить</button>${button('Убрать из серии', 'series-post-delete', `data-id="${Number(post.id)}"`)}</div>
            </form>`);
    }

    // Картинка к одному слоту или записи — из их окна.
    async function seriesDialogImage(trigger) {
        captureSeriesDialog();
        const slot = trigger.dataset.index !== undefined ? seriesSlots[Number(trigger.dataset.index)] : null;
        const target = slot || seriesEdit?.draft;
        if (!target) return;
        if (!String(target.message || '').trim() && !String(target.imagePrompt || '').trim()) { toast('Нужен текст записи или свой промпт для фото.', true); return; }
        if (target.media.length >= 10) { toast('VK принимает не больше 10 вложений в одной записи.', true); return; }
        const note = $('#vkt-series-dialog-progress');
        const say = text => { if (note) { note.hidden = false; note.textContent = text; } };
        trigger.disabled = true;
        say('Рисуем… Обычно это 10–40 секунд.');
        try {
            const media = await drawImage(seriesImagePrompt(target.message, target.imagePrompt || ''), text => say(text));
            target.media.push(media);
            toast(slot ? 'Фото прикреплено к слоту.' : 'Фото прикреплено. Нажмите «Сохранить», чтобы запись в очереди его получила.');
        } catch (error) { toast(error.message, true, error.fix); }
        finally { trigger.disabled = false; }
        // Пока рисовали, окно могли закрыть — тогда не открываем его заново.
        if (!dialog.open) return;
        if (slot) seriesSlotDialog(seriesSlots.indexOf(slot)); else seriesPostDialog(0, false);
    }
    function addSeriesPostMedia(item) {
        const draft = seriesEdit?.draft;
        if (!draft || !item || !item.id) return false;
        if (draft.media.some(media => Number(media.id) === Number(item.id))) { toast('Этот файл в записи уже есть.'); return false; }
        if (draft.media.length >= 10) { toast('VK принимает не больше 10 вложений в одной записи.', true); return false; }
        draft.media.push(item);
        return true;
    }

    // Остаток суточного лимита генерации. У администратора лимита нет — строки тоже.
    // Выбор модели там, где пишется текст. Выбор общий для всех разделов до перезагрузки страницы.
    const modelPicker = ai => {
        const models = (ai && ai.models) || [];
        if (!models.length) return '';
        const current = models.some(model => model.id === aiModel) ? aiModel : (ai.default_model || models[0].id);
        return `<label class="vkt-model-pick">Модель для текста<select data-ai-model>${models.map(model => `<option value="${esc(model.id)}" ${model.id === current ? 'selected' : ''}>${esc(model.title)}</option>`).join('')}</select></label>`;
    };
    const quotaNote = ai => {
        const quota = ai && ai.quota;
        if (!quota) return '';
        return `<p class="vkt-quota">Сегодня осталось: текстов ${num(quota.text?.left)} из ${num(quota.text?.limit)}, картинок и видео ${num(quota.media?.left)} из ${num(quota.media?.limit)}.</p>`;
    };

    // ——— Подключения: чтение и публикация разведены по разным страницам ———
    const slotCard = (slots, name) => { const found = (slots || []).find(item => item.slot === name); return found ? tokenCard(found) : ''; };
    // Зелёная точка — только у живого ключа: сохранённый, но отвергнутый VK не считается.
    const hasSlot = (s, name) => (s.tokens || []).some(item => item.slot === name && item.has_token && item.alive !== false);
    const readyReading = s => hasSlot(s, 'service');
    const readyPosting = s => hasSlot(s, 'user') && ((s.community || {}).keys || []).some(key => key.alive);

    function reading() {
        const s = state.settings;
        return heading('Чтение постов', 'Всё, чем плагин собирает чужие стены: посты, ролики, счётчики и товары по ссылкам.') +
            `<div class="vkt-settings-grid"><section class="vkt-panel">
                <h2>Сервисный ключ приложения</h2>
                <p class="vkt-muted">Единственный ключ, который нужен для сбора. Он читает стены через <code>wall.get</code> и по замыслу VK больше ничего не умеет: на загрузку файлов и публикацию отвечает кодом 28. Это нормально и ни на что не влияет.</p>
                <div class="vkt-token-cards">${slotCard(s.tokens, 'service')}</div>
                <hr class="vkt-settings-sep">
                <h2>Расписание и разбор страниц</h2>
                <form data-form="settings" class="vkt-form">
                    <div class="vkt-form-row"><label>Версия API<input name="api_version" value="${esc(s.api_version)}" required pattern="5\.[0-9]{1,3}"></label></div>
                    <div class="vkt-form-row"><label>Обход сообществ<select name="source_hours">${hourOptions(s.source_hours)}</select><small class="vkt-help">Посты и видео со стены.</small></label><label>Замеры роликов<select name="video_hours">${hourOptions(s.video_hours)}</select><small class="vkt-help">Точечное обновление счётчиков.</small></label></div>
                    <label class="vkt-check"><input type="checkbox" name="paused" ${s.paused?'checked':''}>Пауза автоматического сбора</label>
                    <label class="vkt-check"><input type="checkbox" name="posts" ${s.posts?'checked':''}>Сохранять посты сообществ при обходе стены</label>
                    <label class="vkt-check"><input type="checkbox" name="links" ${s.links?'checked':''}>Читать страницы товаров по ссылкам известных магазинов</label>
                    <label>Сервис рендеринга страниц<input name="proxy" autocomplete="off" maxlength="500" placeholder="${s.proxy_host?`Сейчас: ${esc(s.proxy_host)} · впишите новый адрес или очистите поле`:'https://api.example.com/?api_key=КЛЮЧ&url={url}'}"><small class="vkt-help">Пустое поле ничего не меняет, дефис очищает сохранённый адрес. Нужен там, где магазин отвечает роботу проверкой вместо карточки товара.</small></label>
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">Сохранить</button></div>
                </form>
            </section>
            <aside class="vkt-panel vkt-settings-help"><span class="vkt-help-icon">${icon('eye')}</span><h2>Что даёт чтение</h2><p>Посты и ролики наблюдаемых сообществ, динамику просмотров, сводку по сообществам и товары, узнанные по ссылкам из постов.</p><hr><h3>Откуда берутся сообщества</h3><p>Список наблюдения — в разделе «Источники». Обход идёт по расписанию cron, вручную запускается в «Сборе данных».</p><hr><h3>Публикация тут ни при чём</h3><p>Для отправки записей нужны другие ключи, они живут в разделе «Публикация». Сервисный ключ публиковать не умеет и не должен.</p></aside></div>`;
    }

    // ——— Группы по ключам сообществ: у каждой свой ключ, он не истекает ———
    const communityKeyHelp = 'В группе VK: Управление → Дополнительно → Работа с API → Ключи доступа → «Создать ключ». Отметьте доступ к стене сообщества (для записей и ответов обязателен) и скопируйте ключ целиком. Ключ сообщества не истекает, поэтому группа работает без суточного пользовательского токена.';
    const communityKeyForm = () => `<form data-form="community-key" class="vkt-form"><label>Ключ доступа сообщества<input name="token" class="vkt-code-input" autocomplete="off" required placeholder="vk1.a.…"></label><label>Ссылка или ID группы<input name="group" placeholder="Необязательно: vk.com/club123 или 123"><small class="vkt-help">Можно оставить пустым — VK сам скажет, чей это ключ.</small></label><button type="submit" class="vkt-button vkt-primary">${icon('plus')} Добавить группу</button></form>`;
    function communityKeys() {
        const keys = (state.settings.community || {}).keys || [];
        const rows = keys.map(key => {
            const title = esc(key.name || `club${key.group_id}`);
            const status = !key.alive ? badge('ключ не действует', 'red') : !key.can_post ? badge('нет права «Стена»', 'red') : badge('работает', 'green');
            const link = `https://vk.com/${esc(key.screen_name || `club${Number(key.group_id)}`)}`;
            return `<div><span>${safeUrl(key.photo || '') ? `<img src="${safeUrl(key.photo)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : ''}<strong>${title}</strong><small><a href="${link}" target="_blank" rel="noopener noreferrer">${esc(key.screen_name || `club${Number(key.group_id)}`)} ↗</a>${key.error && key.error.message ? ` · ${esc(key.error.message)}` : ''}</small></span><span>${status}${key.legacy ? '' : button('Убрать', 'community-key-forget', `data-id="${Number(key.group_id)}"`)}</span></div>`;
        }).join('');
        return `<h2>Группы по ключам сообществ</h2><p class="vkt-muted">${esc(communityKeyHelp)}</p>${keys.length ? `<div class="vkt-own-groups">${rows}</div>` : '<p class="vkt-muted">Пока ни одной группы с ключом.</p>'}${communityKeyForm()}`;
    }
    // Сколько осталось жить токену — словами: клиенту понятнее «через 5 ч», чем секунды.
    function tokenLife(seconds) {
        const hours = Math.floor(seconds / 3600);
        if (hours >= 1) return `${hours} ч${hours < 6 ? ` ${Math.round((seconds % 3600) / 60)} мин` : ''}`;
        return `${Math.max(1, Math.round(seconds / 60))} мин`;
    }
    /**
     * Пользовательский токен — главное, с чем путаются клиенты. Раньше наверху
     * стояла сырая форма с refresh_token и device_id, а рабочий обмен кода был
     * ниже. Теперь здесь состояние токена, когда его продлевать и три шага
     * подключения; ручная вставка осталась только администратору для отладки.
     */
    function userTokenBlock(s, oauth, admin) {
        const slot = (s.tokens || []).find(item => item.slot === 'user') || {slot: 'user', title: 'Пользовательский токен', hint: '', has_token: false};
        const left = slot.expires_in === null || slot.expires_in === undefined ? null : Number(slot.expires_in);
        const dead = slot.has_token && (slot.alive === false || left === 0);
        const soon = slot.has_token && !dead && left !== null && left < 6 * 3600;
        const until = left ? new Date(Date.now() + left * 1000).toLocaleString('ru-RU', {day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'}) : '';
        const status = !slot.has_token ? badge('не подключён')
            : dead ? badge('истёк — переподключите', 'red')
            : soon ? badge(`истекает через ${tokenLife(left)}`, 'red')
            : badge(left !== null ? `действует до ${until}` : 'подключён', 'green');
        const summary = !slot.has_token
            ? 'Токен ещё не подключён. Без него записи уходят только текстом: фото и видео не приложатся, а список ваших сообществ не обновится.'
            : dead
                ? `VK больше не принимает токен${slot.error?.message ? `: ${slot.error.message}` : '.'} Пройдите три шага ниже — это займёт минуту.`
                : soon
                    ? `Токен закончится ${until}. Переподключите его заранее, чтобы запланированные записи с фото не встали.`
                    : `Всё работает${left !== null ? ` до ${until}` : ''}.${slot.refreshable ? ' Токен обновляется сам.' : ''}`;
        const ready = !!oauth.configured || admin;
        return `<section class="vkt-user-token${dead ? ' is-dead' : soon ? ' is-soon' : ''}" id="vkt-user-token">
            <div class="vkt-panel-heading"><div><h2>Пользовательский токен VK</h2><p class="vkt-muted">Нужен, чтобы прикладывать к записям фото и видео и видеть список ваших сообществ. Текст без вложений публикуется и без него — ключом сообщества.</p></div>${status}</div>
            <div class="vkt-info${dead || soon ? ' vkt-info-warning' : ''}">${esc(summary)}</div>
            ${slot.has_token ? `<div class="vkt-token-preview"><code>${esc(slot.preview || '')}</code> <span class="vkt-muted">${slot.scope ? `права: ${esc(slot.scope)}` : ''}</span> ${button('Проверить', 'token-probe', 'data-slot="user"')}${button('Отключить', 'token-forget', 'data-slot="user"')}</div>` : ''}
            <details class="vkt-details vkt-token-why"${slot.has_token ? '' : ' open'}><summary>Почему токен надо обновлять каждый день</summary>
                <p class="vkt-muted">VK выдаёт такой токен примерно на сутки. Раньше срок снимало право «offline», но в 2026 году VK его отменил, поэтому продлить токен сам сайт не может — только вы, повторным подключением. Это те же три шага ниже. За 6 часов до конца срока в кабинете появится предупреждение со ссылкой сюда, а записи с фото будут ждать нового токена, а не пропадать.</p>
            </details>
            <h3>${slot.has_token ? 'Переподключить' : 'Подключить'} — три шага</h3>
            ${!ready ? '<div class="vkt-info vkt-info-warning">Администратор ещё не настроил приложение сайта — получить токен пока нельзя. Напишите ему.</div>' : ''}
            <form data-form="oauth" class="vkt-form">
                <ol class="vkt-token-steps">
                    <li><strong>Разрешите доступ во ВКонтакте.</strong> Откроется новая вкладка VK — войдите тем аккаунтом, который администрирует ваши сообщества, и нажмите «Разрешить».
                        <div class="vkt-form-actions">${button(`${icon('arrow')} Открыть VK`, 'oauth-open', ready ? '' : 'disabled', true)}${safeUrl(oauth.authorize_url || '') ? `<a class="vkt-button" href="${safeUrl(oauth.authorize_url)}" target="_blank" rel="noopener noreferrer">если вкладка не открылась ↗</a>` : ''}</div></li>
                    <li><strong>Скопируйте адрес страницы.</strong> VK покажет почти пустую страницу, иногда с предупреждением «не копируйте данные из адресной строки». Здесь копировать можно: адрес уходит только на ваш сайт, и в нём одноразовый код, а не сам токен. Щёлкните по адресной строке, выделите всё и скопируйте.</li>
                    <li><strong>Вставьте адрес сюда.</strong> Токен подключится сразу после вставки — код живёт около минуты, не медлите.
                        <label>Адрес из браузера<input name="code" class="vkt-code-input" placeholder="https://oauth.vk.com/blank.html?code=…" autocomplete="off" ${ready ? '' : 'disabled'}></label>
                        <div class="vkt-form-actions"><button class="vkt-button vkt-primary" ${ready ? '' : 'disabled'}>Подключить</button></div></li>
                </ol>
            </form>
            ${admin && !slot.locked ? `<details class="vkt-details"><summary>Вставить токен вручную · для отладки</summary>
                <p class="vkt-muted">Токен, полученный в браузере, VK привязывает к адресу этого браузера, и с сервера он работает не всегда. Поля refresh_token, device_id и client_id нужны, только если способ получения их выдал — тогда токен будет обновляться сам.</p>
                ${tokenCard(slot)}
            </details>` : ''}
        </section>`;
    }
    function posting() {
        const s = state.settings;
        const community = s.community || {};
        const ai = s.ai || {};
        const vkid = s.vkid || {};
        const oauth = s.oauth || {};
        const constantToken = s.token_source === 'wp-config.php';
        const currentKind = s.token_mode || 'service';
        const admin = isAdmin();
        const steps = [
            {ok: hasSlot(s, 'user'), title: 'Пользовательский токен', text: 'Грузит файлы в сообщество. Другого способа приложить фото или видео VK не даёт.'},
            admin
                ? {ok: hasSlot(s, 'app_secret'), title: 'Защищённый ключ приложения', text: 'Нужен, чтобы токен получал сервер, а не браузер: иначе VK привяжет токен к чужому адресу.'}
                : {ok: !!oauth.configured, title: 'Приложение сайта', text: 'Общее для всех кабинетов, его настраивает администратор. Через него вы получите свой токен.'},
            {ok: !!(community.keys || []).some(key => key.alive && key.can_post), title: 'Ключи сообществ', text: 'Публикуют записи и ответы на стене своих групп, по ключу на группу. Пользовательскому токену wall.post закрыт.'},
        ];
        return heading('Публикация', 'Ключи и настройки для отправки записей. Файл грузит один ключ, публикует другой — так устроен VK.', button('Стенд постинга', 'probe-matrix')) +
            `<div class="vkt-settings-grid"><section class="vkt-panel">
                <h2>Что должно быть на месте</h2>
                <ol class="vkt-steps">${steps.map(step => `<li>${badge(step.ok ? 'готово' : 'нет', step.ok ? 'green' : '')}<div><strong>${esc(step.title)}</strong><span class="vkt-muted">${esc(step.text)}</span></div></li>`).join('')}</ol>
                <p class="vkt-help">Проверить всё разом — кнопка «Стенд постинга» наверху: она спрашивает каждый ключ только о его работе и подводит итог по каждому сообществу.</p>
                <hr class="vkt-settings-sep">
                ${userTokenBlock(s, oauth, admin)}
                <hr class="vkt-settings-sep">
                ${communityKeys()}
                <hr class="vkt-settings-sep">
                <h2>Ключи публикации</h2>
                <p class="vkt-muted">Ключ доступа сообщества здесь и в «Группах по ключам сообществ» выше — один и тот же ключ из VK. Проще добавлять группы списком выше; этот блок остался для одной основной группы.</p>
                <div class="vkt-token-cards">${(s.tokens || []).filter(slot => ['app_secret', 'community'].includes(slot.slot)).map(tokenCard).join('')}</div>
                <form data-form="settings" id="vkt-posting-form" class="vkt-form">
                    <label>ID основной группы<input type="number" name="community_id" value="${Number(community.group_id) || ''}" min="0" placeholder="Например, 241464933"></label>
                    <label class="vkt-check"><input type="checkbox" name="publishing_review" ${s.publishing_review?'checked':''}>Требовать ручную проверку публикаций</label>
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">Сохранить</button></div>
                </form>
                <hr class="vkt-settings-sep">
                ${admin ? `<h2>Приложение сайта · для обмена кода</h2>
                <p class="vkt-muted">Через это приложение все кабинеты получают пользовательский токен: браузер получает одноразовый код, а меняет его на токен сервер защищённым ключом. Поэтому права классические (<code>${esc(oauth.scope || '')}</code>), а привязка к IP приходится на сервер.</p>
                <form data-form="oauth-app" class="vkt-form">
                    <div class="vkt-form-row">
                        <label>ID приложения<input type="number" name="app_id" value="${Number(oauth.app_id) || ''}" min="1" placeholder="Например, 54770323"></label>
                        <label>Адрес возврата приложения<input class="vkt-code-input" value="${esc(oauth.redirect || '')}" readonly></label>
                    </div>
                    <div class="vkt-info">Здесь нужен ID приложения из консоли <strong>dev.vk.ru</strong> — это <strong>не тот же номер</strong>, что в блоке VK ID ниже. Приложение из кабинета VK ID классический OAuth не обслуживает и отвечает <code>Security Error</code>; плагин проверит это до перехода. Защищённый ключ того же приложения сохраните в слоте «Защищённый ключ приложения» выше.</div>
                    <div class="vkt-form-actions"><button class="vkt-button">Сохранить ID приложения</button></div>
                </form>` : ''}
                ${admin ? `<hr class="vkt-settings-sep">
                <details class="vkt-details"><summary>Запасные способы получить токен</summary>
                <p class="vkt-muted">Нужны, только если обмен кода почему-то не идёт. Оба дают токен на сутки и без автообновления.</p>
                <h3>Способ 1 · вставить токен вручную</h3>
                <p class="vkt-muted">Нажмите кнопку, разрешите доступ, затем скопируйте из браузера <strong>весь адрес целиком</strong> и вставьте его в поле ниже — плагин сам достанет токен и срок жизни и выберет нужный тип. Токен из браузера привязан к вашему адресу, поэтому для автопостинга с сервера он не годится: им можно только проверить доступность методов.</p>
                <div class="vkt-form-actions">${button(`${icon('arrow')} Открыть страницу VK`, 'vkid-implicit')}</div>
                <form data-form="settings" class="vkt-form">
                    <div class="vkt-field-status">${badge(s.has_token?'Токен сохранён':'Не подключён',s.has_token?'green':'')}<span class="vkt-muted">${constantToken?'Задан в wp-config.php':'Хранится на сервере в зашифрованном виде'}</span></div>
                    <label>Тип токена<select name="token_kind" ${constantToken?'disabled':''}><option value="service" ${currentKind==='service'?'selected':''}>Сервисный ключ приложения</option><option value="user" ${currentKind==='user'?'selected':''}>Пользовательский токен</option></select></label>
                    <label>Access token<input type="password" name="token" autocomplete="new-password" placeholder="${s.has_token?'Оставьте пустым, чтобы сохранить текущий':'Токен или весь адрес из браузера'}" ${constantToken?'disabled':''} maxlength="2048"></label>
                    <small class="vkt-help">Принимается целиком адрес вида <code>https://oauth.vk.com/blank.html#access_token=…&amp;expires_in=86400</code>.</small>
                    <div class="vkt-form-actions"><button class="vkt-button">Сохранить токен</button>${s.has_token && !constantToken?button('Удалить токен','delete-token'):''}</div>
                </form>
                <h3>Способ 2 · VK ID</h3>
                ${vkid.blocked_by_constant ? '<div class="vkt-info vkt-info-warning">В <code>wp-config.php</code> задан <code>VKT_ACCESS_TOKEN</code>. Эта константа всегда трактуется как сервисный ключ и имеет приоритет над настройками, поэтому пользовательский токен сохранить не получится.</div>' : ''}
                <p class="vkt-muted">Проверено живыми запросами: токен VK ID несёт только права VK ID, а классические методы отвечают <code>1051</code>. Для загрузки файлов он не подходит — оставлен на случай, если VK вернёт эти права.</p>
                <form data-form="vkid" class="vkt-form">
                    <label>ID приложения VK ID<input type="number" name="vkid_client_id" value="${Number(vkid.client_id) || ''}" min="1" step="1" placeholder="Например, 54755819" ${vkid.locked ? 'disabled' : ''}></label>
                    <label>Доверенный redirect URI<input class="vkt-code-input" name="vkid_redirect" value="${esc(vkid.redirect_uri || '')}" spellcheck="false"></label>
                    <p class="vkt-help">Адрес должен быть заранее прописан в приложении как доверенный. <strong>У приложений типа VK Mini App такого раздела нет</strong> — VK отвечает <code>redirect_uri is incorrect</code>. Запрашиваемые права: <code>${esc(vkid.scope || '')}</code>.</p>
                    <div class="vkt-form-actions"><button class="vkt-button">${icon('lock')} ${vkid.blocked_by_constant ? 'Сохранить ID приложения' : 'Подключить VK ID'}</button></div>
                </form>
                <p class="vkt-help">Этот же ID приложения VK ID открывает пользователям вход через VK на главной.</p>
                </details>` : ''}
            </section>
            <aside class="vkt-panel vkt-settings-help"><span class="vkt-help-icon">${icon('post')}</span><h2>Кто что делает</h2><p>Файл в сообщество грузит <strong>пользовательский токен</strong>: <code>photos.getWallUploadServer</code> ключу сообщества закрыт кодом 27. Саму запись публикует <strong>ключ сообщества</strong>: пользовательскому токену <code>wall.post</code> отвечает кодом 15 для приложений не типа Standalone. Фотография при этом попадает в альбом стены той же группы, поэтому вложение принимается.</p><hr><h3>Callback API</h3>${community.configured ? (community.callback_configured ? `<label>Адрес Callback API<input class="vkt-code-input" value="${esc(community.callback_url)}" readonly></label><p class="vkt-help">Укажите этот адрес в настройках Callback API и нажмите «Подтвердить» в VK.</p>${button('Проверить ключ и права', 'community-check')}` : `<p class="vkt-muted">${admin ? 'Данные Callback API не настроены.' : 'Callback API подключает администратор сайта для своего сообщества.'}</p>${button('Проверить ключ и права', 'community-check')}`) : '<p class="vkt-muted">Ключ сообщества не настроен — сохраните его в карточке «Ключ сообщества» и укажите ID своего сообщества.</p>'}<hr><h3>Генерация xAI</h3>${ai.configured ? `<p>${admin ? 'Ключ задан в wp-config.php и в браузер не возвращается. ' : ''}Модели: <code>${esc(ai.text_model)}</code>, <code>${esc(ai.image_model)}</code>, <code>${esc(ai.video_model)}</code>. Кнопки генерации доступны в конструкторе записи.</p>${quotaNote(ai)}` : `<p class="vkt-muted">${admin ? 'Константа <code>VKT_XAI_API_KEY</code> не задана — кнопки генерации скрыты.' : 'Генерация на сайте не подключена.'}</p>`}<hr><a href="https://dev.vk.com/ru/method/wall.post" target="_blank" rel="noopener noreferrer">wall.post в документации VK ↗</a></aside></div>`;
    }

    // Справочник вложений: что VK позволяет приложить к записи и чего это стоит.
    const attachmentKinds = [
        {name: 'Фото', id: 'photo-123_456', now: 'Файлом из медиатеки, загрузкой с компьютера или генерацией', need: 'Пользовательский токен с правом photos', state: 'работает'},
        {name: 'Видео', id: 'video-123_456', now: 'Файлом MP4 до 100 МБ', need: 'Пользовательский токен и включённый раздел «Видеозаписи» в сообществе — иначе VK отвечает ошибкой 7', state: 'работает'},
        {name: 'Музыка', id: 'audio-123_456', now: 'Отклоняется при создании записи', need: 'VK разрешает audio только вместе с фото или видео в режиме карусели — нужен отдельный режим в конструкторе', state: 'впереди'},
        {name: 'Документ, GIF', id: 'doc-123_456', now: 'Только готовым ID, один на запись', need: 'Загрузку docs.getWallUploadServer ключу сообщества VK закрывает ошибкой 15 — нужен пользовательский токен, как у фото', state: 'частично'},
        {name: 'Опрос', id: 'poll-123_456', now: 'Готовым ID; единственным вложением быть не может', need: 'Создание опроса через polls.create — пока не подключено', state: 'частично'},
        {name: 'Товар', id: 'market-123_456', now: 'Готовым ID', need: 'Товар должен существовать в магазине сообщества', state: 'работает'},
        {name: 'Подборка товаров', id: 'market_album-123_456', now: 'Готовым ID', need: 'Подборка создаётся в сообществе', state: 'работает'},
        {name: 'Альбом', id: 'album-123_456', now: 'Готовым ID', need: 'Альбом сообщества', state: 'работает'},
        {name: 'Заметка, страница', id: 'note-123_456, page-123_456', now: 'Готовым ID', need: 'Заметка или вики-страница сообщества', state: 'работает'},
        {name: 'Ссылка', id: 'https://…', now: 'Одна на запись, в поле вложений', need: 'Страница с превью: прямой адрес файла VK отклоняет — link_photo_sizing_rule', state: 'работает'},
    ];
    function attachments() {
        const tone = {'работает': 'green', 'частично': '', 'впереди': ''};
        const rows = attachmentKinds.map(kind => `<tr><td><strong>${esc(kind.name)}</strong><small class="vkt-muted"><code>${esc(kind.id)}</code></small></td><td>${badge(kind.state, tone[kind.state])}</td><td>${esc(kind.now)}</td><td class="vkt-muted">${esc(kind.need)}</td></tr>`).join('');
        return heading('Что можно прикрепить', 'Чем VK разрешает дополнить запись, что из этого плагин умеет сегодня и чего не хватает остальному.') +
            `<div class="vkt-settings-grid"><section class="vkt-panel vkt-table-wrap">
                <table><thead><tr><th>Вложение</th><th>Состояние</th><th>Как добавить сейчас</th><th>Что для этого нужно</th></tr></thead><tbody>${rows}</tbody></table>
            </section>
            <aside class="vkt-panel vkt-settings-help"><span class="vkt-help-icon">${icon('plus')}</span><h2>Общие правила</h2><p>В одной записи не больше <strong>десяти</strong> вложений и не больше <strong>одной</strong> ссылки. Документ — один на запись. Опрос не может быть единственным вложением.</p><hr><h3>Готовый ID</h3><p>Если вложение уже существует в VK, его ID берётся из адреса записи и вставляется в поле «Вложения ID или ссылка» в конструкторе. Вид — <code>тип</code><code>владелец</code>_<code>номер</code>, у сообщества владелец со знаком минус: <code>photo-241464933_457239017</code>.</p><hr><h3>Что даёт загрузка файлом</h3><p>Плагин хранит ID вложения WordPress, а ID для VK получает сам при отправке — отдельно для каждого сообщества, потому что фотография привязывается к конкретной группе. Полученный ID запоминается в задании, поэтому повтор после сетевой ошибки не грузит тот же файл дважды.</p></aside></div>`;
    }

    // ——— Модели для текстов: несколько поставщиков, одна по умолчанию ———
    function textModels() {
        const ai = state.settings.ai || {};
        const models = ai.models || [];
        const presets = ai.presets || {};
        const rows = models.map(model => `<div><span><strong>${esc(model.title)}</strong><small><code>${esc(model.model)}</code> · ${esc(model.host)} · ключ ${esc(model.preview)}</small></span><span>${model.id === ai.default_model ? badge('по умолчанию', 'green') : button('По умолчанию', 'ai-model-default', `data-id="${esc(model.id)}"`)}${button('Проверить', 'ai-model-check', `data-id="${esc(model.id)}"`)}${model.builtin ? '' : button('Убрать', 'ai-model-delete', `data-id="${esc(model.id)}"`)}</span></div>`).join('');
        const first = presets.deepseek ? 'deepseek' : Object.keys(presets)[0];
        return `<h2>Модели для текстов</h2><p class="vkt-muted">Пишут посты, серии и ответы на комментарии. Подходит любой поставщик с OpenAI-совместимым API. При добавлении плагин делает пробный запрос — ключ, адрес и доступность из страны сервера проверяются сразу. Картинки и видео по-прежнему генерирует xAI.</p>
            ${models.length ? `<div class="vkt-own-groups">${rows}</div>` : '<div class="vkt-info vkt-info-warning">Ни одной модели: генерация текстов выключена.</div>'}
            <form data-form="ai-model" class="vkt-form">
                <div class="vkt-form-row"><label>Поставщик<select name="preset" data-ai-preset>${Object.entries(presets).map(([id, preset]) => `<option value="${esc(id)}" ${id === first ? 'selected' : ''}>${esc(preset.title)}</option>`).join('')}</select></label><label>Модель<input name="model" class="vkt-code-input" value="${esc(presets[first]?.model || '')}" placeholder="deepseek-chat"></label></div>
                <label>Адрес API<input name="base" class="vkt-code-input" value="${esc(presets[first]?.base || '')}" placeholder="https://…/v1"><small class="vkt-help">Запрос уходит на адрес + /chat/completions.</small></label>
                <div class="vkt-form-row"><label>Ключ API<input name="key" class="vkt-code-input" autocomplete="off" required placeholder="sk-…"></label><label>Название в списке<input name="title" placeholder="Необязательно"></label></div>
                <label class="vkt-check"><input type="checkbox" name="default">Сделать моделью по умолчанию</label>
                <div class="vkt-form-actions"><button type="submit" class="vkt-button vkt-primary">${icon('plus')} Проверить и добавить</button></div>
            </form>`;
    }
    function settings() {
        const s = state.settings;
        return heading('Настройки', 'Общие параметры рабочего пространства. Ключи VK живут в разделе «Подключения».') +
            `<div class="vkt-settings-grid"><section class="vkt-panel">
                <h2>Рабочее пространство</h2>
                <form data-form="settings" class="vkt-form">
                    <label class="vkt-check"><input type="checkbox" name="homepage" ${s.homepage?'checked':''}>Показывать дашборд на главной странице сайта</label>
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">Сохранить</button></div>
                </form>
                <hr class="vkt-settings-sep">
                ${textModels()}
                <hr class="vkt-settings-sep">
                <h2>Куда что переехало</h2>
                <ul class="vkt-steps">
                    <li>${badge('чтение', 'green')}<div><strong>Сервисный ключ, расписание обхода, сервис рендеринга</strong><span class="vkt-muted">Раздел «Чтение постов».</span></div></li>
                    <li>${badge('публикация', 'green')}<div><strong>Пользовательский токен, защищённый ключ, ключ сообщества, обмен кода, Callback API</strong><span class="vkt-muted">Раздел «Публикация».</span></div></li>
                    <li>${badge('справка', '')}<div><strong>Фото, видео, музыка, документы, опросы, товары</strong><span class="vkt-muted">Раздел «Что можно прикрепить».</span></div></li>
                </ul>
            </section>
            <aside class="vkt-panel vkt-settings-help"><span class="vkt-help-icon">${icon('lock')}</span><h2>Кто что видит</h2><p>Пользователи входят через VK ID и после вашего одобрения получают личный кабинет: свои источники, подборки, группы и очередь публикаций. Сбор, журнал и ключи сайта видите только вы. Секреты не возвращаются в браузер: в интерфейсе виден только огрызок ключа и его длина.</p><hr><h3>Версия</h3><p>Плагин обновляет схему базы сам при первом запросе после замены файлов. Накопленные данные сохраняются.</p></aside></div>`;
    }

    // ——— Стенд генерации фото: FLUX через api.bfl.ai ———
    const fluxStatuses = {pending: ['Выполняется', ''], done: ['Готово', 'green'], error: ['Отказ', 'red']};
    const fluxRunRow = run => `<tr><td>${date(run.at)}<small class="vkt-muted">${esc(run.model || '')}</small></td><td>${badge(...(fluxStatuses[run.status] || [esc(run.status), '']))}</td><td>${run.media ? `<span class="vkt-media-mini">${safeUrl(run.media.thumbnail || run.media.url) ? `<img src="${safeUrl(run.media.thumbnail || run.media.url)}" alt="" loading="lazy">` : ''}</span>` : ''}${esc(run.message || '')}${run.cost ? `<small class="vkt-muted">Списано кредитов: ${decimal(run.cost, 3)}</small>` : ''}</td></tr>`;
    function flux() {
        const s = state.settings;
        const cfg = s.flux || {};
        const models = cfg.models || {};
        // Варианты одного запроса показываем вместе: это последняя пачка прогонов.
        const last = fluxRuns.length ? fluxRuns.filter(run => run.batch === fluxRuns[0].batch && run.media) : [];
        // Ключ сервиса общий для сайта: участнику он ни к чему, как и баланс кредитов.
        return heading('Генерация фото', 'Стенд FLUX через api.bfl.ai: правка своей фотографии по образцу товара и генерация с нуля. Ответ сервиса показывается дословно.', isAdmin() ? button(`${icon('refresh')} Баланс кредитов`, 'flux-credits') : '') +
            `<div class="vkt-settings-grid"><section class="vkt-panel">
                ${isAdmin() ? `<h2>Ключ BFL</h2>
                <div class="vkt-token-cards">${slotCard(s.tokens, 'bfl')}</div>
                <hr class="vkt-settings-sep">` : ''}
                <h2>Запрос</h2>
                ${cfg.configured ? '' : `<div class="vkt-info vkt-info-warning">${isAdmin() ? 'Сохраните ключ BFL выше — без него стенд ничего не отправит.' : 'Генерация фото пока не подключена: ключ сервиса сохраняет администратор.'}</div>`}
                <form data-form="flux" class="vkt-form">
                    <label>Модель<select name="model">${Object.entries(models).map(([id, model]) => `<option value="${esc(id)}">${esc(model.title)}</option>`).join('')}</select><small class="vkt-help">${Object.values(models).map(model => `<strong>${esc(model.title)}</strong> — ${esc(model.hint)}`).join('<br>')}</small></label>
                    <label>Что сделать<textarea name="prompt" rows="6" required minlength="3" maxlength="5000" placeholder="Например: replace the swimsuit with the design from image 2, keep the person, pose, lighting and background unchanged, catalog product photo"></textarea></label>
                    <fieldset class="vkt-media"><legend>Исходное фото и образцы · до ${Number(cfg.max_references) || 4}</legend>
                        <div id="vkt-flux-media" class="vkt-media-chips">${fluxMediaHtml()}</div>
                        <div class="vkt-media-actions"><label class="vkt-button vkt-file"><input type="file" accept="image/*" data-flux-upload hidden>${icon('plus')} Загрузить файл</label>${button(`${icon('layers')} Из медиатеки`, 'flux-media')}</div>
                        <small class="vkt-help">Первое изображение — исходное фото, остальные идут образцами (<code>input_image_2</code> и далее). Порядок важен: на него ссылаются словами «image 1», «image 2». Без изображений запрос рисует картинку с нуля.</small>
                    </fieldset>
                    <div class="vkt-form-row">
                        <label>Строгость фильтра<select name="safety_tolerance">${[0,1,2,3,4,5].map(value => `<option value="${value}" ${2 === value ? 'selected' : ''}>${value}${0 === value ? ' — строже всего' : value === (Number(cfg.max_tolerance) || 5) ? ' — мягче всего' : ''}</option>`).join('')}</select><small class="vkt-help">Выше 5 сервис не принимает.</small></label>
                        <label>Seed · необязательно<input type="number" name="seed" min="0" max="4294967295" placeholder="Повторяемость"></label>
                    </div>
                    <div class="vkt-form-row">
                        <label>Ширина<input type="number" name="width" min="0" max="2048" step="32" placeholder="как у исходного"></label>
                        <label>Высота<input type="number" name="height" min="0" max="2048" step="32" placeholder="как у исходного"></label>
                        <label>Формат<select name="output_format"><option value="jpeg">JPEG</option><option value="png">PNG</option><option value="webp">WebP</option></select></label>
                        <label>Сколько вариантов<select name="count">${variantOptions()}</select></label>
                    </div>
                    <small class="vkt-help">Каждый вариант — отдельная задача сервиса. Со своим seed варианты получают seed, seed + 1 и так далее, иначе они вышли бы одинаковыми.</small>
                    ${quotaNote(s.ai)}
                    <label class="vkt-check"><input type="checkbox" name="disable_pup">Не расширять промпт автоматически</label>
                    <div id="vkt-flux-progress" class="vkt-help" hidden></div>
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary" ${cfg.configured ? '' : 'disabled'}>${icon('fire')} Отправить</button></div>
                </form>
                ${last.length ? `<hr class="vkt-settings-sep"><h2>Последний результат</h2><figure class="vkt-flux-result"><div class="vkt-flux-gallery ${last.length > 1 ? 'is-many' : ''}">${last.map(run => `<a href="${safeUrl(run.media.url)}" target="_blank" rel="noopener noreferrer"><img src="${safeUrl(run.media.url)}" alt="" loading="lazy"></a>`).join('')}</div><figcaption>${last.length > 1 ? `Файлы сохранены в медиатеку сайта: ${last.length} шт.` : `Файл сохранён в медиатеку сайта: <strong>${esc(last[0].media.name)}</strong>.`} Приложить к записи можно в «Автопостинге» — кнопка «Из медиатеки».</figcaption></figure>` : ''}
                ${fluxRuns.length ? `<hr class="vkt-settings-sep"><h2>Прогоны в этой вкладке</h2><div class="vkt-table-wrap"><table><thead><tr><th>Когда</th><th>Итог</th><th>Ответ сервиса</th></tr></thead><tbody>${fluxRuns.map(fluxRunRow).join('')}</tbody></table></div>` : ''}
            </section>
            <aside class="vkt-panel vkt-settings-help"><span class="vkt-help-icon">${icon('fire')}</span><h2>Что уже проверено живьём</h2><p>20.09.2026, FLUX.2 [pro], ключ из кабинета BFL:</p>
                <ul class="vkt-steps">
                    <li>${badge('проходит', 'green')}<div><strong>Раздельный купальник текстом</strong><span class="vkt-muted">Генерация с нуля, без исходного фото — даже на строгости по умолчанию.</span></div></li>
                    <li>${badge('проходит', 'green')}<div><strong>Правка одежды на фото</strong><span class="vkt-muted">Смена цвета слитного купальника отдаёт результат.</span></div></li>
                    <li>${badge('отказ', 'red')}<div><strong>Замена слитного на раздельный по фото</strong><span class="vkt-muted">Статус <code>Content Moderated</code>, причина Sexual Content. Строгость 5 и отключённое расширение промпта не помогают.</span></div></li>
                </ul>
                <p class="vkt-help">То есть запрос не «банится» на входе: картинка рисуется, но фильтр на выходе не отдаёт её именно на пути «правка фотографии человека → открытый купальник».</p>
                <hr><h3>Как читать отказ</h3><p><code>Request Moderated</code> — не пропустили промпт или исходное фото, генерации не было. <code>Content Moderated</code> — картинка готова, но фильтр не отдал её. Причины сервис называет сам.</p>
                <hr><h3>Ссылка живёт 10 минут</h3><p>Готовый файл плагин скачивает сразу и кладёт в медиатеку — адрес BFL в браузер не попадает.</p>
            </aside></div>`;
    }

    // ——— Пользователи: заявки и лимиты кабинетов ———
    const userStatuses = {pending: ['Ждёт одобрения', ''], active: ['Активен', 'green'], blocked: ['Заблокирован', 'red']};
    function users() {
        const s = state.settings;
        const list = usersData || [];
        const pending = list.filter(user => user.status === 'pending').length;
        const actions = user => user.status === 'active'
            ? button('Заблокировать', 'user-status', `data-id="${Number(user.id)}" data-status="blocked"`)
            : button(user.status === 'blocked' ? 'Разблокировать' : 'Одобрить', 'user-status', `data-id="${Number(user.id)}" data-status="active"`) + (user.status === 'pending' ? button('Отклонить', 'user-status', `data-id="${Number(user.id)}" data-status="blocked"`) : '');
        const limitCell = user => {
            const limits = user.limits || {};
            const part = (key, label, used) => limits[key] ? `<span class="${limits[key].own === null ? '' : 'vkt-limit-own'}">${label} ${used ? `${num(limits[key].used || 0)}/` : ''}${num(limits[key].limit)}</span>` : '';
            return `<div class="vkt-limit-cell">${part('text', 'тексты', true)}${part('media', 'картинки', true)}${part('replies_queue', 'ответов в очереди', false)}</div>`;
        };
        const rows = list.map(user => `<tr><td><div class="vkt-user-cell">${safeUrl(user.avatar || '') ? `<img src="${safeUrl(user.avatar)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="vkt-post-avatar">${esc(String(user.name || '?').slice(0, 1))}</span>`}<div><strong>${esc(user.name)}</strong><small>${Number(user.vk_id) ? `<a href="https://vk.com/id${Number(user.vk_id)}" target="_blank" rel="noopener noreferrer">vk.com/id${Number(user.vk_id)} ↗</a>` : 'без VK'}</small></div></div></td><td>${badge(...(userStatuses[user.status] || [esc(user.status), '']))}</td><td>${date(user.registered)}${user.last_login ? `<small class="vkt-muted">вход ${ago(user.last_login)}</small>` : ''}</td><td>${num(user.sources)}</td><td>${num(user.groups)}</td><td>${num(user.posts)}</td><td>${limitCell(user)}</td><td class="vkt-table-actions">${button('Лимиты', 'user-limits', `data-id="${Number(user.id)}"`)}${actions(user)}</td></tr>`).join('');
        return heading('Пользователи', 'Кабинеты тех, кто вошёл через VK ID. Каждый видит только свои источники, подборки и публикации.', button(`${icon('refresh')} Обновить`, 'reload')) +
            `<div class="vkt-stats">${stat('Всего', num(list.length), 'Вошли через VK ID', 'people')}${stat('Ждут одобрения', num(pending), 'Кабинет откроется после одобрения', 'clock', 'orange')}${stat('Активных', num(list.filter(user => user.status === 'active').length), 'Работают с кабинетом', 'check', 'green')}${stat('Источников у вас', num(s.limits?.sources_used || 0), 'Ваш личный список', 'layers', 'purple')}</div>` +
            (list.length ? `<section class="vkt-panel vkt-table-wrap"><table><thead><tr><th>Пользователь</th><th>Статус</th><th>Заявка</th><th>Источники</th><th>Группы</th><th>Записи</th><th>Лимиты · сегодня</th><th></th></tr></thead><tbody>${rows}</tbody></table></section>` : empty('Пока никого', s.vkid?.configured ? 'Пользователи появятся здесь после первого входа через VK ID на главной.' : 'Укажите ID приложения VK ID в разделе «Публикация» → «Запасные способы» — тогда на главной появится кнопка входа.')) +
            `<div class="vkt-settings-grid"><section class="vkt-panel"><h2>Общие лимиты кабинета</h2><p class="vkt-muted">Действуют на всех пользователей, кроме администратора и тех, кому в таблице выше кнопкой «Лимиты» задано своё значение. Генерация xAI считается по суткам в часовом поясе сайта, списывается только удачная.</p>
                <form data-form="settings" class="vkt-form">
                    <div class="vkt-form-row"><label>Источников на кабинет<input type="number" name="member_sources" min="1" max="500" value="${Number(s.member_sources) || 100}"></label><label>Текстов в сутки<input type="number" name="ai_text_daily" min="0" max="1000" value="${Number(s.ai_text_daily ?? 30)}"></label><label>Картинок и видео в сутки<input type="number" name="ai_media_daily" min="0" max="200" value="${Number(s.ai_media_daily ?? 5)}"></label></div>
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">Сохранить лимиты</button></div>
                </form></section>
            <aside class="vkt-panel vkt-settings-help"><span class="vkt-help-icon">${icon('people')}</span><h2>Как устроены кабинеты</h2><p>Сообщество обходится один раз, даже если его добавили несколько человек, — а посты каждый видит только из своих источников.</p><hr><h3>Что общее</h3><p>Сервисный ключ, приложение VK и расписание сбора — ваши. Пользователь получает свой токен через ваше приложение и хранит свой ключ сообщества.</p><hr><h3>Блокировка</h3><p>Закрывает кабинет и выкидывает пользователя из всех открытых сессий. Его записи в очереди перестают публиковаться. Удаление учётной записи в WordPress уносит и данные кабинета.</p></aside></div>`;
    }

    // ——— Посты сообществ ———
    const postUrl = post => `https://vk.com/wall${Number(post.owner_id)}_${Number(post.post_id)}`;
    const mediaLabels = {photo: 'Фото', video: 'Видео', market: 'Товар', link: 'Ссылка', doc: 'Документ', audio: 'Аудио', poll: 'Опрос', album: 'Альбом'};
    const postSorts = {velocity: 'Прирост в час', g1: 'Прирост за сутки', g3: 'Прирост за 3 дня', g7: 'Прирост за 7 дней', g30: 'Прирост за 30 дней', views: 'Просмотры', err: 'ERR', viral: 'Вирусность', likes: 'Лайки', comments: 'Комментарии', published_at: 'Дата публикации', measured_at: 'Последний замер'};
    // Диапазоны фильтров: подпись, поле в базе и подсказка единиц.
    const postRanges = [['velocity', 'Прирост в час', 'от 1000'], ['day', 'Прирост за сутки', 'от 10 000'], ['views', 'Просмотры', 'от 50 000'], ['members', 'Подписчики', 'от 5000'], ['viral', 'Вирусность, ×', 'от 0.5'], ['err', 'ERR, %', 'от 0.1'], ['price', 'Цена, ₽', 'от 100']];
    const activeFilters = () => (postsSource ? 1 : 0) + Object.entries(postsFilters).filter(([, value]) => value !== '' && value !== null && value !== undefined && value !== false).length;

    const linkStatuses = {ok: ['Товар распознан', 'green'], pending: ['В очереди на чтение', ''], manual: ['Не читали — домен вне списка магазинов', ''], blocked: ['Магазин закрыл доступ роботу', 'red'], error: ['Сайт не ответил', 'red'], empty: ['Страница прочитана, товара в разметке нет', '']};
    function postProduct(post) {
        const legacy = {url: post.link_url || (post.market_id ? `https://vk.ru/market${post.market_id}` : ''), title: post.product_title || post.link_title || post.text_product || '', price: post.product_price ?? post.text_price, old_price: post.product_old_price, sku: post.product_sku, source: post.product_source || 'text', recognized: !!post.product_source, link_id: post.link_id, page: {title: post.page_title, price: post.page_price, sku: post.shop_sku, shop: post.link_shop, status: post.link_status, final_url: post.final_url}};
        const items = Array.isArray(post.product_items) && post.product_items.length ? post.product_items : (legacy.url ? [legacy] : []);
        if (!items.length) return '';
        const renderProduct = item => {
            const page = item.page || {};
            const fromPage = page.status === 'ok' && page.title;
            const title = fromPage ? page.title : item.title;
            const price = fromPage ? (page.price ?? item.price) : item.price;
            const url = safeUrl(page.final_url || item.url || '');
            const shop = page.shop || item.shop || (item.market_id ? 'VK Маркет' : '');
            const source = fromPage ? 'со страницы магазина' : item.recognized ? 'из вложения VK' : item.source === 'link' ? 'название ссылки' : 'из текста поста';
            const status = page.status ? linkStatuses[page.status] : null;
            return `<div class="vkt-post-product-item">${shop ? `<span class="vkt-post-shop">${icon('cart')} ${esc(shop)}</span>` : ''}
                <span class="vkt-post-product-title">${esc(title || 'Название товара не определено')}</span>
                ${title ? `<span class="vkt-post-text-note">${source}</span>` : ''}
                ${item.recognized && item.price_source === 'text' && !(fromPage && page.price != null) ? '<span class="vkt-post-text-note">цена из текста поста</span>' : ''}
                ${price !== null && price !== undefined ? `<span class="vkt-post-price"><strong>${money(price)}</strong>${item.old_price ? `<s>${money(item.old_price)}</s>` : ''}</span>` : ''}
                ${page.sku || item.sku ? `<span class="vkt-post-sku">Артикул ${esc(page.sku || item.sku)}</span>` : ''}
                ${url ? `<a class="vkt-product-destination" href="${url}" title="${url}" target="_blank" rel="noopener noreferrer">${esc(shop || 'Открыть ссылку')} ↗</a>` : ''}
                ${status && page.status !== 'ok' ? `<span class="vkt-link-status">${badge(status[0], status[1])}${item.link_id ? button('Прочитать', 'resolve-link', `data-id="${Number(post.id)}" data-link="${Number(item.link_id)}"`) : ''}</span>` : ''}</div>`;
        };
        return `<div class="vkt-post-product">${renderProduct(items[0])}${items.length > 1 ? `<details class="vkt-product-more"><summary>Ещё ссылок: ${items.length - 1}</summary>${items.slice(1).map(renderProduct).join('')}</details>` : ''}</div>`;
    }
    function postEngagement(post) {
        return `<div class="vkt-post-engagement"><span title="Лайки">${icon('heart')} ${short(post.likes)}</span><span title="Репосты">${icon('share')} ${short(post.reposts)}</span><span title="Комментарии">${icon('comment')} ${short(post.comments)}</span><span title="Вовлечение к охвату">ERR ${post.err === null ? '—' : decimal(post.err, 2) + '%'}</span>${post.viral !== null && post.viral !== undefined ? `<span class="vkt-viral" title="Просмотры к числу подписчиков">${icon('fire')} ${decimal(post.viral, 2)}×</span>` : ''}</div>`;
    }
    function postSource(post) {
        const avatar = safeUrl(post.photo || '');
        const title = post.source_title || post.source_value || `Сообщество ${post.owner_id}`;
        return `<div class="vkt-post-source">${avatar ? `<img src="${avatar}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="vkt-post-avatar">${esc(String(title).slice(0, 1))}</span>`}<div><strong>${esc(title)}</strong><small>${post.members ? short(post.members) + ' подп.' : esc(post.source_value || post.owner_id)}</small></div>${post.source_id ? `<button type="button" class="vkt-chip-button" data-command="community-posts" data-id="${Number(post.source_id)}">Посты</button>` : ''}</div>`;
    }
    function postCard(post) {
        const thumbnail = safeUrl(post.thumbnail || '');
        const media = String(post.media || '').split(',').filter(Boolean).map(type => mediaLabels[type] || type);
        return `<article class="vkt-post-card">${postSource(post)}<a class="vkt-post-thumb" href="${postUrl(post)}" target="_blank" rel="noopener noreferrer">${thumbnail ? `<img src="${thumbnail}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="vkt-thumbnail-placeholder">${icon('post')}</span>`}${post.is_ad ? '<span class="vkt-post-flag">Реклама</span>' : ''}${media.length ? `<span class="vkt-post-media">${esc(media.join(' · '))}</span>` : ''}</a>
            <div class="vkt-post-body"><div class="vkt-post-date">${date(post.published_at)}${post.published_at ? ` <span>·</span> ${ago(post.published_at)}` : ''}${post.is_repost ? ' <span>·</span> репост' : ''}</div>
            <p class="vkt-post-text ${post.text_source === 'attachment' ? 'is-fallback' : ''}">${post.text_source === 'attachment' ? '<span class="vkt-post-text-note">название вложения</span> ' : ''}${esc(post.text ? String(post.text).slice(0, 220) : 'Пост без текста')}${post.text && post.text.length > 220 ? '…' : ''}</p>
            ${postProduct(post)}
            <div class="vkt-post-views"><small>ПРОСМОТРЫ</small><strong>${icon('eye')} ${short(post.views)}</strong><div class="vkt-post-growth">${post.velocity !== null && post.velocity !== undefined ? `<span class="vkt-chip green">${icon('arrow')} ${signed(post.velocity)} / ч</span>` : `<span class="vkt-chip">${icon('clock')} нужен второй замер</span>`}${post.g1 !== null && post.g1 !== undefined ? `<span class="vkt-chip orange">${signed(post.g1)} за 24 часа</span>` : ''}${post.g7 !== null && post.g7 !== undefined ? `<span class="vkt-chip">${signed(post.g7)} за 7 дней</span>` : ''}</div></div>
            ${postEngagement(post)}
            <div class="vkt-card-bottom"><a class="vkt-button" href="${postUrl(post)}" target="_blank" rel="noopener noreferrer">Пост VK ↗</a>${button(`${icon('chart')} График`, 'post-history', `data-id="${Number(post.id)}"`)}${button('···', 'post-detail', `data-id="${Number(post.id)}" aria-label="Подробнее о посте"`)}</div></div></article>`;
    }
    function postRow(post) {
        const thumbnail = safeUrl(post.thumbnail || '');
        return `<tr><td class="vkt-post-cell">${thumbnail ? `<img src="${thumbnail}" alt="" loading="lazy" referrerpolicy="no-referrer">` : ''}<div><strong>${esc(post.source_title || post.source_value || post.owner_id)}</strong><p>${esc(post.text ? String(post.text).slice(0, 160) : 'Пост без текста')}</p><small>${date(post.published_at)} · ${ago(post.published_at)} · <a href="${postUrl(post)}" target="_blank" rel="noopener noreferrer">Пост VK ↗</a></small></div></td>
            <td>${postProduct(post) || '<span class="vkt-muted">—</span>'}</td>
            <td><strong>${short(post.views)}</strong><small class="vkt-muted">всего просмотров</small><div class="vkt-post-growth">${post.velocity !== null && post.velocity !== undefined ? `<span class="vkt-chip green">${signed(post.velocity)} / ч</span>` : ''}${post.g1 !== null && post.g1 !== undefined ? `<span class="vkt-chip orange">${signed(post.g1)} / 24 ч</span>` : ''}</div></td>
            <td>${postEngagement(post)}</td>
            <td class="vkt-table-actions">${button('График', 'post-history', `data-id="${Number(post.id)}"`)}${button('···', 'post-detail', `data-id="${Number(post.id)}"`)}</td></tr>`;
    }
    function postFilterPanel() {
        if (!postsFiltersOpen) return '';
        return `<form data-form="post-filters" class="vkt-filter-panel">${postRanges.map(([key, label, hint]) => `<div class="vkt-filter-row"><span>${label}</span><input name="${key}_min" type="number" step="any" inputmode="decimal" placeholder="${hint}" value="${esc(postsFilters[key + '_min'] ?? '')}"><input name="${key}_max" type="number" step="any" inputmode="decimal" placeholder="до" value="${esc(postsFilters[key + '_max'] ?? '')}"></div>`).join('')}
            <div class="vkt-filter-row"><span>Магазин</span><select name="shop" class="vkt-filter-shop"><option value="">Любой</option>${(postsData?.shops || []).map(item => `<option value="${esc(item.shop)}" ${postsFilters.shop === item.shop ? 'selected' : ''}>${esc(item.shop)} · ${num(item.posts)}</option>`).join('')}</select></div>
            <div class="vkt-filter-checks"><label class="vkt-check"><input type="checkbox" name="with_product" ${postsFilters.with_product ? 'checked' : ''}>Только с товаром или ссылкой</label><label class="vkt-check"><input type="checkbox" name="with_price" ${postsFilters.with_price ? 'checked' : ''}>Только с ценой</label><label class="vkt-check"><input type="checkbox" name="recognized" ${postsFilters.recognized ? 'checked' : ''}>Только распознанные товары</label><label class="vkt-check"><input type="checkbox" name="no_ads" ${postsFilters.no_ads ? 'checked' : ''}>Скрыть рекламные</label></div>
            <div class="vkt-filter-actions">${button('Очистить всё', 'posts-filters-clear')}<button class="vkt-button vkt-primary">Применить</button></div></form>`;
    }
    function posts() {
        if (!postsData) return heading('Посты', 'Загружаем витрину…') + '<div class="vkt-loading">Читаем сохранённые посты…</div>';
        const list = postsData.posts || [];
        const summary = postsData.summary || {};
        const communities = postsData.communities || state.sources.filter(item => ['owner', 'domain'].includes(item.kind));
        const source = postsSource ? (communities.find(x => Number(x.id) === Number(postsSource)) || null) : null;
        const periods = [['', 'Всё время'], ['1', 'За 24 часа'], ['7', 'За 7 дней'], ['30', 'За 30 дней']];
        return heading(source ? `Посты · ${esc(source.title || source.value)}` : 'Посты', 'Контент сообществ со счётчиками VK и динамикой просмотров между замерами.', (postsSource ? button('Все сообщества', 'posts-all') : '') + button(`${icon('refresh')} Обновить`, 'posts-reload')) +
            `<div class="vkt-stats">${stat('Найдено постов', num(postsData.total), postsSource ? 'В выбранном сообществе' : 'С учётом фильтров', 'post')}${stat('Суммарные просмотры', short(summary.views), 'По последним замерам', 'eye', 'purple')}${stat('Прирост за сутки', signed(summary.day_growth), 'Сумма по отфильтрованным', 'arrow', 'green')}${stat('Средний ERR', summary.err === null || summary.err === undefined ? '—' : decimal(summary.err, 2) + '%', 'Вовлечение к охвату', 'heart', 'orange')}</div>` +
            `<section class="vkt-panel vkt-posts-toolbar"><form data-form="posts-search" class="vkt-search-form"><label class="vkt-search-input">${icon('search')}<input name="search" maxlength="200" placeholder="Поиск по тексту поста, товару или сообществу" value="${esc(postsSearch)}" aria-label="Поиск по постам"></label><select name="source" aria-label="Сообщество"><option value="0">Все сообщества</option>${communities.map(item => `<option value="${Number(item.id)}" ${Number(postsSource) === Number(item.id) ? 'selected' : ''}>${esc(item.title || item.value)}${item.title ? ` · ${esc(item.value)}` : ''}</option>`).join('')}</select><select name="sort" aria-label="Сортировка">${Object.entries(postSorts).map(([key, label]) => `<option value="${key}" ${postsSort === key ? 'selected' : ''}>${label}</option>`).join('')}</select><button class="vkt-button vkt-primary">Показать</button></form>
            <div class="vkt-toolbar-row"><div class="vkt-chips">${periods.map(([value, label]) => `<button type="button" class="vkt-chip-button ${String(postsFilters.period ?? '') === value ? 'is-active' : ''}" data-command="posts-period" data-value="${value}">${label}</button>`).join('')}</div>
            <div class="vkt-toolbar-right">${button(`${icon('filter')} Фильтры${activeFilters() ? ` <span class="vkt-nav-count">${activeFilters()}</span>` : ''}`, 'posts-filters')}<div class="vkt-chips">${[['grid', 'Витрина', 'grid'], ['table', 'Таблица', 'table']].map(([value, label, name]) => `<button type="button" class="vkt-chip-button ${postsMode === value ? 'is-active' : ''}" data-command="posts-mode" data-value="${value}">${icon(name)} ${label}</button>`).join('')}</div></div></div>${postFilterPanel()}<div class="vkt-recognized-summary">Постов с распознанным товаром: <strong>${num(summary.recognized_posts || 0)}</strong><small>Название из вложения VK или со страницы магазина. С учётом фильтров.</small>${Number(postsData.reextract_pending) ? `<small>Обновляем товары в сохранённых постах: осталось ${num(postsData.reextract_pending)}.</small>` : ''}</div>${postsData.links && Number(postsData.links.total) ? `<div class="vkt-link-summary">${icon('cart')} Страницы магазинов в ваших постах: <strong>${num(postsData.links.total)}</strong> · прочитано с товаром <strong>${num(postsData.links.recognized || 0)}</strong> · в очереди <strong>${num(postsData.links.waiting || 0)}</strong> · не удалось <strong>${num(postsData.links.failed || 0)}</strong><small>Магазины из списка читаются автоматически, остальные — по кнопке «Прочитать» в карточке.</small></div>` : ''}</section>` +
            (list.length ? (postsMode === 'grid'
                ? `<div class="vkt-post-grid">${list.map(postCard).join('')}</div>`
                : `<section class="vkt-panel vkt-table-wrap"><table class="vkt-posts-table"><thead><tr><th>Пост</th><th>Товары и цены</th><th>Охваты и динамика</th><th>Вовлечение</th><th></th></tr></thead><tbody>${list.map(postRow).join('')}</tbody></table></section>`)
                + `<div class="vkt-pagination">${button('← Назад', 'posts-prev', postsPage <= 1 ? 'disabled' : '')}<span>Страница ${postsPage} из ${Math.max(1, postsData.pages)}</span>${button('Далее →', 'posts-next', postsPage >= postsData.pages ? 'disabled' : '')}</div>`
                : empty(state.sources.length ? 'Постов пока нет' : 'Сначала добавьте сообщества', state.sources.length ? 'Запустите сбор — посты появятся после первого обхода стены. Если фильтры узкие, ослабьте их.' : 'Посты собираются со стен источников. Добавьте сообщества и запустите сбор.', state.sources.length ? (isAdmin() ? 'collector' : 'sources') : 'sources', state.sources.length ? (isAdmin() ? 'Перейти к сбору' : 'Мои источники') : 'Добавить источники'));
    }
    // ——— Мои сообщества: свои группы, их динамика, охваты и паспорт ———
    const groupLink = group => group.screen_name ? `https://vk.com/${encodeURIComponent(group.screen_name)}` : `https://vk.com/club${Number(group.group_id)}`;
    const groupAvatar = group => {
        const photo = safeUrl(group.photo || group.metrics?.source_photo || '');
        return photo ? `<img src="${photo}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="vkt-post-avatar">${esc(String(group.name || group.group_id).slice(0, 1))}</span>`;
    };
    const bestTimes = list => (list || []).map(item => item.key).join(', ');
    function groupCard(group) {
        const m = group.metrics || {};
        const week = group.stats && !group.stats.error ? group.stats.week : null;
        const collecting = m.measured_at ? `собрано ${ago(m.measured_at)}` : 'ждёт первого сбора';
        return `<article class="vkt-panel vkt-group-card">
            <div class="vkt-group-head">${groupAvatar(group)}<div><strong>${esc(group.name || `club${group.group_id}`)}</strong><small><a href="${esc(groupLink(group))}" target="_blank" rel="noopener noreferrer">${esc(group.screen_name || `club${group.group_id}`)} ↗</a> · ${esc(collecting)}</small></div></div>
            <div class="vkt-group-badges">${group.has_passport ? badge('паспорт заполнен', 'green') : badge('нет паспорта')}${Number(group.materials_count) ? badge(`база ведения: ${num(group.materials_count)}`, 'green') : ''}${group.is_news ? badge('новостная', 'green') : ''}${Number(group.can_post) ? '' : badge('нет права публикации', 'red')}</div>
            <div class="vkt-group-numbers">
                <span>Подписчики<strong>${short(m.members)}</strong></span>
                <span>Постов за 30 дн.<strong>${num(m.posts30 || 0)}</strong></span>
                <span>Ср. просмотры<strong>${short(m.avg_views30 === null ? null : Math.round(m.avg_views30))}</strong></span>
                <span>Прирост 7 дн.<strong class="vkt-growth">${signed(m.g7)}</strong></span>
                <span>ERR<strong>${m.err === null || m.err === undefined ? '—' : decimal(m.err, 2) + '%'}</strong></span>
                <span>Охват 7 дн.<strong>${week && week.reach !== null ? short(week.reach) : '—'}</strong></span>
            </div>
            <small class="vkt-muted">${m.last_post ? `Последний пост ${ago(m.last_post)}` : 'Постов ещё нет в базе'}${group.best_times?.length ? ` · лучше заходят в ${esc(bestTimes(group.best_times))}` : ''}</small>
            <div class="vkt-table-actions">${button('Открыть', 'group-open', `data-id="${Number(group.id)}"`, true)}${button('Скрыть', 'group-hide', `data-id="${Number(group.id)}" data-hidden="1"`)}</div>
        </article>`;
    }
    function groups() {
        if (!groupsData) return heading('Мои сообщества', 'Загружаем свои группы…') + '<div class="vkt-loading">Загружаем…</div>';
        if (groupOpen) return groupPage();
        const list = groupsData.groups || [];
        const visible = list.filter(group => !Number(group.hidden));
        const hidden = list.filter(group => Number(group.hidden));
        const sum = key => visible.reduce((total, group) => total + (Number(group.metrics?.[key]) || 0), 0);
        const subtitle = 'Только свои группы: последние посты, их движение, охваты и паспорт — кто ведёт, формат, тон и призывы. Паспорт и база ведения учитываются, когда нейросеть пишет посты, серии и ответы.';
        if (!list.length) return heading('Мои сообщества', subtitle) + empty('Своих групп пока нет', 'Добавьте группу ключом сообщества или обновите список в «Автопостинге» — она появится здесь и начнёт собираться.', 'publishing', 'Открыть автопостинг');
        return heading('Мои сообщества', subtitle, button(`${icon('refresh')} Обновить`, 'groups-reload')) +
            (groupsData.warning ? `<div class="vkt-info vkt-info-warning">${esc(groupsData.warning)}</div>` : '') +
            `<div class="vkt-stats">${stat('Сообществ', num(visible.length), hidden.length ? `ещё скрыто: ${num(hidden.length)}` : 'Все на виду', 'people')}${stat('Подписчиков', short(sum('members')), 'Сумма по группам', 'heart', 'purple')}${stat('Постов за 30 дней', num(sum('posts30')), 'Без рекламных', 'post', 'green')}${stat('Прирост за 7 дней', signed(sum('g7')), 'Просмотры постов', 'arrow', 'orange')}</div>` +
            (visible.length ? `<div class="vkt-group-grid">${visible.map(groupCard).join('')}</div>` : '<div class="vkt-info">Все группы скрыты — верните нужные из списка ниже.</div>') +
            (hidden.length ? `<details class="vkt-panel vkt-group-hidden"${groupShowHidden ? ' open' : ''}><summary data-command="groups-hidden-toggle">Скрытые · ${num(hidden.length)}</summary><p class="vkt-muted">Скрытые группы не собираются, история сохраняется. В автопостинге и сериях они доступны как раньше.</p>${hidden.map(group => `<div class="vkt-group-hidden-row">${groupAvatar(group)}<strong>${esc(group.name || `club${group.group_id}`)}</strong>${button('Вернуть в список', 'group-hide', `data-id="${Number(group.id)}" data-hidden="0"`)}</div>`).join('')}</details>` : '');
    }
    function groupStatsPanel(group) {
        const stats = group.stats;
        const refresh = button(`${icon('refresh')} ${stats ? 'Обновить охваты' : 'Получить охваты'}`, 'group-stats', `data-id="${Number(group.id)}"`);
        if (!stats) return `<p class="vkt-muted">Охват и посетителей VK отдаёт методом stats.get администраторам группы. Плагин попробует ключ сообщества, затем пользовательский токен.</p>${refresh}`;
        if (stats.error) return `<div class="vkt-info vkt-info-warning">${esc(stats.error)}</div><p class="vkt-muted">Запрошено ${date(stats.at)}. Просмотры постов ниже собираются и без этого.</p>${refresh}`;
        const days = stats.days || [];
        const peak = Math.max(1, ...days.map(day => Number(day.reach) || 0));
        const week = stats.week || {};
        const bars = days.length ? `<div class="vkt-group-bars">${days.map(day => `<span title="${esc(day.day)}: охват ${num(day.reach)}, посетители ${num(day.visitors)}"><i style="height:${Math.max(2, Math.round(100 * (Number(day.reach) || 0) / peak))}%"></i><small>${esc(day.day.slice(8))}</small></span>`).join('')}</div>` : '<p class="vkt-muted">VK вернул пустую статистику: у небольших групп она появляется не сразу.</p>';
        return `<div class="vkt-group-numbers">
                <span>Охват 7 дн.<strong>${short(week.reach)}</strong></span>
                <span>Из них подписчики<strong>${short(week.reach_subscribers)}</strong></span>
                <span>Посетители<strong>${short(week.visitors)}</strong></span>
                <span>Подписались<strong class="vkt-growth">${week.subscribed === null || week.subscribed === undefined ? '—' : '+' + num(week.subscribed)}</strong></span>
                <span>Отписались<strong>${week.unsubscribed === null || week.unsubscribed === undefined ? '—' : '−' + num(week.unsubscribed)}</strong></span>
            </div>${bars}<p class="vkt-muted">Охват по дням за две недели · ${stats.via === 'community' ? 'ключом сообщества' : 'пользовательским токеном'} · ${date(stats.at)}</p>${refresh}`;
    }
    function groupDigestPanel(digest) {
        if (!digest || !digest.posts) return '<div class="vkt-info">Сборщик ещё не собрал посты группы. Первый обход обычно через несколько минут после появления группы здесь.</div>';
        const chips = list => list.length ? `<div class="vkt-chips">${list.join('')}</div>` : '<span class="vkt-muted">мало данных</span>';
        const postLine = post => `<li><span>${esc(post.text || 'Запись без текста')}</span><small>${num(post.views)} просм.${post.err !== null ? ` · ERR ${decimal(post.err, 2)}%` : ''} · ${date(post.published_at)}</small></li>`;
        return `<div class="vkt-group-numbers">
                <span>Постов за ${num(digest.days)} дн.<strong>${num(digest.posts)}</strong></span>
                <span>В неделю<strong>${digest.per_week === null ? '—' : decimal(digest.per_week, 1)}</strong></span>
                <span>Ср. просмотры<strong>${short(digest.avg_views)}</strong></span>
                <span>Медиана<strong>${short(digest.median_views)}</strong></span>
                <span>ERR<strong>${digest.avg_err === null ? '—' : decimal(digest.avg_err, 2) + '%'}</strong></span>
                <span>Длина текста<strong>${num(digest.avg_length)}</strong></span>
                <span>С фото/видео<strong>${num(digest.media_share)}%</strong></span>
                <span>Со ссылкой<strong>${num(digest.link_share)}%</strong></span>
            </div>
            <div class="vkt-group-facts">
                <div><h3>Лучшие часы</h3>${chips(digest.best_hours.map(item => `<span class="vkt-chip-button">${esc(item.key)} · ${short(item.avg)}</span>`))}</div>
                <div><h3>Лучшие дни</h3>${chips(digest.best_days.map(item => `<span class="vkt-chip-button">${esc(item.label)} · ${short(item.avg)}</span>`))}</div>
                <div><h3>Хештеги</h3>${chips(digest.hashtags.map(tag => `<span class="vkt-chip-button">#${esc(tag)}</span>`))}</div>
            </div>
            ${digest.top.length ? `<h3>Лучше всего зашли</h3><ul class="vkt-group-posts">${digest.top.map(postLine).join('')}</ul>` : ''}
            ${digest.weak.length ? `<h3>Слабее всего</h3><ul class="vkt-group-posts">${digest.weak.map(postLine).join('')}</ul>` : ''}
            <p class="vkt-muted">Средние и лучшее время — по постам старше двух суток: свежие ещё набирают просмотры. Часы — по времени сайта.</p>`;
    }
    // В запрос уходит начало материала — тот же предел, что VKT_Materials::PROMPT_EACH.
    const MATERIAL_PROMPT_LIMIT = 6000;
    function groupMaterialsPanel(group) {
        const list = group.materials || [];
        const attached = list.map(item => item.file).filter(Boolean);
        const free = (group.library || []).filter(item => !attached.includes(item.file));
        const use = (item, key, label) => `<label class="vkt-check"><input type="checkbox" data-material-use="${key}" data-id="${esc(item.id)}" ${item[key] ? 'checked' : ''}>${label}</label>`;
        const row = item => `<li class="vkt-material${item.posts || item.replies ? '' : ' is-off'}">
                <div class="vkt-material-head"><strong>${esc(item.title)}</strong>${badge(item.file ? 'файл плагина' : 'свой текст')}<small class="vkt-muted">${num(item.chars)} симв.${Number(item.chars) > MATERIAL_PROMPT_LIMIT ? ` · в модель уйдут первые ${num(MATERIAL_PROMPT_LIMIT)}` : ''}${item.posts || item.replies ? '' : ' · не используется'}</small></div>
                <div class="vkt-material-uses">${use(item, 'posts', 'Посты и серии')}${use(item, 'replies', 'Ответы на комментарии')}</div>
                <div class="vkt-table-actions">${button(item.file ? 'Посмотреть' : 'Править', item.file ? 'group-material-view' : 'group-material-edit', `data-id="${esc(item.id)}"`)}${button('Убрать', 'group-material-delete', `data-id="${esc(item.id)}"`)}</div>
            </li>`;
        const draft = groupMaterialDraft || {title: '', text: ''};
        const form = groupMaterialEdit ? `<form data-form="group-material" class="vkt-form vkt-material-form">
                <label>Название<input name="title" data-material-draft="title" maxlength="120" required value="${esc(draft.title)}" placeholder="Например, тон ответов на вопросы о цене"></label>
                <label>Текст материала, Markdown<textarea name="text" data-material-draft="text" rows="14" maxlength="20000" required class="vkt-code-input" placeholder="Правила, по которым модель пишет для этой группы">${esc(draft.text)}</textarea></label>
                <label>Или возьмите текст из файла .md / .txt — он подставится в поле выше<input type="file" accept=".md,.markdown,.txt,text/plain,text/markdown" data-material-file></label>
                ${groupMaterialEdit === 'new' ? `<div class="vkt-form-row"><label class="vkt-check"><input type="checkbox" name="posts" checked>Посты и серии</label><label class="vkt-check"><input type="checkbox" name="replies">Ответы на комментарии</label></div>` : ''}
                <div class="vkt-form-actions"><button class="vkt-button vkt-primary">${icon('check')} ${groupMaterialEdit === 'new' ? 'Добавить материал' : 'Сохранить материал'}</button>${button('Отмена', 'group-material-cancel')}</div>
            </form>` : '';
        return `<section class="vkt-panel vkt-group-materials"><div class="vkt-panel-heading"><div><h2>База ведения</h2><p>Материалы, по которым ведётся группа: методика, правила рубрик, ответы на частые вопросы. Отмеченные уходят в модель вместе с паспортом — в посты и серии или в ответы на комментарии. Если материал расходится с паспортом, главнее паспорт.</p></div></div>
            ${list.length ? `<ul class="vkt-material-list">${list.map(row).join('')}</ul>` : '<div class="vkt-info">Материалов пока нет: модель пишет только по паспорту и истории постов.</div>'}
            ${free.length ? `<div class="vkt-material-library"><h3>Готовые материалы плагина</h3>${free.map(item => `<div class="vkt-material-offer"><div><strong>${esc(item.title)}</strong><small class="vkt-muted">${esc(item.description || '')} · ${num(item.chars)} симв.</small></div>${button('Подключить', 'group-material-add', `data-file="${esc(item.file)}"`, true)}</div>`).join('')}</div>` : ''}
            ${form || `<div class="vkt-form-actions">${button('Добавить свой материал', 'group-material-new')}</div>`}
        </section>`;
    }
    // Настройки новостей как в форме: сохранённое, поверх — несохранённые правки.
    const groupNewsForm = group => {
        const saved = group.news || {};
        return {enabled: !!saved.enabled, method: saved.method || 'search', engine: saved.engine || '', sources: (saved.sources || []).join('\n'), topic: saved.topic || '', count: saved.count || 10, days: saved.days || 1, mode: saved.mode || 'posts', ...(groupNewsDraft || {})};
    };
    const groupNewsPayload = group => {
        const form = groupNewsForm(group);
        return {enabled: !!form.enabled, method: form.method, engine: form.engine, sources: form.sources, topic: form.topic, count: Number(form.count), days: Number(form.days), mode: form.mode};
    };
    function groupNewsPanel(group) {
        const saved = group.news || {};
        const form = groupNewsForm(group);
        const aiReady = !!state.settings.ai?.configured;
        const option = (value, label, current) => `<option value="${esc(value)}" ${String(current) === String(value) ? 'selected' : ''}>${label}</option>`;
        const dayLabels = {1: 'за сутки', 3: 'за 3 дня', 7: 'за неделю'};
        const check = groupNewsCheck?.groupId === Number(group.id) ? groupNewsCheck : null;
        const result = groupNewsResult?.groupId === Number(group.id) ? groupNewsResult : null;
        const intro = 'Свежие новости из интернета под эту группу: поиск находит статьи по вашей выборке, нейросеть пишет по ним записи, ссылку на источник добавляет плагин и проверяет, что она настоящая.';
        const bySearch = form.method !== 'rss';
        const engines = saved.search || [];
        if (!form.enabled) return `<section class="vkt-panel vkt-group-news"><div class="vkt-panel-heading"><div><h2>Новости</h2><p>${intro}</p></div></div>
            <form data-form="group-news" class="vkt-form"><label class="vkt-check"><input type="checkbox" data-news="enabled">Это новостная группа</label>${saved.enabled ? `<div class="vkt-form-actions"><button class="vkt-button vkt-primary">${icon('check')} Сохранить</button><span class="vkt-muted">Источники и выборка сохранятся — их можно включить обратно.</span></div>` : ''}</form></section>`;
        const checkHtml = check ? `<div class="vkt-news-check"><h3>Источники${check.fresh === undefined ? '' : ` · свежих новостей: ${num(check.fresh)}`}</h3><ul>${(check.sources || []).map(source => `<li class="${source.ok ? '' : 'is-failed'}"><span>${esc(source.url)}</span><small>${source.ok ? `${String(source.url).startsWith('Поиск') ? 'найдено' : 'читается · в ленте'} ${num(source.total)}, годных ${num(source.fresh)}${source.message ? ` · ${esc(source.message)}` : ''}` : esc(source.message)}</small></li>`).join('')}</ul>${(check.sample || []).length ? `<h3>Свежее сверху</h3><ul>${check.sample.map(item => `<li><span>${esc(item.title)}</span><small>${esc(item.source)}</small></li>`).join('')}</ul>` : ''}</div>` : '';
        const resultHtml = result && result.posts.length ? `<div class="vkt-news-result"><h3>${result.mode === 'digest' ? 'Дайджест готов' : `Готово записей: ${num(result.posts.length)}`} · модель выбирала из ${num(result.offered)} свежих</h3>
                <ul>${result.posts.map((post, index) => `<li><div><strong>${esc(post.title)}</strong><small class="vkt-muted">${esc(post.source)}</small></div><p>${esc(post.text)}</p>${button('Убрать', 'group-news-drop', `data-index="${index}"`)}</li>`).join('')}</ul>
                <div class="vkt-form-actions">${button(`${icon('clock')} Разложить по сетке серии`, 'group-news-series', '', true)}<span class="vkt-muted">Записи встанут в свободные слоты серии этой группы: там их можно поправить, добавить фото и поставить в очередь. Сами они не публикуются.</span></div></div>` : '';
        return `<section class="vkt-panel vkt-group-news"><div class="vkt-panel-heading"><div><h2>Новости</h2><p>${intro}</p></div></div>
            <form data-form="group-news" class="vkt-form">
                <label class="vkt-check"><input type="checkbox" data-news="enabled" checked>Это новостная группа</label>
                <label>Откуда брать новости<select data-news="method">${option('search', 'Поиск в интернете', form.method)}${option('rss', 'RSS-ленты сайтов', form.method)}</select></label>
                ${bySearch ? (engines.length ? `<label>Кто ищет<select data-news="engine">${engines.map(engine => option(engine.id, esc(engine.title), engines.some(item => item.id === form.engine) ? form.engine : engines[0].id)).join('')}</select><small class="vkt-help">Поиск и текст — разные модели: ищет выбранный здесь, посты пишет «Модель для текста» у кнопки сбора. ${engines.length > 1 ? '' : `Другого поиска пока нет: его даёт модель OpenRouter или Qwen${isAdmin() ? ' — подключается в «Настройках»' : ', модели подключает администратор'}. `}Сайты ниже необязательны: без них поиск идёт по всему интернету.</small></label>` : `<div class="vkt-info vkt-info-warning">Искать в интернете пока нечем: нужен ключ xAI либо модель OpenRouter или Qwen. ${isAdmin() ? 'Подключите модель в «Настройках».' : 'Модели подключает администратор.'} До тех пор работают только RSS-ленты.</div>`) : '<p class="vkt-help">Многие сайты RSS не отдают или прячут — тогда выберите «Поиск в интернете».</p>'}
                <label>${bySearch ? 'Сайты, где искать в первую очередь — необязательно, по одному в строке, до 5' : `Источники — адрес сайта или его RSS-ленты, по одному в строке, до ${num(saved.max_sources || 15)}`}<textarea data-news="sources" rows="${bySearch ? 3 : 5}" class="vkt-code-input" placeholder="${bySearch ? 'https://www.kp.ru' : 'https://example.ru/rss&#10;https://example.com'}">${esc(form.sources)}</textarea></label>
                <label>${bySearch ? 'Какие новости искать' : 'Какие новости брать'}<textarea data-news="topic" rows="4" maxlength="1500" placeholder="Например: только баскетбол НБА — матчи, обмены, травмы. Без слухов, ставок и политики.">${esc(form.topic)}</textarea></label>
                <div class="vkt-form-row vkt-news-options">
                    <label>Сколько новостей за сбор<select data-news="count">${(saved.counts || [5, 10, 15, 20, 30]).map(value => option(value, num(value), form.count)).join('')}</select></label>
                    <label>Свежесть<select data-news="days">${(saved.day_options || [1, 3, 7]).map(value => option(value, dayLabels[value] || `за ${value} дн.`, form.days)).join('')}</select></label>
                    <label>Что получить<select data-news="mode">${option('posts', 'Отдельный пост на каждую новость', form.mode)}${option('digest', 'Один пост-дайджест', form.mode)}</select></label>
                </div>
                <div class="vkt-form-actions"><button class="vkt-button vkt-primary">${icon('check')} Сохранить настройки</button>${bySearch ? '' : button('Проверить источники', 'group-news-check')}${groupNewsDraft ? '<span class="vkt-muted">Есть несохранённые правки — сбор и проверка сохранят их сами.</span>' : ''}</div>
            </form>
            ${checkHtml}
            ${aiReady ? `<div class="vkt-ai-row vkt-news-run">${modelPicker(state.settings.ai)}${button(`${icon('fire')} Собрать новости`, 'group-news-collect', '', true)}<small class="vkt-help">Взятые новости запоминаются и в следующий сбор не попадут. Уже использовано: ${num(saved.used || 0)}.${Number(saved.used) ? ` ${button('Забыть использованные', 'group-news-reset')}` : ''}</small></div>${quotaNote(state.settings.ai)}` : '<div class="vkt-info">Чтобы собирать новости, подключите модель для текстов в «Настройках».</div>'}
            ${resultHtml}
        </section>`;
    }
    function groupPage() {
        const group = groupDetail;
        if (!group) return heading('Мои сообщества', 'Загружаем группу…', button('← Все сообщества', 'group-close')) + '<div class="vkt-loading">Собираем сводку по группе…</div>';
        const m = group.metrics || {};
        const passport = groupPassportDraft !== null ? groupPassportDraft : (group.passport || (groupsData?.template || '').replace('# Паспорт сообщества', `# Паспорт сообщества «${group.name || 'club' + group.group_id}»`));
        const aiReady = !!state.settings.ai?.configured;
        const actions = button('← Все сообщества', 'group-close') + (m.source_id ? button('Все посты группы', 'community-posts', `data-id="${Number(m.source_id)}"`) : '') + button(`${icon('clock')} Серия для группы`, 'group-series', `data-id="${Number(group.id)}"`, true);
        const posts = group.posts || [];
        return heading(esc(group.name || `club${group.group_id}`), `<a href="${esc(groupLink(group))}" target="_blank" rel="noopener noreferrer">${esc(groupLink(group).replace('https://', ''))} ↗</a>${m.measured_at ? ` · собрано ${esc(ago(m.measured_at))}` : ' · ждёт первого сбора'}`, `<div class="vkt-table-actions">${actions}</div>`) +
            `<div class="vkt-stats">${stat('Подписчики', short(m.members), 'По последнему обходу', 'people')}${stat('Постов за 30 дней', num(m.posts30 || 0), `всего в базе ${num(m.posts || 0)}`, 'post', 'purple')}${stat('Прирост за 7 дней', signed(m.g7), `за сутки ${signed(m.g1)}`, 'arrow', 'green')}${stat('ERR', m.err === null || m.err === undefined ? '—' : decimal(m.err, 2) + '%', 'Среднее по постам', 'heart', 'orange')}</div>` +
            `<div class="vkt-group-layout">
                <section class="vkt-panel"><div class="vkt-panel-heading"><div><h2>Охваты</h2><p>Кто видел группу, а не только посты.</p></div></div>${groupStatsPanel(group)}</section>
                <section class="vkt-panel"><div class="vkt-panel-heading"><div><h2>Как заходят посты</h2><p>Сводка сборщика: её же видит нейросеть, когда пишет для группы.</p></div></div>${groupDigestPanel(group.digest)}</section>
            </div>` +
            `<section class="vkt-panel vkt-group-passport"><div class="vkt-panel-heading"><div><h2>Паспорт группы</h2><p>Markdown: кто ведёт и за кем закреплена, аудитория, формат, тон, призывы, запреты. Уходит в модель вместе с историей постов, когда пишутся посты, серии и ответы на комментарии.${group.passport_at ? ` Сохранён ${esc(date(group.passport_at))}.` : ' Ещё не сохранён.'}</p></div></div>
                <form data-form="group-passport" class="vkt-form">
                    <textarea name="passport" data-group-passport rows="24" maxlength="20000" class="vkt-code-input">${esc(passport)}</textarea>
                    ${aiReady ? `<div class="vkt-ai-row">${modelPicker(state.settings.ai)}${button(`${icon('fire')} Черновик по постам`, 'group-passport-draft', `data-id="${Number(group.id)}"`)}<small class="vkt-help">Нейросеть заполнит формат, тон и призывы по постам. Уже вписанное сохранит, «Кто ведёт» оставит вам.</small></div>${quotaNote(state.settings.ai)}` : ''}
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">${icon('check')} Сохранить паспорт</button>${groupPassportDraft !== null ? '<span class="vkt-muted">Есть несохранённые правки.</span>' : ''}</div>
                </form>
            </section>` +
            groupMaterialsPanel(group) +
            groupNewsPanel(group) +
            `<div class="vkt-section-title"><h2>Последние посты</h2><span class="vkt-muted">Замеры сборщика: просмотры и их прирост.</span></div>` +
            (posts.length ? `<section class="vkt-panel vkt-table-wrap"><table><thead><tr><th>Пост</th><th>Вышел</th><th>Просмотры</th><th>24 часа</th><th>7 дней</th><th>ERR</th><th></th></tr></thead><tbody>${posts.map(post => `<tr><td><strong>${esc(String(post.text || 'Запись без текста').slice(0, 160))}</strong>${Number(post.is_pinned) ? ' ' + badge('закреплён') : ''}${Number(post.is_ad) ? ' ' + badge('реклама') : ''}</td><td>${date(post.published_at)}<small class="vkt-muted">${ago(post.published_at)}</small></td><td><strong>${short(post.views)}</strong></td><td class="vkt-growth">${signed(post.g1)}</td><td class="vkt-growth">${signed(post.g7)}</td><td>${post.err === null ? '—' : decimal(post.err, 2) + '%'}</td><td class="vkt-table-actions">${button('Динамика', 'post-history', `data-id="${Number(post.id)}"`)}<a class="vkt-button" href="https://vk.com/wall${Number(post.owner_id)}_${Number(post.post_id)}" target="_blank" rel="noopener noreferrer">VK ↗</a></td></tr>`).join('')}</tbody></table></section>`
                : '<div class="vkt-info">Постов группы в базе пока нет — сборщик обойдёт её в ближайшие минуты.</div>');
    }

    function communities() {
        if (!communitiesData) return heading('Сообщества', 'Загружаем сводку…') + '<div class="vkt-loading">Считаем агрегаты по сообществам…</div>';
        const rows = [...communitiesData].sort((a, b) => (Number(b[communitiesSort]) || 0) - (Number(a[communitiesSort]) || 0));
        const totals = rows.reduce((acc, row) => ({posts: acc.posts + Number(row.posts || 0), views: acc.views + Number(row.views || 0), members: acc.members + Number(row.members || 0), g1: acc.g1 + Number(row.g1 || 0)}), {posts: 0, views: 0, members: 0, g1: 0});
        const columns = {views: 'Просмотры', g1: 'Прирост за сутки', g3: 'Прирост за 3 дня', g7: 'Прирост за 7 дней', g30: 'Прирост за 30 дней', members: 'Подписчики', posts: 'Постов', err: 'ERR', viral: 'Вирусность'};
        return heading('Сообщества', 'Все отслеживаемые сообщества и их суммарная динамика.', button(`${icon('refresh')} Обновить`, 'communities-reload')) +
            `<div class="vkt-stats">${stat('Сообществ', num(rows.length), 'Источники в мониторинге', 'people')}${stat('Постов собрано', num(totals.posts), 'По всем сообществам', 'post', 'purple')}${stat('Суммарные просмотры', short(totals.views), 'По последним замерам', 'eye', 'green')}${stat('Прирост за сутки', signed(totals.g1), 'Сумма приростов постов', 'arrow', 'orange')}</div>` +
            `<section class="vkt-panel vkt-posts-toolbar"><div class="vkt-toolbar-row"><span class="vkt-muted">Сортировка</span><div class="vkt-chips">${Object.entries(columns).map(([key, label]) => `<button type="button" class="vkt-chip-button ${communitiesSort === key ? 'is-active' : ''}" data-command="communities-sort" data-value="${key}">${label}</button>`).join('')}</div></div></section>` +
            (rows.length ? `<section class="vkt-panel vkt-table-wrap"><table class="vkt-community-table"><thead><tr><th>Сообщество</th><th>Подписчики</th><th>Постов</th><th>Просмотры</th><th>24 часа</th><th>3 дня</th><th>7 дней</th><th>30 дней</th><th>ERR</th><th>Вирусность</th><th>Последний пост</th><th></th></tr></thead><tbody>${rows.map(row => `<tr><td class="vkt-community-cell">${safeUrl(row.photo || '') ? `<img src="${safeUrl(row.photo)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="vkt-post-avatar">${esc(String(row.title || row.value).slice(0, 1))}</span>`}<div><strong>${esc(row.title || row.value)}</strong><small><a href="https://vk.com/${esc(String(row.value).replace(/^-/, 'club'))}" target="_blank" rel="noopener noreferrer">${esc(row.value)} ↗</a>${Number(row.enabled) ? '' : ' · на паузе'}</small></div></td>
                <td>${short(row.members)}</td><td>${num(row.posts)}</td><td><strong>${short(row.views)}</strong></td>
                <td class="vkt-growth">${signed(row.g1)}</td><td class="vkt-growth">${signed(row.g3)}</td><td class="vkt-growth">${signed(row.g7)}</td><td class="vkt-growth">${signed(row.g30)}</td>
                <td>${row.err === null ? '—' : decimal(row.err, 2) + '%'}</td><td>${row.viral === null ? '—' : decimal(row.viral, 2) + '×'}</td>
                <td>${row.last_post ? `${date(row.last_post)}<small class="vkt-muted">${ago(row.last_post)}</small>` : '<span class="vkt-muted">—</span>'}</td>
                <td class="vkt-table-actions">${button('Посты', 'community-posts', `data-id="${Number(row.id)}"`)}${button(Number(row.enabled) ? 'Пауза' : 'Включить', 'source-toggle', `data-id="${Number(row.id)}" data-enabled="${Number(row.enabled) ? 0 : 1}"`)}</td></tr>`).join('')}</tbody></table></section>`
                : empty('Сообществ пока нет', 'Добавьте источники — сводка появится после первого обхода стен.', 'sources', 'Добавить источники'));
    }
    const publishingStatuses = {draft: 'Ожидает проверки', waiting_approval: 'Ожидает проверки', scheduled: 'Запланировано', queued: 'В очереди', publishing: 'Публикуется', pending: 'В очереди', published: 'Опубликовано', partial: 'Частично', failed: 'Ошибка', cancelled: 'Отменено'};
    const fileSize = bytes => {
        const n = Number(bytes) || 0;
        return n >= 1048576 ? decimal(n / 1048576, 1) + ' МБ' : Math.max(1, Math.round(n / 1024)) + ' КБ';
    };
    const mediaChip = item => `<figure class="vkt-media-chip">${safeUrl(item.thumbnail || '') ? `<img src="${safeUrl(item.thumbnail)}" alt="" loading="lazy">` : `<span class="vkt-media-kind">${icon('play')}</span>`}<figcaption><strong>${esc(item.title || item.name)}</strong><small>${item.type === 'image' ? 'Изображение' : 'Видео'} · ${fileSize(item.size)}</small></figcaption><button type="button" class="vkt-media-remove" data-command="media-remove" data-id="${Number(item.id)}" aria-label="Убрать файл">×</button></figure>`;
    const fluxMediaHtml = () => fluxMedia.length
        ? fluxMedia.map((item, index) => mediaChip(item).replace('<figcaption>', `<figcaption><em class="vkt-flux-index">image ${index + 1}</em>`)).join('')
        : '<p class="vkt-muted">Изображений нет: запрос нарисует картинку с нуля.</p>';
    const composerMediaHtml = () => composerMedia.length
        ? composerMedia.map(mediaChip).join('')
        : '<p class="vkt-muted">Файлы не выбраны. Загрузите с компьютера, возьмите из медиатеки или сгенерируйте.</p>';
    // Чипы обновляем точечно: перерисовка раздела стёрла бы набранный текст и выбранные группы.
    function renderComposerMedia() {
        const box = $('#vkt-composer-media');
        if (box) box.innerHTML = composerMediaHtml();
    }
    function addComposerMedia(item) {
        if (!item || !item.id) return false;
        const limit = Number(publishingData?.status?.media_limit) || 10;
        if (composerMedia.some(media => Number(media.id) === Number(item.id))) { toast('Этот файл уже выбран.'); return false; }
        if (composerMedia.length >= limit) { toast(`VK принимает не больше ${limit} вложений в одной записи.`, true); return false; }
        composerMedia.push(item);
        renderComposerMedia();
        return true;
    }
    function addSeriesMedia(index, item) {
        const slot = seriesSlots[index];
        if (!slot || !item || !item.id) return false;
        if (slot.media.some(media => Number(media.id) === Number(item.id))) { toast('Этот файл в слоте уже есть.'); return false; }
        if (slot.media.length >= 10) { toast('VK принимает не больше 10 вложений в одной записи.', true); return false; }
        slot.media.push(item);
        return true;
    }
    async function seriesQueue(trigger) {
        const form = $('[data-form="series-groups"]');
        if (!seriesGroup) { toast('Выберите сообщество серии.', true); return; }
        const slots = seriesFilled();
        if (!slots.length) { toast('Заполните хотя бы один слот: текст, файл или вложение.', true); return; }
        if (!confirm(seriesTarget ? `Добавить ${slots.length} записей в серию «${seriesTitle(seriesTarget)}»?` : `Поставить в очередь ${slots.length} записей?`)) return;
        trigger.disabled = true;
        try {
            const result = await act('series_queue', {
                // Название серии — начало темы промпта: по нему серию узнают в списке запущенных.
                title: seriesPrompt.trim().slice(0, 120),
                groups: [Number(seriesGroup)],
                series_id: seriesTarget,
                signed: !!form?.querySelector('input[name="signed"]')?.checked,
                close_comments: !!form?.querySelector('input[name="close_comments"]')?.checked,
                slots: slots.map(slot => ({scheduled_at: new Date(slot.at).toISOString(), message: slot.message, attachments: slot.attachments, media: slot.media.map(item => Number(item.id))})),
            });
            const failed = result.failed || [];
            // Поставленные слоты убираем из сетки, отклонённые оставляем вместе
            // с текстами: иначе работа пропадёт, а причину уже не увидеть.
            const queued = new Set((result.posts || []).map(post => String(post.scheduled_at)));
            seriesSlots = seriesSlots.filter(slot => !queued.has(new Date(slot.at).toISOString()));
            // Следующие слоты дополняют эту же серию, а не заводят новую.
            if (result.series_id) seriesTarget = result.series_id;
            toast(failed.length
                ? `В очередь встало ${result.created}, отклонено ${failed.length}. Первая причина: ${failed[0].error}`
                : `Серия в очереди: ${result.created} записей.`, failed.length > 0);
            await load();
        } catch (error) { toast(error.message, true, error.fix); }
        finally { trigger.disabled = false; }
    }
    function renderFluxMedia() {
        const box = $('#vkt-flux-media');
        if (box) box.innerHTML = fluxMediaHtml();
    }
    function addFluxMedia(item) {
        const limit = Number(state.settings.flux?.max_references) || 4;
        if (!item || !item.id) return false;
        if (item.type !== 'image') { toast('Стенд принимает только изображения.', true); return false; }
        if (fluxMedia.some(media => Number(media.id) === Number(item.id))) { toast('Этот файл уже выбран.'); return false; }
        if (fluxMedia.length >= limit) { toast(`Больше ${limit} изображений стенд не отправляет.`, true); return false; }
        fluxMedia.push(item);
        renderFluxMedia();
        return true;
    }
    // Задача у BFL асинхронная: ставим её и опрашиваем статус до ответа.
    async function fluxAwait(id, report = null, action = 'flux_status') {
        const progress = $('#vkt-flux-progress');
        const stages = {Pending: 'в очереди', Reasoning: 'обдумывает', Generating: 'рисует'};
        for (let attempt = 0; attempt < 80; attempt += 1) {
            await new Promise(resolve => setTimeout(resolve, attempt === 0 ? 2000 : 3000));
            const status = await act(action, {id});
            if (status.status === 'done') return status;
            const text = `Сервис ${stages[status.stage] || 'работает'}${status.progress ? `: ${status.progress}%` : ''}. Не закрывайте вкладку.`;
            // Серия рисует не на стенде: прогресс показывает там, откуда запросили.
            if (report) report(text);
            else if (progress) { progress.hidden = false; progress.textContent = text; }
        }
        throw new Error('Задача считается дольше четырёх минут. Ответ придёт в журнал — попробуйте позже ещё раз.');
    }
    async function mediaLibrary(search = '') {
        modal(`<h2>Медиатека</h2><p class="vkt-muted">Загружаем файлы…</p>`);
        const items = (await request(`media?limit=48&search=${encodeURIComponent(search)}`)).items || [];
        const grid = items.length
            ? `<div class="vkt-media-library">${items.map(item => `<button type="button" class="vkt-media-card" data-command="media-pick" data-id="${Number(item.id)}">${safeUrl(item.thumbnail || '') ? `<img src="${safeUrl(item.thumbnail)}" alt="" loading="lazy">` : `<span class="vkt-media-kind">${icon('play')}</span>`}<span>${esc(item.title || item.name)}<small>${item.type === 'image' ? 'Изображение' : 'Видео'} · ${fileSize(item.size)}</small></span></button>`).join('')}</div>`
            : `<p class="vkt-muted">${search ? 'По этому запросу ничего нет.' : 'В медиатеке пока нет изображений и MP4-видео.'}</p>`;
        modal(`<h2>Медиатека</h2><p class="vkt-muted">Изображения и MP4-видео сайта. Плагин сам передаст файл в VK — ID вложения искать не нужно.</p><form data-form="media-search" class="vkt-form vkt-form-inline"><label>Поиск<input name="search" value="${esc(search)}" placeholder="Название файла"></label><button class="vkt-button">${icon('search')} Найти</button></form>${grid}`);
        mediaLibraryItems = items;
    }
    const ratioLabels = {portrait: 'Вертикально 3:4', square: 'Квадрат 1:1', landscape: 'Горизонтально 16:9', story: 'История 9:16'};
    const ratioOptions = list => (list || []).map(value => `<option value="${esc(value)}">${esc(ratioLabels[value] || value)}</option>`).join('');
    function aiDialog(kind) {
        const ai = publishingData?.status?.ai || {};
        // Текст пишет любая подключённая модель, картинки — любая из реестра, видео — только xAI.
        if (kind === 'text' && !ai.configured) { toast('Не подключена модель для текстов.', true, isAdmin() ? {view: 'settings', label: 'Добавить модель — «Настройки»'} : null); return; }
        if (kind === 'video' && !ai.media_configured) { toast('Видео генерирует xAI, а его ключ VKT_XAI_API_KEY не задан.', true); return; }
        const providers = kind === 'image' ? imageProviders() : [];
        if (kind === 'image' && !providers.length) { toast('Не подключена ни одна модель для картинок.', true, isAdmin() ? {view: 'flux', label: 'Ключ BFL — «Генерация фото»'} : null); return; }
        // К записи идёт не больше десяти файлов — лишние варианты некуда прикрепить.
        const room = (Number(publishingData?.status?.media_limit) || 10) - composerMedia.length;
        if (kind === 'image' && room < 1) { toast('К записи уже приложено максимум файлов.', true); return; }
        const titles = {text: 'Текст записи', image: 'Изображение', video: 'Видео'};
        const hints = {
            text: 'Опишите, о чём пост. Уже набранный текст уйдёт как черновик для доработки.',
            image: 'Опишите кадр. Готовые файлы попадут в медиатеку сайта и сразу прикрепятся к записи.',
            video: 'Опишите сцену. Ролик длится 6 секунд и генерируется 1–3 минуты.',
        };
        const chosen = (ai.models || []).find(model => model.id === (aiModel || ai.default_model));
        const models = {text: chosen ? chosen.model : '', image: ai.image_model, video: ai.video_model};
        const ratios = kind === 'image' ? Object.keys(fluxSizes) : kind === 'video' ? ai.video_ratios : null;
        const imagePicker = kind === 'image' ? `<div class="vkt-form-row"><label>Чем рисуем<select name="provider">${providers.map(([id, label]) => `<option value="${esc(id)}" ${id === seriesImage.provider ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label><label>Сколько вариантов<select name="count">${variantOptions(room)}</select></label></div>` : '';
        modal(`<h2>Генерация · ${titles[kind]}</h2><p class="vkt-muted">${hints[kind]}${kind === 'image' ? '' : ` Модель: <code>${esc(models[kind] || '')}</code>.`}</p><form data-form="ai-${kind}" class="vkt-form">${kind === 'text' ? modelPicker(ai) : ''}${imagePicker}<label>Что нужно сделать<textarea name="prompt" rows="5" required minlength="3" maxlength="5000" placeholder="Например: анонс распродажи осенней коллекции">${esc(aiPrompt)}</textarea></label>${ratios ? `<label>Формат кадра<select name="ratio">${ratioOptions(ratios)}</select></label>` : ''}${kind === 'image' ? quotaNote(ai) : ''}<div id="vkt-ai-progress" class="vkt-help" hidden></div><button class="vkt-button vkt-primary">${icon('fire')} Сгенерировать</button></form>`);
    }
    // Видео у xAI готовится асинхронно: запускаем задачу и опрашиваем её статус.
    async function awaitVideo(requestId) {
        const progress = $('#vkt-ai-progress');
        for (let attempt = 0; attempt < 40; attempt += 1) {
            await new Promise(resolve => setTimeout(resolve, attempt === 0 ? 4000 : 8000));
            const status = await act('ai_video_status', {request_id: requestId});
            if (status.status === 'done') return status.media;
            if (progress) { progress.hidden = false; progress.textContent = `Готовность ролика: ${Number(status.progress) || 0}%. Не закрывайте окно.`; }
        }
        throw new Error('Ролик готовится дольше обычного. Откройте генерацию ещё раз через минуту — результат подхватится по тому же заданию.');
    }
    const publishingBadge = status => badge(publishingStatuses[status] || esc(status), status === 'published' ? 'green' : status === 'failed' || status === 'partial' ? 'red' : '');
    function publishing() {
        if (!publishingData) return heading('Автопостинг', 'Загружаем свои сообщества и очередь публикаций…') + '<div class="vkt-loading">Загружаем…</div>';
        const groups = publishingData.groups || [];
        const enabled = groups.filter(group => Number(group.enabled) && Number(group.can_post));
        const posts = publishingData.posts || [];
        const totals = posts.reduce((result, post) => { result[post.status] = (result[post.status] || 0) + 1; return result; }, {});
        const aiReady = !!publishingData.status?.ai?.configured;
        const nativeMedia = !!publishingData.status?.media_native;
        const groupChoices = enabled.map(group => `<label class="vkt-publishing-group"><input type="checkbox" name="groups" value="${Number(group.id)}"><span>${safeUrl(group.photo || '') ? `<img src="${safeUrl(group.photo)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<i>${esc(String(group.name || group.group_id).slice(0, 1))}</i>`}<strong>${esc(group.name || `club${group.group_id}`)}</strong><small>${esc(group.screen_name || `club${group.group_id}`)}</small></span></label>`).join('');
        const rows = posts.map(post => {
            const deliveries = post.deliveries || [];
            const targets = deliveries.map(delivery => {
                const title = esc(delivery.name || delivery.screen_name || `club${delivery.group_id}`);
                const link = Number(delivery.vk_post_id) ? `https://vk.com/wall-${Number(delivery.group_id)}_${Number(delivery.vk_post_id)}` : '';
                return `<span class="vkt-publishing-target">${link ? `<a href="${link}" target="_blank" rel="noopener noreferrer">${title} ↗</a>` : title} ${publishingBadge(delivery.status)}${delivery.error ? `<small>${esc(delivery.error)}${delivery.updated_at ? ` · попытка ${date(delivery.updated_at)}` : ''}</small>` : ''}</span>`;
            }).join('');
            const cancellable = deliveries.some(delivery => ['pending', 'waiting_approval'].includes(delivery.status));
            const retryable = deliveries.some(delivery => delivery.status === 'failed');
            return `<tr><td><strong>${esc(post.message ? String(post.message).slice(0, 130) : 'Публикация с вложением')}</strong>${post.attachments ? `<small class="vkt-muted">${esc(post.attachments)}</small>` : ''}${(post.media_items || []).length ? `<span class="vkt-media-mini">${post.media_items.map(item => safeUrl(item.thumbnail || '') ? `<img src="${safeUrl(item.thumbnail)}" alt="${esc(item.name)}" loading="lazy">` : `<em>${esc(item.name)}</em>`).join('')}</span>` : ''}<small class="vkt-muted">${post.origin === 'agents' ? 'Подготовлено агентами' : 'Создано вручную'}</small></td><td>${date(post.scheduled_at)}</td><td>${publishingBadge(post.status)}</td><td><div class="vkt-publishing-targets">${targets}</div></td><td class="vkt-table-actions">${post.status === 'draft' ? button('Проверить', 'publishing-review', `data-id="${Number(post.id)}"`) : ''}${retryable ? button('Повторить', 'publishing-retry', `data-id="${Number(post.id)}"`) : ''}${cancellable ? button('Отменить', 'publishing-cancel', `data-id="${Number(post.id)}"`) : ''}</td></tr>`;
        }).join('');
        return heading('Автопостинг', 'Создавайте записи и публикуйте их сразу или по расписанию в несколько своих групп.', button(`${icon('refresh')} Обновить мои группы`, 'publishing-sync', '', true) + button('Запустить очередь', 'publishing-run') + button('Стенд постинга', 'probe-matrix')) +
            (!publishingData.status.token_ready ? '<div class="vkt-info vkt-info-warning">Для публикации сохраните в настройках пользовательский токен VK ID с разрешениями <code>wall</code> и <code>groups</code>. Сервисный ключ умеет только читать стены.</div>' : '') +
            (publishingData.status.community_only ? '<div class="vkt-info">Подключены только ключи сообществ. Группы с ключом уже в списке адресатов; каждый ключ публикует только на стене своей группы и только текст — медиа VK ему запрещает.</div>' : '') +
            `<div class="vkt-stats">${stat('Своих групп', num(enabled.length), 'Включены и доступны для записи', 'people')}${stat('В очереди', num((totals.queued || 0) + (totals.scheduled || 0) + (totals.draft || 0)), state.settings.publishing_review ? 'Черновики ждут подтверждения' : 'Отправка сразу или по расписанию', 'check', 'purple')}${stat('Опубликовано', num(totals.published || 0), 'Полностью во все адресаты', 'send', 'green')}${stat('С ошибкой', num((totals.failed || 0) + (totals.partial || 0)), 'Можно повторить только неудачные адресаты', 'list', 'orange')}</div>` +
            `<div class="vkt-publishing-layout"><section class="vkt-panel"><div class="vkt-panel-heading"><div><h2>Новая запись</h2><p>Один текст можно подготовить сразу для нескольких сообществ.</p></div></div>${enabled.length ? `<form data-form="publishing" class="vkt-form"><fieldset class="vkt-publishing-groups"><legend>Куда публикуем</legend>${groupChoices}</fieldset><label>Текст записи<textarea name="message" rows="9" maxlength="16000" placeholder="Напишите текст поста…"></textarea></label>${aiReady ? `<div class="vkt-ai-row">${button(`${icon('fire')} Сгенерировать текст`, 'ai-text')}<small class="vkt-help">Черновик уйдёт в модель как основа.</small></div>${quotaNote(publishingData.status.ai)}` : ''}<fieldset class="vkt-media"><legend>Файлы с сервера</legend>${nativeMedia ? `<div id="vkt-composer-media" class="vkt-media-chips">${composerMediaHtml()}</div><div class="vkt-media-actions"><label class="vkt-button vkt-file"><input type="file" accept="image/*,video/mp4" data-media-upload hidden>${icon('plus')} Загрузить файл</label>${button(`${icon('layers')} Из медиатеки`, 'media-library')}${imageProviders().length ? button(`${icon('fire')} Картинка`, 'ai-image') : ''}${publishingData.status?.ai?.media_configured ? button(`${icon('play')} Видео`, 'ai-video') : ''}</div><small class="vkt-help">До 10 файлов. Файл уходит в VK прямо с сервера: ID вложения плагин получает сам, отдельно для каждого сообщества.</small>` : '<div class="vkt-info vkt-info-warning">VK не разрешает ключу сообщества прикладывать медиа: загрузка фото закрыта ошибкой 27, видео — ошибкой 5, а ссылка на файл отклоняется кодом 100. Обходного пути нет. Сохраните в настройках пользовательский токен VK ID с правами <code>wall</code>, <code>photos</code>, <code>groups</code> и <code>video</code> — тогда файлы и генерация картинок станут доступны. Текст публикуется и сейчас.</div>'}</fieldset><label>Вложения VK или ссылка<textarea name="attachments" rows="3" class="vkt-code-input" placeholder="photo-123_456, video-123_789 или https://example.com"></textarea><small class="vkt-help">Необязательное поле для уже существующих вложений VK. До 10 ID через запятую; внешняя ссылка — только одна, и она должна вести на страницу с превью: прямой адрес картинки VK отклоняет.</small></label><label>Дата и время публикации<input type="datetime-local" name="scheduled_at"><small class="vkt-help">Оставьте пустым — запись отправится сразу после нажатия кнопки. Время вводится в часовом поясе вашего устройства.</small></label><div class="vkt-form-row"><label class="vkt-check"><input type="checkbox" name="signed">Подписать запись моим именем</label><label class="vkt-check"><input type="checkbox" name="close_comments">Закрыть комментарии</label></div><p class="vkt-help">${state.settings.publishing_review ? 'Включена ручная проверка: запись сначала сохранится черновиком.' : 'Ручная проверка выключена: запись без даты будет опубликована сразу.'}</p><button class="vkt-button vkt-primary">${icon('send')} ${state.settings.publishing_review ? 'Сохранить на проверку' : 'Опубликовать / запланировать'}</button></form>` : empty(groups.length ? 'Нет доступных групп' : 'Подключите свои сообщества', groups.length ? 'Обновите список: право редактора могло быть отозвано, либо все группы выключены.' : 'Нажмите «Обновить мои группы»: VK вернёт сообщества, где вы администратор или редактор.')}</section>` +
            `<aside class="vkt-panel"><div class="vkt-panel-heading"><div><h2>Мои сообщества</h2><p>Отдельный список: наблюдаемые источники не получают права записи.</p></div>${button(`${icon('plus')} Добавить группу`, 'community-key-add')}</div>${groups.length ? `<div class="vkt-own-groups">${groups.map(group => `<div><span>${safeUrl(group.photo || '') ? `<img src="${safeUrl(group.photo)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : ''}<strong>${esc(group.name || `club${group.group_id}`)}</strong><small><a href="https://vk.com/${esc(group.screen_name || `club${group.group_id}`)}" target="_blank" rel="noopener noreferrer">${esc(group.screen_name || `club${group.group_id}`)} ↗</a></small></span><span>${Number(group.can_post) ? badge(Number(group.enabled) ? 'Включено' : 'Выключено', Number(group.enabled) ? 'green' : '') : badge('Нет права записи', 'red')}${Number(group.can_post) ? button(Number(group.enabled) ? 'Выключить' : 'Включить', 'publishing-group-toggle', `data-id="${Number(group.id)}" data-enabled="${Number(group.enabled) ? 0 : 1}"`) : ''}${button(icon('settings'), 'group-settings', `data-id="${Number(group.group_id)}" title="Настройки группы: ключ и Callback" aria-label="Настройки группы"`)}</span></div>`).join('')}</div>` : '<p class="vkt-muted">Список ещё не загружен из VK.</p>'}</aside></div>` +
            `<div class="vkt-section-title"><h2>История и очередь</h2><span class="vkt-muted">Последняя проверка cron: ${date(publishingData.status.last)}</span></div>` +
            (posts.length ? `<section class="vkt-panel vkt-table-wrap"><table class="vkt-publishing-table"><thead><tr><th>Запись</th><th>Когда</th><th>Статус</th><th>Сообщества</th><th></th></tr></thead><tbody>${rows}</tbody></table></section>` : empty('Публикаций пока нет', 'Создайте первую запись: она появится здесь со статусом для каждой выбранной группы.'));
    }
    // ——— Комментарии: ответы от имени своих сообществ ———
    const replyStatuses = {pending: ['В очереди', ''], sending: ['Отправляется', ''], sent: ['Отправлен', 'green'], failed: ['Ошибка', 'red'], cancelled: ['Отменён', '']};
    const replyBadge = status => badge(...(replyStatuses[status] || [esc(status), '']));
    const commentsGroupRow = () => (commentsData?.groups || []).find(group => Number(group.group_id) === commentsGroup) || null;
    const commentsAiReady = () => !!commentsData?.status?.ai?.configured;
    // Уже отвеченный или поставленный в работу комментарий повторно не выбирается.
    const commentTaken = comment => ['pending', 'sending', 'sent'].includes(comment.queued);
    const commentOpen = comment => !comment.is_group && !comment.deleted && (!!comment.text || !!comment.has_media) && !comment.answered && !commentTaken(comment);
    async function loadComments() {
        commentsData = await request('comments');
        const groups = commentsData.groups || [];
        if (!groups.some(group => Number(group.group_id) === commentsGroup)) {
            commentsGroup = Number(groups[0]?.group_id || 0);
            commentsPosts = null; commentsPost = null; commentsThread = null;
            commentsSelected.clear();
        }
        if (commentsGroup && commentsMode === 'feed') {
            try { commentsFeed = await act('comments_inbox', {group_id: commentsGroup, filter: commentsFeedFilter}); }
            catch (error) { commentsFeed = {comments: [], total: 0, error: error.message}; }
            indexThread();
        }
        if (commentsGroup && commentsMode === 'posts' && !commentsPosts && commentsData.status?.reading) {
            try { commentsPosts = await act('comments_posts', {group_id: commentsGroup}); }
            catch (error) { commentsPosts = {posts: [], total: 0, offset: 0, error: error.message}; }
        }
    }
    // После ответа или действия с очередью перечитываем то, что сейчас на экране.
    // WP-cron срабатывает только при заходах на сайт, и очередь без них стоит.
    // Поэтому открытая вкладка «Комментарии» раз в минуту сама толкает свою
    // очередь. Пока человек печатает или открыто окно — не трогаем, чтобы не сбить ввод.
    async function commentsTick() {
        if (view !== 'comments' || !(commentsData?.queue || []).some(reply => ['pending', 'sending'].includes(reply.status))) return;
        if (dialog.open || ['TEXTAREA', 'INPUT', 'SELECT'].includes(document.activeElement?.tagName)) return;
        try { await act('comments_run'); await refreshComments(); } catch {}
    }
    if (typeof setInterval === 'function') setInterval(commentsTick, 60000);
    async function refreshComments() {
        if (commentsMode === 'feed') { await loadComments(); render(); return; }
        if (commentsPost) await openCommentsPost(commentsPost.id);
    }
    // Комментарии записи и ветки кладутся в общий индекс: по ключу «запись_комментарий» их находят форма ответа и выбор.
    function indexThread() {
        commentsIndex.clear();
        (commentsFeed?.comments || []).forEach(item => commentsIndex.set(`${item.post_id}_${item.id}`, {post: {id: item.post_id, text: item.post_text || ''}, comment: item}));
        if (!commentsThread || !commentsPost) return;
        (commentsThread.comments || []).forEach(comment => {
            [comment, ...(comment.thread || [])].forEach(item => commentsIndex.set(`${commentsPost.id}_${item.id}`, {post: commentsPost, comment: item}));
        });
    }
    const commentPayload = key => {
        const entry = commentsIndex.get(key) || null;
        if (entry) return {post_id: Number(entry.post.id), comment_id: Number(entry.comment.id), author_id: Number(entry.comment.from_id), author: entry.comment.author, comment_text: [entry.comment.text, commentMediaText(entry.comment)].filter(Boolean).join(' '), post_text: entry.post.text};
        return commentsSelected.get(key) || null;
    };
    async function openCommentsPost(id, offset = 0) {
        const post = (commentsPosts?.posts || []).find(item => Number(item.id) === Number(id));
        if (!post) return;
        if (!offset) { commentsPost = post; commentsThread = null; commentsReplyKey = ''; render(); }
        const result = await act('comments_thread', {group_id: commentsGroup, post_id: post.id, offset});
        if (offset && commentsThread) commentsThread = {...result, comments: [...commentsThread.comments, ...result.comments]};
        else commentsThread = result;
        indexThread();
        render();
    }
    // Вложения комментария: на стикер или фото без текста тоже отвечают, поэтому их надо видеть.
    const commentMediaLabels = {sticker: 'Стикер', photo: 'Фото', video: 'Видео', graffiti: 'Граффити', doc: 'Документ', audio: 'Аудио', link: 'Ссылка', audio_message: 'Голосовое сообщение', poll: 'Опрос', market: 'Товар'};
    const commentMedia = comment => (comment.media || []).map(item => {
        const url = safeUrl(item.url || '');
        const label = commentMediaLabels[item.type] || 'Вложение';
        return url
            ? `<img class="vkt-comment-media${item.type === 'sticker' ? ' is-sticker' : ''}" src="${url}" alt="${esc(label)}" title="${esc(item.title || label)}" loading="lazy" referrerpolicy="no-referrer">`
            : `<span class="vkt-badge">${esc(label)}${item.title ? `: ${esc(item.title)}` : ''}</span>`;
    }).join('');
    // То же словами — для очереди и нейросети, которым картинку не показать.
    const commentMediaText = comment => (comment.media || []).map(item => `[${(commentMediaLabels[item.type] || 'Вложение').toLowerCase()}${item.title ? `: ${item.title}` : ''}]`).join(' ') || (comment.has_media ? '[вложение без текста]' : '');
    function commentItem(comment, post, nested = false) {
        const key = `${post.id}_${comment.id}`;
        const pickable = !comment.is_group && !comment.deleted && (!!comment.text || !!comment.has_media) && !commentTaken(comment);
        const media = comment.deleted ? '' : commentMedia(comment);
        const avatar = safeUrl(comment.photo || '') ? `<img src="${safeUrl(comment.photo)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="vkt-post-avatar">${esc(String(comment.author || '?').slice(0, 1))}</span>`;
        const marks = [
            comment.is_group ? badge('Сообщество') : '',
            !nested && comment.answered ? badge('Есть ответ группы', 'green') : '',
            comment.queued ? replyBadge(comment.queued) : '',
        ].join('');
        const text = comment.deleted ? '<em class="vkt-muted">Комментарий удалён</em>' : comment.text ? esc(comment.text) : media ? '' : `<em class="vkt-muted">${comment.has_media ? 'Стикер или вложение без текста — нажмите «Загрузить из VK», чтобы увидеть его' : 'Пустой комментарий'}</em>`;
        const form = commentsReplyKey === key ? `<form data-form="comment-reply" data-key="${esc(key)}" class="vkt-form vkt-comment-reply"><textarea name="message" rows="3" maxlength="${Number(commentsData.status.max_length) || 2000}" data-draft="${esc(key)}" placeholder="Ответ от имени сообщества…" required>${esc(commentsDrafts.get(key) || '')}</textarea><div class="vkt-comment-reply-actions"><button type="submit" class="vkt-button vkt-primary">${icon('send')} Отправить</button>${commentsAiReady() ? button(`${icon('fire')} Сгенерировать`, 'comments-ai-one', `data-key="${esc(key)}"`) : ''}${button('Отмена', 'comments-reply-close')}</div></form>` : '';
        return `<div class="vkt-comment${nested ? ' is-nested' : ''}${comment.is_group ? ' is-own' : ''}">${pickable ? `<input type="checkbox" class="vkt-comment-pick" data-comments-pick="${esc(key)}" ${commentsSelected.has(key) ? 'checked' : ''} aria-label="Выбрать комментарий">` : '<span class="vkt-comment-pick"></span>'}${avatar}<div class="vkt-comment-body"><div class="vkt-comment-head"><strong>${Number(comment.from_id) > 0 ? `<a href="https://vk.com/id${Number(comment.from_id)}" target="_blank" rel="noopener noreferrer">${esc(comment.author)}</a>` : esc(comment.author)}</strong><small>${date(comment.date)}</small>${marks}</div>${text ? `<p>${text}</p>` : ''}${media ? `<div class="vkt-comment-medias">${media}</div>` : ''}${pickable && commentsReplyKey !== key ? `<button type="button" class="vkt-link-button" data-command="comments-reply-open" data-key="${esc(key)}">Ответить</button>` : ''}${form}${!nested && (comment.thread || []).length ? `<div class="vkt-comment-thread">${comment.thread.map(item => commentItem(item, post, true)).join('')}${Number(comment.thread_count) > comment.thread.length ? `<small class="vkt-muted">В ветке ещё ${num(Number(comment.thread_count) - comment.thread.length)} — они видны в VK.</small>` : ''}</div>` : ''}</div></div>`;
    }
    function commentsThreadPanel() {
        if (!commentsPost) return `<section class="vkt-panel vkt-comments-thread"><div class="vkt-comments-placeholder">${icon('comment')}<p>Выберите запись слева — здесь появятся её комментарии.</p></div></section>`;
        const post = commentsPost;
        const link = `https://vk.com/wall-${commentsGroup}_${Number(post.id)}`;
        const head = `<div class="vkt-comments-post-head"><div><p>${post.text ? esc(post.text) : '<em class="vkt-muted">Запись без текста</em>'}</p><small class="vkt-muted">${date(post.date)} · <a href="${link}" target="_blank" rel="noopener noreferrer">Открыть в VK ↗</a></small></div></div>`;
        if (!commentsThread) return `<section class="vkt-panel vkt-comments-thread">${head}<div class="vkt-loading">Загружаем комментарии…</div></section>`;
        const all = commentsThread.comments || [];
        const shown = commentsOnlyOpen ? all.filter(commentOpen) : all;
        const open = all.filter(commentOpen).length;
        const toolbar = `<div class="vkt-toolbar-row"><div class="vkt-chips"><button type="button" class="vkt-chip-button ${commentsOnlyOpen ? '' : 'is-active'}" data-command="comments-filter" data-value="all">Все · ${num(all.length)}</button><button type="button" class="vkt-chip-button ${commentsOnlyOpen ? 'is-active' : ''}" data-command="comments-filter" data-value="open">Без ответа · ${num(open)}</button></div><div class="vkt-toolbar-right">${open ? button('Отметить все без ответа', 'comments-select-open') : ''}</div></div>`;
        const list = shown.length ? shown.map(comment => commentItem(comment, post)).join('') : `<p class="vkt-muted">${all.length ? 'Все комментарии уже с ответом или в очереди.' : 'Комментариев пока нет.'}</p>`;
        const more = Number(commentsThread.total) > all.length ? button(`Ещё комментарии · ${num(Number(commentsThread.total) - all.length)}`, 'comments-more') : '';
        return `<section class="vkt-panel vkt-comments-thread">${head}${toolbar}<div class="vkt-comments-list">${list}</div>${more}</section>`;
    }
    // ——— Лента комментариев и Callback группы ———
    const callbackStatuses = {ok: ['Callback работает', 'green'], manual: ['Callback: ждёт подтверждения в VK', ''], wait: ['Callback: ждёт подтверждения', ''], failed: ['Callback: VK не достучался до сайта', 'red'], unconfigured: ['Callback не настроен в VK', 'red'], '': ['Callback не подключён', '']};
    const callbackBadge = (status, ready) => badge(...(callbackStatuses[ready || status ? status : ''] || [esc(status), '']));
    function commentsFeedPanel(group) {
        const feed = commentsFeed;
        const callback = group && Number(group.callback_ready)
            ? `${callbackBadge(group.callback_status, true)}${group.callback_at ? ` <small class="vkt-muted">последнее событие ${ago(group.callback_at)}</small>` : ''}`
            : `<span class="vkt-muted">Новые комментарии не приходят сами — подключите Callback в</span> ${button('настройках группы', 'group-settings', `data-id="${Number(group?.group_id)}"`)}`;
        const head = `<div class="vkt-toolbar-row"><div class="vkt-chips"><button type="button" class="vkt-chip-button ${commentsFeedFilter === 'open' ? 'is-active' : ''}" data-command="comments-feed-filter" data-value="open">Без ответа</button><button type="button" class="vkt-chip-button ${commentsFeedFilter === 'all' ? 'is-active' : ''}" data-command="comments-feed-filter" data-value="all">Все</button></div><div class="vkt-toolbar-right">${button(`${icon('refresh')} Загрузить из VK`, 'comments-scan')}${(feed?.comments || []).some(commentOpen) ? button('Отметить все без ответа', 'comments-select-open') : ''}</div></div><div class="vkt-feed-callback">${callback}</div>`;
        if (!feed) return `<section class="vkt-panel vkt-comments-thread">${head}<div class="vkt-loading">Загружаем ленту…</div></section>`;
        if (feed.error) return `<section class="vkt-panel vkt-comments-thread">${head}<div class="vkt-info vkt-info-warning">${esc(feed.error)}</div></section>`;
        const list = (feed.comments || []).map(comment => {
            const link = `https://vk.com/wall-${commentsGroup}_${Number(comment.post_id)}?reply=${Number(comment.id)}`;
            return `<div class="vkt-feed-item"><a class="vkt-feed-post" href="${link}" target="_blank" rel="noopener noreferrer">${comment.in_thread ? 'Ответ в ветке · ' : ''}${comment.post_text ? esc(String(comment.post_text).slice(0, 90)) : `Запись #${Number(comment.post_id)}`} ↗</a>${commentItem(comment, {id: comment.post_id, text: comment.post_text || ''})}</div>`;
        }).join('');
        const emptyText = Number(feed.total)
            ? 'Все комментарии уже с ответом или в очереди. Переключитесь на «Все», чтобы увидеть их.'
            : 'В ленте пока пусто. Нажмите «Загрузить из VK» — плагин соберёт комментарии последних записей. Новые будут приходить сами, когда подключён Callback.';
        return `<section class="vkt-panel vkt-comments-thread">${head}<div class="vkt-comments-list">${list || `<p class="vkt-muted">${emptyText}</p>`}</div></section>`;
    }
    async function groupSettings(groupId) {
        const info = await act('callback_info', {group_id: groupId});
        const group = [...(commentsData?.groups || []), ...(publishingData?.groups || [])].find(item => Number(item.group_id) === Number(groupId)) || {};
        const title = esc(group.name || `club${groupId}`);
        const suggested = Array.from(crypto.getRandomValues(new Uint8Array(12)), byte => 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'[byte % 56]).join('');
        const events = Object.values(info.events || {}).map(label => esc(label)).join(', ');
        modal(`<h2>Настройки группы · ${title}</h2>
            <h3>Ключ доступа сообщества</h3>
            ${info.has_key ? `<p>${badge('ключ сохранён', 'green')} <span class="vkt-muted">Заменить или убрать его можно в разделе «Публикация».</span></p>` : `<p class="vkt-muted">${esc(communityKeyHelp)}</p>${communityKeyForm().replace('placeholder="Необязательно: vk.com/club123 или 123"', `value="club${Number(groupId)}"`)}`}
            <hr class="vkt-settings-sep">
            <h3>Callback API — комментарии в реальном времени</h3>
            <p>${callbackBadge(info.status, info.configured)}${info.last_event ? ` <span class="vkt-muted">последнее событие ${ago(info.last_event)}</span>` : ''}</p>
            <p class="vkt-muted">VK сам присылает на сайт новые, изменённые и удалённые комментарии группы — они появляются в ленте «Комментариев» без обхода записей. События: ${events}.</p>
            <div class="vkt-form-actions">${button(`${icon('check')} Подключить автоматически`, 'callback-setup', `data-id="${Number(groupId)}" ${info.has_key ? '' : 'disabled'}`, true)}${info.configured ? button('Отключить', 'callback-forget', `data-id="${Number(groupId)}"`) : ''}</div>
            <small class="vkt-help">${info.has_key ? 'Нужно право ключа «управление сообществом»: плагин сам зарегистрирует сайт в группе, задаст секретный ключ и включит события.' : 'Для автоматического подключения нужен ключ группы. Или настройте вручную ниже.'}</small>
            <details class="vkt-callback-manual"><summary>Настроить вручную</summary>
                <ol class="vkt-muted"><li>В группе VK: Управление → Работа с API → Callback API → «Добавить сервер», версия API ${esc(state.settings.api_version || '5.199')}.</li><li>Адрес — скопируйте отсюда. Строку подтверждения из VK и секретный ключ впишите ниже и сохраните.</li><li>В VK впишите тот же секретный ключ, нажмите «Сохранить», затем «Подтвердить».</li><li>Во вкладке «Типы событий» отметьте комментарии на стене: добавление, редактирование, удаление, восстановление.</li></ol>
                <form data-form="callback-manual" data-id="${Number(groupId)}" class="vkt-form">
                    <label>Адрес сервера<input class="vkt-code-input" value="${esc(info.url)}" readonly data-select-all></label>
                    <div class="vkt-form-row"><label>Строка подтверждения из VK<input name="code" class="vkt-code-input" required value="${esc(info.code || '')}" placeholder="12381946"></label><label>Секретный ключ<input name="secret" class="vkt-code-input" required value="${info.has_secret ? '' : esc(suggested)}" placeholder="${info.has_secret ? 'сохранён — впишите, чтобы заменить' : ''}"></label></div>
                    <button type="submit" class="vkt-button">Сохранить</button>
                </form>
            </details>`);
    }
    function comments() {
        if (!commentsData) return heading('Комментарии', 'Загружаем свои сообщества и очередь ответов…') + '<div class="vkt-loading">Загружаем…</div>';
        const groups = commentsData.groups || [];
        const status = commentsData.status || {};
        const queue = commentsData.queue || [];
        const totals = queue.reduce((result, reply) => { result[reply.status] = (result[reply.status] || 0) + 1; return result; }, {});
        const actions = button(`${icon('refresh')} Обновить`, 'comments-refresh') + button('Запустить очередь', 'comments-run');
        if (!groups.length) return heading('Комментарии', 'Ответы на комментарии от имени своих сообществ.', actions) + empty('Нет своих сообществ', 'Загрузите группы, где вы администратор или редактор, и включите нужные — тогда их комментарии появятся здесь.', 'publishing', 'Открыть «Автопостинг»');
        const group = commentsGroupRow();
        const sender = {
            community: '<div class="vkt-info">Ответы в эту группу уходят её ключом сообщества — от имени группы, только текстом.</div>',
            user: '<div class="vkt-info">Ответы уходят вашим пользовательским токеном от имени группы. Если VK откажет в праве для приложения, подключите ключ этого сообщества в разделе «Публикация».</div>',
            '': '<div class="vkt-info vkt-info-warning">Отвечать в эту группу нечем: нужен ключ этого сообщества или пользовательский токен. Подключите их в разделе «Публикация».</div>',
        }[group?.sender || ''];
        const posts = commentsPosts?.posts || [];
        const postList = !status.reading
            ? '<div class="vkt-info vkt-info-warning">Комментарии читает общий ключ сбора, а он не настроен. Попросите администратора подключить сервисный ключ.</div>'
            : !commentsPosts ? '<div class="vkt-loading">Загружаем записи…</div>'
            : commentsPosts.error ? `<div class="vkt-info vkt-info-warning">${esc(commentsPosts.error)}</div>`
            : posts.length ? posts.map(post => `<button type="button" class="vkt-comments-post ${Number(commentsPost?.id) === Number(post.id) ? 'is-active' : ''}" data-command="comments-post" data-id="${Number(post.id)}">${safeUrl(post.thumb || '') ? `<img src="${safeUrl(post.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : ''}<span><strong>${post.text ? esc(post.text.slice(0, 120)) : 'Запись без текста'}</strong><small>${date(post.date)} · ${icon('comment')} ${num(post.comments)}${post.is_pinned ? ' · закреплена' : ''}${post.can_comment ? '' : ' · комментарии закрыты'}</small></span></button>`).join('') + (Number(commentsPosts.total) > posts.length ? button('Ещё записи', 'comments-posts-more') : '')
            : '<p class="vkt-muted">На стене пока нет записей.</p>';
        const rows = queue.map(reply => {
            const target = `https://vk.com/wall-${Number(reply.group_id)}_${Number(reply.post_id)}?reply=${Number(reply.vk_comment_id) || Number(reply.comment_id)}`;
            return `<tr><td><strong>${esc(reply.author_name || 'Комментарий')}</strong><small class="vkt-muted">${esc(reply.comment_text || '')}</small></td><td>${esc(reply.message)}${reply.origin === 'ai' ? '<small class="vkt-muted">Черновик нейросети</small>' : ''}</td><td><a href="${target}" target="_blank" rel="noopener noreferrer">${esc(reply.name || reply.screen_name || `club${Number(reply.group_id)}`)} ↗</a></td><td>${reply.status === 'sent' ? date(reply.sent_at) : date(reply.available_at)}</td><td>${replyBadge(reply.status)}${reply.error ? `<small>${esc(reply.error)}</small>` : ''}</td><td class="vkt-table-actions">${reply.status === 'failed' ? button('Повторить', 'comments-retry', `data-id="${Number(reply.id)}"`) : ''}${['pending', 'failed'].includes(reply.status) ? button('Отменить', 'comments-cancel', `data-id="${Number(reply.id)}"`) : ''}</td></tr>`;
        }).join('');
        const bar = commentsSelected.size ? `<div class="vkt-comments-bulkbar"><span>Выбрано комментариев: <strong>${num(commentsSelected.size)}</strong></span>${button(`${icon('send')} Ответить выбранным`, 'comments-bulk', '', true)}${button('Снять выбор', 'comments-clear')}</div>` : '';
        return heading('Комментарии', 'Ответы от имени своих сообществ: по одному или пачкой через очередь с паузами между ответами.', actions) +
            `<section class="vkt-panel vkt-posts-toolbar"><div class="vkt-toolbar-row"><label class="vkt-comments-group">Сообщество<select data-comments-group>${groups.map(item => `<option value="${Number(item.group_id)}" ${Number(item.group_id) === commentsGroup ? 'selected' : ''}>${esc(item.name || `club${item.group_id}`)}</option>`).join('')}</select></label>${group ? `<a href="https://vk.com/${esc(group.screen_name || `club${group.group_id}`)}" target="_blank" rel="noopener noreferrer">${esc(group.screen_name || `club${group.group_id}`)} ↗</a>` : ''}${group ? button(`${icon('settings')} Настройки группы`, 'group-settings', `data-id="${Number(group.group_id)}"`) : ''}</div>${sender}</section>` +
            `<div class="vkt-stats">${stat('В очереди', num((totals.pending || 0) + (totals.sending || 0)), `В группу не чаще раза в ${Math.round((status.min_gap || 60) / 60)} мин.`, 'clock', 'purple')}${stat('Отправлено', num(totals.sent || 0), 'Из последних 150 ответов', 'send', 'green')}${stat('С ошибкой', num(totals.failed || 0), 'Можно повторить', 'list', 'orange')}${stat('Выбрано', num(commentsSelected.size), 'Для ответа пачкой', 'check')}</div>` +
            `<div class="vkt-chips vkt-comments-modes"><button type="button" class="vkt-chip-button ${commentsMode === 'feed' ? 'is-active' : ''}" data-command="comments-mode" data-value="feed">${icon('comment')} Лента комментариев</button><button type="button" class="vkt-chip-button ${commentsMode === 'posts' ? 'is-active' : ''}" data-command="comments-mode" data-value="posts">${icon('post')} По записям</button></div>` +
            (commentsMode === 'feed'
                ? commentsFeedPanel(group)
                : `<div class="vkt-comments-layout"><section class="vkt-panel vkt-comments-posts"><div class="vkt-panel-heading"><div><h2>Записи</h2><p>Последние записи стены.</p></div></div>${postList}</section>${commentsThreadPanel()}</div>`) +
            bar +
            ((totals.pending || 0) && status.cron_stale ? `<div class="vkt-info vkt-info-warning">Планировщик сайта не разбирал очередь больше пяти минут. Пока открыта эта вкладка, ответы уходят сами раз в минуту; чтобы они шли и без неё, на хостинге нужна задача планировщика раз в минуту — как её добавить, написано в «Обзоре».</div>` : '') +
            `<div class="vkt-section-title"><h2>Очередь ответов</h2><span class="vkt-muted">Последний разбор очереди: ${date(status.last)}${(totals.pending || 0) ? ` · ${button('Отменить все ожидающие', 'comments-cancel-all')}` : ''}</span></div>` +
            (queue.length ? `<section class="vkt-panel vkt-table-wrap"><table class="vkt-comments-table"><thead><tr><th>Комментарий</th><th>Ответ</th><th>Сообщество</th><th>Когда</th><th>Статус</th><th></th></tr></thead><tbody>${rows}</tbody></table></section>` : empty('Ответов пока нет', 'Ответьте на комментарий или отметьте несколько и поставьте ответы в очередь.'));
    }
    function commentsBulkDialog() {
        const items = [...commentsSelected.entries()];
        if (!items.length) { if (dialog.open) dialog.close(); return; }
        const intervals = [1, 2, 3, 5, 10, 15, 30, 60];
        const ai = commentsData?.status?.ai;
        modal(`<h2>Ответы на ${num(items.length)} комм.</h2><p class="vkt-muted">Каждый ответ можно поправить. В очереди ответы расходятся по времени: в одну группу не чаще выбранного интервала.</p><form data-form="comments-bulk" class="vkt-form">` +
            `<div class="vkt-comments-bulk-tools"><label>Общий текст<textarea rows="2" maxlength="2000" data-bulk="common" placeholder="Например: Спасибо! Ответили вам в сообщениях.">${esc(commentsBulk.common)}</textarea></label>${button('Вставить всем', 'comments-bulk-fill')}</div>` +
            (commentsAiReady() ? `<div class="vkt-comments-bulk-tools">${modelPicker(commentsData?.status?.ai)}<label>Указания для нейросети<textarea rows="2" maxlength="2000" data-bulk="instruction" placeholder="Тон, что предлагать, о чём не писать…">${esc(commentsBulk.instruction)}</textarea></label>${button(`${icon('fire')} Сгенерировать`, 'comments-bulk-ai')}</div><small class="vkt-help">Ответ по каждому комментарию с учётом записи; один запрос — до ${num(Number(commentsData?.status?.generate_chunk) || 20)} комментариев и один текст из суточного лимита. Сгенерированное заменит пустые и прежние черновики нейросети.</small>${quotaNote(ai)}` : '') +
            `<div class="vkt-comments-bulk-list">${items.map(([key, item]) => `<div class="vkt-comments-bulk-item"><div><strong>${esc(item.author)}</strong><small>${esc(String(item.comment_text || '').slice(0, 240))}</small></div><textarea rows="2" maxlength="2000" data-draft="${esc(key)}" placeholder="Ответ…">${esc(commentsDrafts.get(key) || '')}</textarea><button type="button" class="vkt-media-remove" data-command="comments-unpick" data-key="${esc(key)}" aria-label="Убрать из пачки">×</button></div>`).join('')}</div>` +
            `<div class="vkt-form-row"><label>Интервал между ответами<select data-bulk="interval">${intervals.map(value => `<option value="${value}" ${Number(commentsBulk.interval) === value ? 'selected' : ''}>${value} мин.</option>`).join('')}</select></label><label>Начать<input type="datetime-local" data-bulk="start" value="${esc(commentsBulk.start)}"><small class="vkt-help">Пусто — сразу, после уже ожидающих ответов этой группы.</small></label></div>` +
            `<label class="vkt-check"><input type="checkbox" data-bulk="jitter" ${commentsBulk.jitter ? 'checked' : ''}> Случайная добавка до половины интервала — чтобы ответы не шли ровно по часам</label>` +
            `<p class="vkt-help" id="vkt-comments-bulk-progress" hidden></p>` +
            (items.length > (Number(commentsData?.status?.queue_chunk) || 25) ? `<p class="vkt-help">Комментариев много, поэтому работа пойдёт пачками: нейросеть пишет по ${num(Number(commentsData?.status?.generate_chunk) || 20)} ответов за запрос, в очередь они встают по ${num(Number(commentsData?.status?.queue_chunk) || 25)}. Сбой одной пачки не отменяет остальные. Одновременно в очереди кабинета может ждать до ${num(Number(commentsData?.status?.max_queue) || 300)} ответов.</p>` : '') +
            `<button type="submit" class="vkt-button vkt-primary">Поставить в очередь</button></form>`);
    }
    const chunks = (list, size) => { const out = []; for (let i = 0; i < list.length; i += Math.max(1, size)) out.push(list.slice(i, i + Math.max(1, size))); return out; };
    // Ход долгой работы с пачками — строкой в окне ответов, а не только всплывающим сообщением.
    const commentsProgress = text => { const note = $('#vkt-comments-bulk-progress'); if (note) { note.hidden = !text; note.textContent = text || ''; } };
    /**
     * Черновики ответов нейросетью. Большой выбор идёт пачками: каждая — свой
     * запрос, и готовые черновики сохраняются сразу. Упала одна пачка —
     * остальные продолжают; после лимита или отказа ключа идти дальше незачем.
     */
    async function commentsGenerate(keys, report = null) {
        const parts = chunks(keys, Number(commentsData?.status?.generate_chunk) || 20);
        const outcome = {filled: 0, total: keys.length, failed: 0, error: '', stopped: false};
        for (let index = 0; index < parts.length; index += 1) {
            const part = parts[index];
            if (report) report(`Нейросеть пишет ответы: пачка ${index + 1} из ${parts.length}, готово ${outcome.filled} из ${keys.length}. Не закрывайте вкладку.`);
            const items = part.map(key => commentPayload(key));
            const known = part.filter((key, at) => items[at]);
            try {
                const result = await act('comments_generate', {model: aiModel, group_id: Number(commentsGroup), instruction: commentsBulk.instruction, items: items.filter(Boolean).map(item => ({author: item.author, post: item.post_text, comment: item.comment_text}))});
                (result.replies || []).forEach(reply => {
                    const key = known[Number(reply.index)];
                    if (key && reply.text) { commentsDrafts.set(key, reply.text); commentsAiKeys.add(key); outcome.filled++; }
                });
                scheduleDrafts();
            } catch (error) {
                // Единственная пачка — обычная ошибка с подсказкой, где чинить.
                if (parts.length === 1) throw error;
                outcome.failed += part.length;
                outcome.error = error.message;
                const status = Number(error.payload?.data?.status);
                if (status === 429 || status === 401 || status === 403 || /лимит|не подключена/i.test(error.message)) { outcome.stopped = true; break; }
            }
        }
        // Остаток суточного лимита генерации пришёл вместе с состоянием вкладки.
        try { commentsData = await request('comments'); } catch {}
        return outcome;
    }
    async function commentsCommand(command, el) {
        const key = el.dataset.key || '';
        if (command==='comments-reply-open') { commentsReplyKey = key; render(); $(`[data-form="comment-reply"] textarea`)?.focus(); return; }
        if (command==='comments-reply-close') { commentsReplyKey = ''; render(); return; }
        if (command==='comments-mode') { commentsMode = el.dataset.value === 'posts' ? 'posts' : 'feed'; commentsReplyKey = ''; render(); try { await loadComments(); } catch (error) { toast(error.message, true, error.fix); } render(); return; }
        if (command==='comments-feed-filter') { commentsFeedFilter = el.dataset.value === 'all' ? 'all' : 'open'; commentsFeed = null; render(); try { await loadComments(); } catch (error) { toast(error.message, true, error.fix); } render(); return; }
        if (command==='comments-filter') { commentsOnlyOpen = el.dataset.value === 'open'; render(); return; }
        if (command==='comments-select-open') {
            if (commentsMode === 'feed') (commentsFeed?.comments || []).filter(commentOpen).forEach(comment => { const id = `${comment.post_id}_${comment.id}`; commentsSelected.set(id, commentPayload(id)); });
            else (commentsThread?.comments || []).filter(commentOpen).forEach(comment => { const id = `${commentsPost.id}_${comment.id}`; commentsSelected.set(id, commentPayload(id)); });
            render(); return;
        }
        if (command==='comments-clear') { commentsSelected.clear(); render(); return; }
        if (command==='comments-bulk') { commentsBulkDialog(); return; }
        if (command==='comments-unpick') { commentsSelected.delete(key); commentsBulkDialog(); render(); return; }
        if (command==='comments-bulk-fill') {
            if (!commentsBulk.common.trim()) { toast('Напишите общий текст.', true); return; }
            commentsSelected.forEach((item, id) => { commentsDrafts.set(id, commentsBulk.common.trim()); commentsAiKeys.delete(id); });
            commentsBulkDialog(); return;
        }
        el.disabled = true;
        try {
            if (command==='comments-post') { await openCommentsPost(el.dataset.id); return; }
            if (command==='comments-scan') {
                toast('Собираем комментарии последних записей — это до минуты…');
                const result = await act('comments_scan', {group_id: commentsGroup});
                toast(`Записей с комментариями: ${result.posts}, комментариев в ленте: ${result.comments}.${Number(result.removed) ? ` Удалённых в VK убрано: ${result.removed}.` : ''}${result.warning ? ' ' + result.warning : ''}`, !!result.warning);
                await refreshComments(); return;
            }
            if (command==='comments-more') { await openCommentsPost(commentsPost.id, (commentsThread?.comments || []).length); return; }
            if (command==='comments-posts-more') {
                const result = await act('comments_posts', {group_id: commentsGroup, offset: (commentsPosts?.posts || []).length});
                commentsPosts = {...result, posts: [...(commentsPosts?.posts || []), ...result.posts]};
                render(); return;
            }
            if (command==='comments-refresh') {
                commentsPosts = null;
                await load();
                if (commentsPost) await openCommentsPost(commentsPost.id);
                return;
            }
            if (command==='comments-ai-one') {
                const {filled} = await commentsGenerate([key]);
                if (!filled) toast('Модель не предложила ответ. Попробуйте ещё раз или выберите другую.', true);
                render(); $(`[data-form="comment-reply"] textarea`)?.focus(); return;
            }
            if (command==='comments-bulk-ai') {
                // Ручные правки не затираем: модель заполняет пустые и свои прежние черновики.
                const keys = [...commentsSelected.keys()].filter(id => !(commentsDrafts.get(id) || '').trim() || commentsAiKeys.has(id));
                if (!keys.length) { toast('У всех выбранных уже есть свой текст. Очистите те, что нужно сгенерировать.'); return; }
                toast(`Модель пишет ответы: ${keys.length}…`);
                const made = await commentsGenerate(keys, commentsProgress);
                toast(`Готово черновиков: ${made.filled} из ${keys.length}.${made.error ? ` ${made.stopped ? 'Остановились' : 'Часть пачек не прошла'}: ${made.error} Готовое сохранено — нажмите «Сгенерировать» ещё раз, чтобы дописать остальные.` : ''}`, made.filled < keys.length);
                commentsBulkDialog(); return;
            }
            if (command==='comments-run') { const result = await act('comments_run'); toast(result.message); }
            if (command==='comments-retry') { await act('comments_retry', {id: Number(el.dataset.id)}); toast('Ответ возвращён в очередь.'); }
            if (command==='comments-cancel') { await act('comments_cancel', {ids: [Number(el.dataset.id)]}); toast('Ответ отменён.'); }
            if (command==='comments-cancel-all') {
                if (!confirm('Отменить все ожидающие ответы?')) return;
                const result = await act('comments_cancel', {all: true});
                toast(`Отменено ответов: ${result.cancelled}.`);
            }
            await load();
            if (['comments-run', 'comments-cancel', 'comments-cancel-all', 'comments-retry'].includes(command)) await refreshComments();
        } catch (error) { toast(error.message, true, error.fix); }
        finally { el.disabled = false; }
    }
    async function commentsReply(form, values) {
        const key = form.dataset.key;
        const item = commentPayload(key);
        if (!item) { toast('Комментарий не найден — обновите запись.', true); return; }
        const result = await act('comments_reply', {group_id: commentsGroup, items: [{...item, origin: commentsAiKeys.has(key) ? 'ai' : 'manual', message: values.message}]});
        toast(result.status === 'sent' ? 'Ответ опубликован.' : result.status === 'failed' ? 'VK не принял ответ — причина в очереди ниже.' : result.message, result.status === 'failed');
        commentsDrafts.delete(key); commentsAiKeys.delete(key); commentsSelected.delete(key); commentsReplyKey = '';
        await load();
        await refreshComments();
    }
    async function commentsQueue() {
        const entries = [...commentsSelected.entries()];
        const ready = entries.filter(([key]) => (commentsDrafts.get(key) || '').trim());
        if (!ready.length) { toast('Ни у одного выбранного комментария нет текста ответа.', true); return; }
        if (ready.length < entries.length && !confirm(`Без текста: ${entries.length - ready.length}. Они останутся выбранными и не попадут в очередь. Продолжить?`)) return;
        const start = commentsBulk.start ? new Date(commentsBulk.start) : null;
        // В очередь — пачками: каждая встаёт следом за предыдущей. Не прошла пачка — уже поставленное
        // остаётся в очереди, а остальное — выбранным вместе с текстами, чтобы повторить.
        const parts = chunks(ready, Number(commentsData?.status?.queue_chunk) || 25);
        let created = 0, firstAt = '', lastAt = '', skipped = [], failure = '';
        for (let index = 0; index < parts.length; index += 1) {
            const part = parts[index];
            if (parts.length > 1) commentsProgress(`Ставим в очередь: пачка ${index + 1} из ${parts.length}, поставлено ${created} из ${ready.length}.`);
            let result;
            try {
                result = await act('comments_queue', {
                    group_id: commentsGroup,
                    interval: Number(commentsBulk.interval) || 3,
                    jitter: !!commentsBulk.jitter,
                    start_at: start && !isNaN(start) ? start.toISOString() : '',
                    items: part.map(([key, item]) => ({...item, origin: commentsAiKeys.has(key) ? 'ai' : 'manual', message: commentsDrafts.get(key).trim()})),
                });
            } catch (error) {
                if (!created) throw error;
                failure = error.message;
                break;
            }
            created += Number(result.created) || 0;
            firstAt = firstAt || result.first_at;
            lastAt = result.last_at || lastAt;
            const missed = result.skipped || [];
            skipped = skipped.concat(missed);
            // Не поместившееся в очередь кабинета остаётся выбранным; остальное из пачки обработано.
            const kept = new Set(missed.filter(item => item.full).map(item => Number(item.index)));
            part.forEach(([key], at) => { if (!kept.has(at)) { commentsSelected.delete(key); commentsDrafts.delete(key); commentsAiKeys.delete(key); } });
            scheduleDrafts();
            if (kept.size) { failure = missed.find(item => item.full).error; break; }
        }
        commentsProgress('');
        const left = ready.length - created - skipped.filter(item => !item.full).length;
        if (!failure) dialog.close();
        toast(`В очереди ответов: ${created}${firstAt ? `. Первый — ${date(firstAt)}, последний — ${date(lastAt)}` : ''}.${skipped.filter(item => !item.full).length ? ` Пропущено: ${skipped.filter(item => !item.full).length} — ${skipped.find(item => !item.full).error}` : ''}${failure ? ` Остановились: ${failure} Не поставлено: ${left} — они остались выбранными с текстами.` : ''}`, !!failure || skipped.length > 0);
        await load();
        await refreshComments();
        if (failure && commentsSelected.size) commentsBulkDialog();
    }
    // ——— Неполадки: что остановило работу и где это чинить ———
    const healthIssues = () => (state?.health || []).filter(issue => !issue.view || !adminViews.includes(issue.view) || isAdmin());
    function healthBanner() {
        const issues = healthIssues();
        if (!issues.length) return '';
        const shown = healthOpen ? issues : issues.slice(0, 3);
        const rows = shown.map(issue => `<div class="vkt-health-item is-${issue.level === 'error' ? 'error' : 'warning'}"><span class="vkt-health-mark" aria-hidden="true">${issue.level === 'error' ? '!' : 'i'}</span><div><strong>${esc(issue.title)}</strong><p>${esc(issue.text)}</p></div>${issue.view && names[issue.view] ? `<a class="vkt-button" href="#${esc(issue.view)}">${esc(issue.action || names[issue.view])} →</a>` : ''}</div>`).join('');
        const more = issues.length > 3 ? `<button type="button" class="vkt-link-button" data-command="health-toggle">${healthOpen ? 'Свернуть' : `Показать все · ${issues.length}`}</button>` : '';
        return `<section class="vkt-health" aria-label="Неполадки">${rows}${more}</section>`;
    }
    function render() {
        if (!state) return;
        const broken = new Set(healthIssues().filter(issue => issue.level === 'error').map(issue => issue.view));
        root.querySelectorAll('[data-nav]').forEach(el => { el.classList.toggle('has-issue', broken.has(el.dataset.nav)); });
        root.querySelectorAll('[data-nav]').forEach(el=> { const active = el.dataset.nav===view; el.classList.toggle('is-active',active); if(active) el.setAttribute('aria-current','page'); else el.removeAttribute('aria-current'); });
        $('#vkt-breadcrumb').textContent = names[view];
        // Точка у пункта меню: ключи этого раздела на месте или нет.
        [['reading', readyReading(state.settings)], ['posting', readyPosting(state.settings)]].forEach(([name, ready]) => {
            const dot = $(`#vkt-${name}-dot`);
            if (dot) { dot.classList.toggle('is-ready', ready); dot.title = ready ? 'Ключи на месте' : 'Ключи ещё не настроены'; }
        });
        if (adminViews.includes(view) && !isAdmin()) view = 'overview';
        if (view === 'users' && !usersData) { content.innerHTML = heading('Пользователи', 'Загружаем список…') + '<div class="vkt-loading">Загружаем…</div>'; return; }
        content.innerHTML = healthBanner() + ({overview,discover,posts,communities,groups,publishing,series,comments,videos,products,sources,reading,posting,attachments,flux,users,api,collector,logs,settings})[view]();
        applyFieldDrafts();
        scheduleDrafts();
    }
    function modal(html) {
        $('#vkt-dialog-content').innerHTML = html;
        if (!dialog.open) dialog.showModal();
    }
    function publishingReview(id) {
        const post = (publishingData?.posts || []).find(item => String(item.id) === String(id));
        if (!post) return;
        const targets = (post.deliveries || []).map(delivery => `<li>${esc(delivery.name || delivery.screen_name || `club${delivery.group_id}`)}</li>`).join('');
        modal(`<h2>Проверка публикации</h2><p class="vkt-muted">Проверьте весь текст, вложения, адресатов и время. До подтверждения cron эту запись не отправит.</p><h3>Текст</h3><div class="vkt-review-copy">${esc(post.message || 'Без текста')}</div><h3>Вложения</h3><p class="vkt-code-input">${esc(post.attachments || 'Нет')}</p>${(post.media_items || []).length ? `<h3>Файлы с сервера</h3><div class="vkt-media-chips">${post.media_items.map(item => `<figure class="vkt-media-chip">${safeUrl(item.thumbnail || '') ? `<img src="${safeUrl(item.thumbnail)}" alt="" loading="lazy">` : `<span class="vkt-media-kind">${icon('play')}</span>`}<figcaption><strong>${esc(item.title || item.name)}</strong><small>${item.type === 'image' ? 'Изображение' : 'Видео'} · ${fileSize(item.size)}</small></figcaption></figure>`).join('')}</div>` : ''}<h3>Сообщества</h3><ul>${targets}</ul><div class="vkt-detail-stats"><span>Публикация<strong>${date(post.scheduled_at)}</strong></span><span>Подпись<strong>${Number(post.signed) ? 'Да' : 'Нет'}</strong></span><span>Комментарии<strong>${Number(post.close_comments) ? 'Закрыты' : 'Открыты'}</strong></span></div><div class="vkt-form-actions">${button(`${icon('check')} Подтвердить и отправить`, 'publishing-approve', `data-id="${Number(post.id)}"`, true)}${button('Отменить черновик', 'publishing-cancel', `data-id="${Number(post.id)}"`)}</div>`);
    }
    function videoDetail(id) {
        const v = state.videos.find(v=>String(v.id)===String(id));
        if (!v) return;
        modal(`<h2>${esc(v.title)}</h2><p class="vkt-muted">Последний замер: ${date(v.measured_at)}</p><div class="vkt-detail-stats"><span>Просмотры<strong>${num(v.views)}</strong></span><span>Лайки<strong>${num(v.likes)}</strong></span><span>Комментарии<strong>${num(v.comments)}</strong></span></div><h3>Связанные товары</h3><div class="vkt-linked-products">${v.products.length?v.products.map(p=>`<div>${esc(p.title)}${button('Отвязать','unlink',`data-id="${v.id}" data-product="${p.id}"`)}</div>`).join(''):'<p class="vkt-muted">Пока нет связей.</p>'}</div>${state.products.length?`<form data-form="link" class="vkt-form"><input type="hidden" name="video_id" value="${v.id}"><label>Добавить товар<select name="product_id">${state.products.map(p=>`<option value="${p.id}">${esc(p.title)}</option>`).join('')}</select></label><button class="vkt-button vkt-primary">Привязать товар</button></form>`:'<p>Сначала добавьте товар в разделе «Товары».</p>'}<div class="vkt-form-actions">${button('Обновить через очередь','enqueue',`data-id="${v.id}"`)}${button('Удалить ролик','delete',`data-id="${v.id}" data-entity="videos"`)}</div>`);
    }
    async function history(id) {
        modal('<h2>Динамика просмотров</h2><p>Загружаем замеры…</p>');
        const rows = await request(`history/${id}`);
        const points = rows.filter(r=>r.views!==null).map(r=>({x:new Date(r.measured_at.replace(' ','T')+'Z').getTime(), y:Number(r.views)}));
        let chart = '<p class="vkt-muted">Для графика нужны хотя бы два замера с просмотрами.</p>';
        if (points.length>=2) {
            const min = Math.min(...points.map(p=>p.y)), max = Math.max(...points.map(p=>p.y)), xMin = points[0].x, xMax = points.at(-1).x;
            const poly = points.map(p=>`${35+(p.x-xMin)/(xMax-xMin||1)*650},${180-(p.y-min)/(max-min||1)*145}`).join(' ');
            chart = `<div class="vkt-chart-labels"><span>${num(max)} просмотров</span><span>${date(rows[0].measured_at)} — ${date(rows.at(-1).measured_at)}</span></div><svg class="vkt-history-chart" viewBox="0 0 720 215" role="img" aria-label="График просмотров по времени"><path d="M35 35H685M35 107H685M35 180H685" stroke="#e8edf4" fill="none"/><polyline points="${poly}" fill="none" stroke="#3478f6" stroke-width="3" stroke-linejoin="round"/><text x="35" y="207" font-size="11" fill="#8792a2">${num(min)}</text></svg>`;
        }
        if(!dialog.open) return;
        modal(`<h2>Динамика просмотров</h2><p class="vkt-muted">Последние 120 замеров. Время — в часовом поясе вашего браузера.</p>${chart}<div class="vkt-table-wrap vkt-history-table"><table><thead><tr><th>Замер</th><th>Просмотры</th><th>Лайки</th><th>Комментарии</th></tr></thead><tbody>${[...rows].reverse().map(r=>`<tr><td>${date(r.measured_at)}</td><td>${num(r.views)}</td><td>${num(r.likes)}</td><td>${num(r.comments)}</td></tr>`).join('')}</tbody></table></div>`);
    }
    // ——— График динамики поста ———
    const historyRanges = {'24h': ['24 часа', 1], '3d': ['3 дня', 3], '7d': ['7 дней', 7], days: ['По дням', 0], all: ['За всё время', 0]};
    let historyPost = null, historyRows = null, historyRange = '24h';
    function historyPoints() {
        const rows = (historyRows || []).filter(row => row.views !== null && row.views !== undefined);
        let points = rows.map(row => ({x: parseDate(row.measured_at).getTime(), y: Number(row.views), row}));
        const days = historyRanges[historyRange]?.[1] || 0;
        if (days) {
            const from = Date.now() - days * 86400000;
            points = points.filter(point => point.x >= from);
        }
        if (historyRange === 'days') {
            // По дням: оставляем последний замер каждых суток по времени браузера.
            const byDay = new Map();
            points.forEach(point => byDay.set(new Date(point.x).toDateString(), point));
            points = [...byDay.values()];
        }
        return points;
    }
    function chartSvg(points) {
        if (points.length < 2) return `<p class="vkt-muted">В этом диапазоне меньше двух замеров с просмотрами. Выберите период шире или дождитесь следующего обхода.</p>`;
        const width = 760, height = 240, left = 54, right = 16, top = 18, bottom = 34;
        const ys = points.map(point => point.y);
        const min = Math.min(...ys), max = Math.max(...ys);
        const xMin = points[0].x, xMax = points.at(-1).x;
        const px = point => left + (point.x - xMin) / (xMax - xMin || 1) * (width - left - right);
        const py = point => height - bottom - (point.y - min) / (max - min || 1) * (height - top - bottom);
        const line = points.map(point => `${px(point).toFixed(1)},${py(point).toFixed(1)}`).join(' ');
        const area = `${left},${height - bottom} ${line} ${px(points.at(-1)).toFixed(1)},${height - bottom}`;
        const dots = points.map(point => `<circle cx="${px(point).toFixed(1)}" cy="${py(point).toFixed(1)}" r="3"><title>${date(point.row.measured_at)} · ${num(point.y)} просмотров</title></circle>`).join('');
        const ticks = [max, Math.round((max + min) / 2), min].map((value, index) => `<text x="8" y="${top + 4 + index * (height - top - bottom) / 2}" font-size="11" fill="#8792a2">${short(value)}</text>`).join('');
        return `<svg class="vkt-history-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="График просмотров по времени"><path d="M${left} ${top}H${width - right}M${left} ${(top + height - bottom) / 2}H${width - right}M${left} ${height - bottom}H${width - right}" stroke="#e8edf4" fill="none"/><polygon points="${area}" fill="rgba(52,120,246,.10)"/><polyline points="${line}" fill="none" stroke="#3478f6" stroke-width="2.5" stroke-linejoin="round"/><g fill="#3478f6">${dots}</g>${ticks}<text x="${left}" y="${height - 8}" font-size="11" fill="#8792a2">${date(points[0].row.measured_at)}</text><text x="${width - right}" y="${height - 8}" font-size="11" fill="#8792a2" text-anchor="end">${date(points.at(-1).row.measured_at)}</text></svg>`;
    }
    function renderHistory() {
        const post = historyPost;
        const points = historyPoints();
        const last = points.at(-1);
        const previous = points.at(-2);
        const hourly = last && previous ? (last.y - previous.y) / Math.max(0.0166, (last.x - previous.x) / 3600000) : null;
        const dayAgo = points.find(point => point.x >= (last ? last.x : 0) - 86400000);
        const daily = last && dayAgo && dayAgo !== last ? last.y - dayAgo.y : null;
        const tabs = Object.entries(historyRanges).map(([key, [label]]) => `<button type="button" class="vkt-chip-button ${historyRange === key ? 'is-active' : ''}" data-command="history-range" data-value="${key}">${label}</button>`).join('');
        modal(`<h2>Динамика просмотров${post?.text ? ' · ' + esc(String(post.text).slice(0, 70)) : ''}</h2>
            <div class="vkt-chips vkt-history-tabs">${tabs}</div>
            <div class="vkt-history-summary"><span>Последний замер<strong>${last ? date(last.row.measured_at) : '—'}</strong></span><span>Просмотры<strong>${last ? num(last.y) : '—'}</strong></span><span>Прирост за час<strong class="vkt-growth">${hourly === null ? '—' : signed(Math.round(hourly))}</strong></span><span>Прирост за 24 часа<strong class="vkt-growth">${daily === null ? '—' : signed(daily)}</strong></span></div>
            ${chartSvg(points)}
            <p class="vkt-muted">Точки — фактические замеры, время в часовом поясе браузера. Замеры старше 48 часов прорежены до одного в час, старше 7 дней — до одного в сутки.</p>
            <div class="vkt-table-wrap vkt-history-table"><table><thead><tr><th>Замер</th><th>Просмотры</th><th>Лайки</th><th>Репосты</th><th>Комментарии</th></tr></thead><tbody>${[...points].reverse().slice(0, 60).map(point => `<tr><td>${date(point.row.measured_at)}</td><td>${num(point.row.views)}</td><td>${num(point.row.likes)}</td><td>${num(point.row.reposts)}</td><td>${num(point.row.comments)}</td></tr>`).join('')}</tbody></table></div>`);
    }
    async function postHistory(id) {
        historyPost = (postsData?.posts || []).find(post => String(post.id) === String(id)) || null;
        historyRange = '24h';
        modal('<h2>Динамика просмотров</h2><p>Загружаем замеры…</p>');
        historyRows = await request(`post-history/${Number(id)}`);
        if (!dialog.open) return;
        renderHistory();
    }
    function postDetail(id) {
        const post = (postsData?.posts || []).find(item => String(item.id) === String(id));
        if (!post) return;
        const windows = [['g1', 'Сутки'], ['g2', '2 дня'], ['g3', '3 дня'], ['g4', '4 дня'], ['g5', '5 дней'], ['g6', '6 дней'], ['g7', '7 дней'], ['g30', '30 дней']];
        modal(`<h2>Пост сообщества ${esc(post.source_title || post.source_value || post.owner_id)}</h2>
            <p class="vkt-muted">${date(post.published_at)} · ${ago(post.published_at)} · последний замер ${date(post.measured_at)}</p>
            <p class="vkt-post-full">${esc(post.text || 'Пост без текста')}</p>
            ${postProduct(post)}
            <div class="vkt-detail-stats"><span>Просмотры<strong>${num(post.views)}</strong></span><span>Лайки<strong>${num(post.likes)}</strong></span><span>Репосты<strong>${num(post.reposts)}</strong></span><span>Комментарии<strong>${num(post.comments)}</strong></span><span>ERR<strong>${post.err === null ? '—' : decimal(post.err, 2) + '%'}</strong></span><span>Вирусность<strong>${post.viral === null ? '—' : decimal(post.viral, 2) + '×'}</strong></span></div>
            <h3>Прирост просмотров по окнам</h3>
            <div class="vkt-window-grid">${windows.map(([key, label]) => `<span>${label}<strong>${post[key] === null || post[key] === undefined ? '—' : signed(post[key])}</strong></span>`).join('')}</div>
            <p class="vkt-help">Пустое значение означает, что подходящего опорного замера в этом окне ещё нет — сбор начался позже или стоял на паузе.</p>
            <div class="vkt-form-actions"><a class="vkt-button" href="${postUrl(post)}" target="_blank" rel="noopener noreferrer">Открыть пост ↗</a>${button(`${icon('chart')} График`, 'post-history', `data-id="${Number(post.id)}"`)}${button('Удалить пост', 'delete', `data-id="${Number(post.id)}" data-entity="posts"`)}</div>`);
    }
    async function runSearch() {
        searchResults = await act('search',{q:searchQuery, short:searchShort, offset:searchOffset});
        await load(false);
        render();
    }
    root.addEventListener('click', async event => {
        const el = event.target.closest('[data-command]');
        if (!el || el.disabled) return;
        const command = el.dataset.command;
        if (command==='menu') { const open = root.classList.toggle('menu-open'); el.setAttribute('aria-expanded',String(open)); return; }
        if (command==='close') { dialog.close(); return; }
        if (command==='add-video') { modal('<h2>Добавить ролик</h2><p class="vkt-muted">Прямая ссылка video/clip или ID owner_id_video_id. Ролик ищется среди последних 100 постов стены владельца.</p><form data-form="video" class="vkt-form"><label>Ссылка или ID<input name="video" placeholder="https://vk.com/video-123_456" required></label><button class="vkt-button vkt-primary">Добавить в мониторинг</button></form>'); return; }
        if (command==='add-product') { modal('<h2>Новый товар</h2><form data-form="product" class="vkt-form"><label>Название<input name="title" required maxlength="255" placeholder="Например, настольная лампа"></label><label>Ссылка на товар<input name="url" type="url" placeholder="https://…"></label><button class="vkt-button vkt-primary">Добавить товар</button></form>'); return; }
        if (command==='import-sources') { modal('<h2>Импорт сообществ</h2><p class="vkt-muted">По одному в строке. Подойдут ссылки vk.com/team, короткие имена и числовые ID — вперемешку. За раз до 200 адресов.</p><form data-form="import" class="vkt-form"><label>Список<textarea name="list" class="vkt-code-input" rows="12" required maxlength="20000" placeholder="https://vk.com/team&#10;all_about_nba&#10;-22822305&#10;club1"></textarea></label><button class="vkt-button vkt-primary">Импортировать</button></form>'); return; }
        if (command==='export-sources') { const list=state.sources.map(x=>x.value).join('\n'); modal(`<h2>Список источников</h2><p class="vkt-muted">Скопируйте и сохраните — этот же список можно вставить обратно через импорт.</p><textarea class="vkt-code-input" rows="12" readonly>${esc(list)}</textarea>`); return; }
        if (command==='add-source') { modal('<h2>Новый источник</h2><form data-form="source" class="vkt-form"><label>Тип<select name="kind"><option value="domain">Короткое имя сообщества</option><option value="owner">Числовой ID</option></select></label><label>Сообщество<input name="value" required maxlength="200" placeholder="team или -22822305"></label><p class="vkt-help">Короткое имя — часть адреса: для vk.com/team это team. Числовой ID сообщества пишется со знаком минус, ID пользователя — положительный.</p><button class="vkt-button vkt-primary">Сохранить источник</button></form>'); return; }
        if (command==='publishing-review') { publishingReview(el.dataset.id); return; }
        if (command==='health-toggle') { healthOpen = !healthOpen; render(); return; }
        if (command==='group-settings' || command==='callback-setup' || command==='callback-forget') {
            el.disabled = true;
            try {
                if (command==='callback-setup') {
                    toast('Регистрируем сайт в группе VK…');
                    const info = await act('callback_setup', {group_id: Number(el.dataset.id)});
                    toast(info.status === 'ok' ? 'Callback подключён и подтверждён VK.' : `Сервер зарегистрирован, статус VK: ${callbackStatuses[info.status]?.[0] || info.status}.`, info.status === 'failed');
                }
                if (command==='callback-forget') { await act('callback_forget', {group_id: Number(el.dataset.id)}); toast('Callback отключён на сайте. Сервер в самой группе VK можно удалить там же.'); }
                await groupSettings(Number(el.dataset.id));
                if (command !== 'group-settings') { commentsData = null; if (view === 'comments') await loadComments(); }
            } catch (error) { toast(error.message, true, error.fix); }
            finally { el.disabled = false; }
            return;
        }
        if (command==='community-key-add') { modal(`<h2>Добавить группу по ключу</h2><p class="vkt-muted">${esc(communityKeyHelp)}</p>${communityKeyForm()}`); return; }
        if (command.startsWith('comments-')) { await commentsCommand(command, el); return; }
        if (command==='probe-matrix') {
            el.disabled = true;
            try { tokenReport(await act('probe_matrix')); }
            catch (error) { toast(error.message, true, error.fix); }
            finally { el.disabled = false; }
            return;
        }
        if (command==='token-probe' || command==='token-forget') {
            el.disabled = true;
            try {
                if (command === 'token-forget') {
                    if (!confirm('Удалить этот ключ из настроек?')) return;
                    await act('token_forget', {slot: el.dataset.slot});
                    toast('Ключ удалён.');
                } else {
                    tokenReport(await act('token_probe', {slot: el.dataset.slot}));
                }
                await load();
            } catch (error) { toast(error.message, true, error.fix); }
            finally { el.disabled = false; }
            return;
        }
        if (command==='oauth-open') {
            const field = $('[data-form="oauth-app"] input[name="app_id"]');
            // Участник поля ID не видит: приложение сайта одно на всех.
            const appId = Number(field?.value) || Number(state.settings.oauth?.app_id) || 0;
            if (!appId) { toast('Укажите ID приложения.', true); return; }
            // Вкладку открываем синхронно по клику: после await браузер считает
            // вызов не пользовательским и молча блокирует всплывающее окно.
            const win = window.open('', '_blank');
            if (win) win.opener = null;
            el.disabled = true;
            try {
                const checked = await act('oauth_check', isAdmin() ? {app_id: appId} : {});
                if (win) { win.location.href = checked.authorize_url; toast('Во вкладке VK нажмите «Разрешить», скопируйте адрес страницы и вставьте его в шаг 3.'); }
                else { toast('Браузер заблокировал вкладку — откройте ссылку рядом с кнопкой.', true); }
                await load();
            } catch (error) { if (win) win.close(); toast(error.message, true, error.fix); }
            finally { el.disabled = false; }
            return;
        }
        if (command==='vkid-implicit') {
            const app = Number(state.settings.vkid?.client_id) || 0;
            if (!app) { toast('Сначала укажите ID приложения ниже и сохраните его.', true); return; }
            const url = `https://oauth.vk.com/authorize?client_id=${app}&display=page&redirect_uri=https://oauth.vk.com/blank.html&scope=wall,photos,groups,video&response_type=token&v=5.199`;
            window.open(url, '_blank', 'noopener');
            toast('Разрешите доступ, затем скопируйте адрес из браузера целиком в поле «Access token».');
            return;
        }
        if (command==='media-remove') {
            // Медиатека одна на конструктор, слоты серии и стенд, поэтому цель
            // выбора хранится отдельно: иначе файл уходит не туда.
            const inDialog = !!el.closest('#vkt-dialog');
            if (el.closest('#vkt-flux-media')) {
                fluxMedia = fluxMedia.filter(item => Number(item.id) !== Number(el.dataset.id));
                renderFluxMedia();
                return;
            }
            if (inDialog && seriesEdit && $('#vkt-dialog-content [data-form="series-post"]')) {
                captureSeriesDialog();
                seriesEdit.draft.media = seriesEdit.draft.media.filter(item => Number(item.id) !== Number(el.dataset.id));
                seriesPostDialog(0, false);
                return;
            }
            if (inDialog && null !== seriesSlotTarget && seriesSlots[seriesSlotTarget]) {
                captureSeriesDialog();
                const slot = seriesSlots[seriesSlotTarget];
                slot.media = slot.media.filter(item => Number(item.id) !== Number(el.dataset.id));
                seriesSlotDialog(seriesSlotTarget);
                return;
            }
            composerMedia = composerMedia.filter(item => Number(item.id) !== Number(el.dataset.id));
            renderComposerMedia();
            return;
        }
        if (command==='media-pick') {
            const item = mediaLibraryItems.find(media => Number(media.id) === Number(el.dataset.id));
            if (mediaTarget === 'flux') {
                if (addFluxMedia(item)) dialog.close();
                return;
            }
            if (mediaTarget === 'series-post') {
                if (addSeriesPostMedia(item)) seriesPostDialog(0, false);
                return;
            }
            if (null !== seriesSlotTarget) {
                if (addSeriesMedia(seriesSlotTarget, item)) seriesSlotDialog(seriesSlotTarget);
                return;
            }
            if (addComposerMedia(item)) dialog.close();
            return;
        }
        if (command==='series-slot') { seriesSlotDialog(Number(el.dataset.index)); return; }
        if (command==='series-media') { captureSeriesDialog(); mediaTarget = 'series'; seriesSlotTarget = Number(el.dataset.index); await mediaLibrary(); return; }
        if (command==='series-post-media') { captureSeriesDialog(); mediaTarget = 'series-post'; seriesSlotTarget = null; await mediaLibrary(); return; }
        if (command==='series-slot-image' || command==='series-post-image') { await seriesDialogImage(el); return; }
        if (command==='series-shop') { await seriesDialogShop(el); return; }
        if (command==='series-slot-add') {
            // Отдельная запись вне сетки: завтра в этот же час, дальше время правится в окне.
            const when = new Date(Date.now() + 86400000);
            when.setMinutes(0, 0, 0);
            while (seriesSlots.some(slot => slot.at === localStamp(when))) when.setHours(when.getHours() + 1);
            const added = {at: localStamp(when), message: '', attachments: '', media: []};
            seriesSlots.push(added);
            seriesSlots.sort((a, b) => a.at < b.at ? -1 : 1);
            render();
            seriesSlotDialog(seriesSlots.indexOf(added));
            return;
        }
        if (command==='series-post') {
            try { await seriesPostDialog(el.dataset.id); } catch (error) { if (dialog.open) dialog.close(); toast(error.message, true, error.fix); }
            return;
        }
        if (command==='series-slot-remove') {
            seriesSlots.splice(Number(el.dataset.index), 1);
            seriesSlotTarget = null;
            dialog.close();
            render();
            return;
        }
        if (command==='series-open') {
            seriesGroup = seriesGroupOf(el.dataset.id) || seriesGroup;
            seriesTarget = el.dataset.id;
            render();
            content.querySelector('.vkt-series-layout')?.scrollIntoView({behavior: 'smooth', block: 'start'});
            return;
        }
        if (command==='group-open') {
            groupDraftsSave();
            groupOpen = Number(el.dataset.id); groupDetail = null;
            groupDraftsLoad(groupOpen);
            if (dialog.open) dialog.close();
            if (view !== 'groups') { location.hash = '#groups'; return; }
            render();
            try { groupDetail = await act('group_detail', {id: groupOpen}); } catch (error) { groupOpen = 0; toast(error.message, true, error.fix); }
            render();
            content.scrollIntoView({block: 'start'});
            return;
        }
        if (command==='group-close') { groupDraftsSave(); groupOpen = 0; groupDetail = null; groupDraftsLoad(0); render(); return; }
        if (command==='user-limits') {
            const user = (usersData || []).find(item => Number(item.id) === Number(el.dataset.id));
            if (!user) return;
            const limits = user.limits || {};
            const field = (key, label, hint) => `<label>${label}<input type="number" name="${key}" min="0" value="${limits[key]?.own ?? ''}" placeholder="общий: ${num(limits[key]?.limit ?? 0)}"><small class="vkt-help">${hint}</small></label>`;
            modal(`<h2>Лимиты · ${esc(user.name)}</h2><p class="vkt-muted">Личные значения этого кабинета. Пустое поле — действует общий лимит сайта. Сегодня израсходовано: текстов ${num(limits.text?.used || 0)}, картинок и видео ${num(limits.media?.used || 0)}.</p>
                <form data-form="user-limits" class="vkt-form"><input type="hidden" name="id" value="${Number(user.id)}">
                    <div class="vkt-form-row">${field('text', 'Текстов в сутки', 'Посты, серии, новости и ответы на комментарии: одна генерация — один текст; ответы пишутся пачками по 20, каждая пачка — один текст.')}${field('media', 'Картинок и видео в сутки', 'Фото к записям и ролики.')}</div>
                    <div class="vkt-form-row">${field('replies_queue', 'Ответов на комментарии в очереди', 'Сколько ответов может одновременно ждать отправки. Выбрать можно больше — лишнее останется выбранным до освобождения очереди.')}${field('sources', 'Источников', 'Сколько чужих сообществ можно отслеживать.')}</div>
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">${icon('check')} Сохранить лимиты</button></div>
                </form>`);
            return;
        }
        if (command==='group-news-drop') { groupNewsResult?.posts.splice(Number(el.dataset.index), 1); render(); return; }
        if (command==='group-news-series') {
            const posts = groupNewsResult?.posts || [];
            if (!posts.length) return;
            seriesGroup = groupNewsResult.groupId; seriesTarget = '';
            // Сетки ещё нет — строим по текущим настройкам серии, как кнопкой «Построить сетку».
            if (!seriesSlots.length) seriesSlots = seriesPlan(seriesSetup);
            const free = seriesSlots.filter(slot => !slot.message.trim());
            const placed = posts.splice(0, free.length);
            placed.forEach((post, index) => { free[index].message = post.text; });
            if (!placed.length) { toast(seriesSlots.length ? 'В сетке серии нет свободных слотов. Добавьте время или период в «Серии» и повторите.' : 'Сетка серии не строится: проверьте дни и время в «Серии».', true); render(); return; }
            toast(posts.length ? `Разложено ${placed.length} из ${placed.length + posts.length}: слотов не хватило. Добавьте время или период и разложите остаток из карточки группы.` : `Записи разложены по сетке: ${placed.length}. Проверьте и поставьте в очередь.`, posts.length > 0);
            if (!posts.length) groupNewsResult = null;
            location.hash = '#series';
            return;
        }
        if (command==='group-material-new') { groupMaterialEdit = 'new'; groupMaterialDraft = {title: '', text: ''}; render(); return; }
        if (command==='group-material-cancel') { groupMaterialEdit = null; groupMaterialDraft = null; render(); return; }
        if (command==='group-material-edit' || command==='group-material-view') {
            const item = (groupDetail?.materials || []).find(entry => entry.id === el.dataset.id);
            if (!item) return;
            if (command==='group-material-view') { modal(`<h2>${esc(item.title)}</h2><p class="vkt-muted">Файл плагина materials/${esc(item.file)}.md — правится в плагине и обновляется сразу у всех групп.</p><textarea class="vkt-code-input" rows="18" readonly>${esc(item.text)}</textarea>`); return; }
            groupMaterialEdit = item.id; groupMaterialDraft = {title: item.title, text: item.text};
            render();
            return;
        }
        if (command==='groups-hidden-toggle') { groupShowHidden = !groupShowHidden; return; }
        if (command==='group-series') { seriesGroup = Number(el.dataset.id); seriesTarget = ''; location.hash = '#series'; return; }
        if (command==='series-best-times') {
            // Время подставляет кнопка — набранное в форме сетки ей уступает.
            delete fieldDrafts['series-setup'];
            const bound = (groupsData?.groups || []).find(group => Number(group.id) === Number(seriesGroup));
            const input = $('[data-form="series-setup"] input[name="times"]');
            if (bound && input) { input.value = bestTimes(bound.best_times); seriesSetup.times = input.value; toast('Время подставлено — постройте сетку.'); }
            return;
        }
        if (command==='series-images-stop') { if (seriesImageRun) { seriesImageRun.stop = true; el.disabled = true; } return; }
        if (command==='flux-media') { mediaTarget = 'flux'; seriesSlotTarget = null; await mediaLibrary(); return; }
        if (command==='series-apply-media') {
            const source = seriesSlots[Number(el.dataset.index)];
            if (!source) return;
            seriesSlots.forEach(slot => { slot.media = source.media.slice(); });
            seriesSlotTarget = null;
            dialog.close();
            render();
            toast(`Файлы применены ко всем слотам: ${seriesSlots.length}.`);
            return;
        }
        if (command==='series-slot-clear') {
            const slot = seriesSlots[Number(el.dataset.index)];
            if (!slot) return;
            slot.message = ''; slot.attachments = ''; slot.media = [];
            seriesSlotTarget = null;
            dialog.close();
            render();
            return;
        }
        if (command==='series-clear') {
            if (!confirm('Убрать всю сетку вместе с написанными текстами?')) return;
            seriesSlots = [];
            render();
            return;
        }
        if (command==='series-queue') { await seriesQueue(el); return; }
        if (command==='ai-text' || command==='ai-image' || command==='ai-video') { aiDialog(command.slice(3)); return; }
        if (command==='video-detail') { videoDetail(el.dataset.id); return; }
        if (command==='post-detail') { postDetail(el.dataset.id); return; }
        if (command==='posts-filters') { postsFiltersOpen = !postsFiltersOpen; render(); return; }
        if (command==='posts-mode') { postsMode = el.dataset.value === 'table' ? 'table' : 'grid'; render(); return; }
        if (command==='history-range') { historyRange = el.dataset.value in historyRanges ? el.dataset.value : '24h'; renderHistory(); return; }
        if (command==='communities-sort') { communitiesSort = el.dataset.value; render(); return; }
        el.disabled = true;
        try {
            if (command==='media-library') { mediaTarget = 'composer'; seriesSlotTarget = null; await mediaLibrary(); return; }
            if (command==='flux-credits') {
                const result = await act('flux_credits');
                toast(`На счету BFL: ${decimal(result.credits, 2)} кредита.`);
                return;
            }
            if (command==='history') { await history(el.dataset.id); return; }
            if (command==='post-history') { await postHistory(el.dataset.id); return; }
            if (command==='community-posts') {
                postsSource = Number(el.dataset.id); postsPage = 1;
                if (dialog.open) dialog.close();
                if (view === 'posts') { await load(); } else { location.hash = '#posts'; }
                return;
            }
            if (command==='resolve-link') {
                toast('Читаем страницу товара…');
                const result = await act('resolve_link', {id: Number(el.dataset.id), link_id: Number(el.dataset.link) || 0});
                toast(result.link?.title ? `Товар: ${result.link.title}` : (result.link?.message || 'Страница прочитана.'), !result.link?.title);
            }
            if (command==='posts-all') { postsSource = 0; postsPage = 1; }
            if (command==='posts-period') { postsFilters = {...postsFilters, period: el.dataset.value}; postsPage = 1; }
            if (command==='posts-filters-clear') { postsFilters = {}; postsSource = 0; postsPage = 1; }
            if (command==='posts-next') postsPage++;
            if (command==='posts-prev') postsPage = Math.max(1, postsPage - 1);
            if (command==='save-result') { await act('save_video',{video:el.dataset.id}); toast('Ролик сохранён. Первый замер записан.'); el.textContent='Сохранено'; await load(false); return; }
            if (command==='collect') { toast('Сбор запущен, ожидаем ответы VK…'); const result=await act('collect'); toast(result.message); }
            if (command==='groups-reload') { toast('Обновляем свои группы…'); }
            if (command==='group-hide') {
                const hide = el.dataset.hidden === '1';
                await act('group_hide', {id: Number(el.dataset.id), hidden: hide});
                if (hide && Number(el.dataset.id) === groupOpen) { groupOpen = 0; groupDetail = null; }
                toast(hide ? 'Группа скрыта и больше не собирается. История сохранена.' : 'Группа снова в списке и собирается.');
            }
            if (command==='group-stats') {
                const stats = await act('group_stats', {id: Number(el.dataset.id)});
                toast(stats?.error ? stats.error : 'Охваты обновлены.', !!stats?.error);
            }
            if (command==='group-news-check' || command==='group-news-collect') {
                const id = groupOpen;
                // Проверяем и собираем по тому, что на экране: несохранённые правки сначала сохраняются.
                if (groupNewsDraft) { await act('group_news_save', {id, news: groupNewsPayload(groupDetail)}); groupNewsDraft = null; }
                if (command==='group-news-check') {
                    toast('Читаем источники…');
                    groupNewsCheck = {groupId: id, ...(await act('group_news_check', {id}))};
                    toast(`Источники проверены: свежих новостей ${groupNewsCheck.fresh}.`);
                } else {
                    toast('Читаем источники и отбираем новости — это может занять минуту…');
                    const found = await act('group_news_collect', {id, model: aiModel});
                    groupNewsResult = {groupId: id, posts: found.posts || [], mode: found.mode, offered: found.offered};
                    groupNewsCheck = {groupId: id, sources: found.sources || []};
                    toast(found.mode === 'digest' ? 'Дайджест готов — проверьте его.' : `Готово записей: ${groupNewsResult.posts.length}. Проверьте их.`);
                }
            }
            if (command==='group-news-reset') {
                if (!window.confirm('Забыть использованные новости? Следующий сбор может предложить их снова.')) return;
                await act('group_news_reset', {id: groupOpen});
                toast('Список использованных новостей очищен.');
            }
            if (command==='group-material-add') {
                await act('group_material_save', {id: groupOpen, material: {file: el.dataset.file, posts: true, replies: false}});
                toast('Материал подключён: посты и серии этой группы пишутся по нему.');
            }
            if (command==='group-material-delete') {
                if (!window.confirm('Убрать материал из базы ведения группы? В генерации он больше не учитывается.')) return;
                await act('group_material_delete', {id: groupOpen, material_id: el.dataset.id});
                if (groupMaterialEdit === el.dataset.id) { groupMaterialEdit = null; groupMaterialDraft = null; }
                toast('Материал убран.');
            }
            if (command==='group-passport-draft') {
                toast('Нейросеть читает посты группы…');
                const draft = await act('group_passport_draft', {id: Number(el.dataset.id), model: aiModel});
                groupPassportDraft = draft.passport;
                toast('Черновик паспорта готов — проверьте и сохраните.');
            }
            if (command==='series-post-delete') {
                if (!confirm('Убрать запись из серии? Она не уйдёт в VK; остальные записи серии останутся.')) return;
                await act('publishing_cancel', {id: Number(el.dataset.id)});
                seriesEdit = null;
                if (dialog.open) dialog.close();
                toast('Запись убрана из серии.');
            }
            if (command==='series-cancel') {
                if (!confirm('Отменить все ещё не отправленные записи этой серии? Опубликованные останутся.')) return;
                const result = await act('series_cancel', {series_id: el.dataset.id});
                toast(`Отменено записей: ${result.cancelled}.`);
            }
            if (command==='ai-model-default') { await act('ai_model_default', {model: el.dataset.id}); toast('Модель по умолчанию сменена.'); }
            if (command==='ai-model-check') { const result = await act('ai_model_check', {model: el.dataset.id}); toast(`Модель отвечает: «${result.answer}».`); return; }
            if (command==='ai-model-delete') {
                if (!confirm('Убрать эту модель? Её ключ будет удалён.')) return;
                await act('ai_model_delete', {model: el.dataset.id});
                toast('Модель убрана.');
            }
            if (command==='community-key-forget') {
                if (!confirm('Убрать ключ этой группы? Публиковать в неё и отвечать в ней будет нечем, пока не добавите ключ снова.')) return;
                await act('community_key_forget', {group_id: Number(el.dataset.id)});
                toast('Ключ группы убран.');
            }
            if (command==='publishing-sync') { const result=await act('publishing_sync'); toast(`Синхронизировано своих групп: ${result.synced}.${result.warning ? ' ' + result.warning : ''}`, !!result.warning, result.fix); }
            if (command==='user-status') {
                const labels = {active: 'Кабинет открыт.', blocked: 'Кабинет закрыт, сессии пользователя завершены.'};
                if (el.dataset.status === 'blocked' && !window.confirm('Закрыть кабинет? Пользователь выйдет со всех устройств, его записи перестанут публиковаться.')) return;
                await act('user_status', {id: Number(el.dataset.id), status: el.dataset.status});
                toast(labels[el.dataset.status] || 'Статус изменён.');
            }
            if (command==='community-check') {
                const result=await act('community_check');
                const permissions=(result.permissions || []).join(', ') || 'не перечислены';
                modal(`<h2>${esc(result.name || `Сообщество #${result.group_id}`)}</h2><p class="vkt-muted">${result.screen_name ? `<a href="https://vk.com/${esc(result.screen_name)}" target="_blank" rel="noopener noreferrer">vk.com/${esc(result.screen_name)} ↗</a>` : `ID ${Number(result.group_id)}`}</p><h3>Права ключа</h3><p>${esc(permissions)}</p>${result.callback_url ? `<h3>Callback API</h3><input class="vkt-code-input" value="${esc(result.callback_url)}" readonly><p class="vkt-help">Ключ и Callback-секрет наружу не передаются.</p>` : ''}`);
                toast('Ключ сообщества и его права подтверждены VK.');
            }
            if (command==='publishing-run') { const result=await act('publishing_run'); toast(result.message); }
            if (command==='publishing-approve') {
                if (!window.confirm('Публикация проверена и готова к отправке в выбранные сообщества?')) return;
                const result=await act('publishing_approve',{id:Number(el.dataset.id)});
                dialog.close(); toast(result.warning || (result.status === 'published' ? 'Запись опубликована.' : 'Запись подтверждена и поставлена в очередь.'), !!result.warning);
            }
            if (command==='publishing-group-toggle') { await act('publishing_group_toggle',{id:Number(el.dataset.id),enabled:Number(el.dataset.enabled)}); }
            if (command==='publishing-retry') { await act('publishing_retry',{id:Number(el.dataset.id)}); toast('Неудачные адресаты возвращены в очередь.'); }
            if (command==='publishing-cancel') {
                if (!window.confirm('Отменить ещё не отправленные публикации? Уже опубликованные записи останутся в VK.')) return;
                await act('publishing_cancel',{id:Number(el.dataset.id)});
                if (dialog.open) dialog.close();
                toast('Ожидающие публикации отменены.');
            }
            if (command==='pause') { await act('settings',{paused:!state.settings.paused}); }
            if (command==='source-toggle') { await act('source_toggle',{id:Number(el.dataset.id),enabled:Number(el.dataset.enabled)}); }
            if (command==='retry') { await act('retry',{id:Number(el.dataset.id)}); toast('Задание возвращено в очередь.'); }
            if (command==='enqueue') { await act('enqueue',{id:Number(el.dataset.id)}); toast('Ролик поставлен в очередь. Запустите сбор или дождитесь расписания.'); }
            if (command==='delete') {
                const prompts = {videos: 'Удалить ролик, его историю замеров и связи с товарами?', posts: 'Удалить пост и его историю замеров?', sources: 'Удалить источник вместе с собранными постами и их историей? Сохранённые ролики останутся.'};
                if (!window.confirm(prompts[el.dataset.entity] || 'Удалить выбранный объект?')) return;
                await act('delete',{id:Number(el.dataset.id),entity:el.dataset.entity});
                dialog.close(); toast('Объект удалён.');
            }
            if (command==='delete-token') { if(!window.confirm('Удалить сохранённый токен VK?')) return; await act('settings',{delete_token:true}); toast('Токен удалён.'); }
            if (command==='unlink') { await act('link',{video_id:Number(el.dataset.id),product_id:Number(el.dataset.product),remove:true}); await load(false); videoDetail(el.dataset.id); return; }
            if (command==='page-next') page++;
            if (command==='page-prev') page=Math.max(1,page-1);
            if (command==='search-next' || command==='search-prev') { searchOffset=Math.max(0,searchOffset+(command==='search-next'?100:-100)); await runSearch(); return; }
            await load();
        } catch (error) { toast(error.message, true, error.fix); }
        finally { el.disabled=false; }
    });
    root.addEventListener('submit', async event => {
        const form = event.target.closest('[data-form]');
        if (!form) return;
        event.preventDefault();
        const submit=form.querySelector('button[type="submit"], button:not([type])');
        if (submit?.disabled) return;
        const values=Object.fromEntries(new FormData(form));
        if(submit) { submit.disabled=true; submit.setAttribute('aria-busy','true'); }
        try {
            switch(form.dataset.form) {
                case 'comment-reply': await commentsReply(form, values); return;
                case 'ai-model': {
                    toast('Проверяем модель пробным запросом…');
                    const added = await act('ai_model_save', {preset: values.preset, model: values.model, base: values.base, key: values.key, title: values.title, default: !!values.default});
                    toast(`«${added.title}» подключена и ответила: «${added.answer}».`);
                    break;
                }
                case 'callback-manual': {
                    await act('callback_save', {group_id: Number(form.dataset.id), code: values.code, secret: values.secret});
                    toast('Сохранено. Теперь в VK впишите тот же секретный ключ и нажмите «Подтвердить».');
                    await groupSettings(Number(form.dataset.id));
                    return;
                }
                case 'community-key': {
                    const added = await act('community_key_add', {token: values.token, group: values.group});
                    if (dialog.open) dialog.close();
                    // Список «Моих сообществ» и комментариев перечитывается: группа должна появиться сразу.
                    publishingData = null; commentsData = null;
                    toast(`Группа «${added.name || 'club' + added.group_id}» добавлена.${added.warning ? ' ' + added.warning : ''}`, !!added.warning);
                    break;
                }
                case 'comments-bulk': await commentsQueue(); return;
                case 'discover': searchQuery=values.q; searchShort=!!values.short; searchOffset=0; await runSearch(); return;
                case 'filter': localSearch=values.search; sort=values.sort; page=1; await load(); return;
                case 'posts-search': postsSearch=values.search; postsSort=values.sort; postsSource=Number(values.source) || 0; postsPage=1; await load(); return;
                case 'post-filters': {
                    // Пустые поля не попадают в фильтр: сервер отбрасывает всё, кроме чисел и флагов.
                    const next = {period: postsFilters.period ?? ''};
                    Object.entries(values).forEach(([key, value]) => {
                        if (['with_product', 'with_price', 'no_ads', 'recognized'].includes(key)) { next[key] = true; return; }
                        if (key === 'shop') { if (String(value).trim() !== '') next.shop = String(value); return; }
                        if (String(value).trim() !== '' && isFinite(Number(value))) next[key] = Number(value);
                    });
                    postsFilters = next; postsPage = 1;
                    await load();
                    return;
                }
                case 'api': {
                    apiMethod=values.method; apiDraft=values.params;
                    let params; try { params=JSON.parse(values.params); } catch { throw new Error('Параметры содержат ошибку JSON. Проверьте кавычки и запятые.'); }
                    if(!params || Array.isArray(params) || typeof params!=='object') throw new Error('Параметры должны быть JSON-объектом.');
                    try { apiResult=await act('api',{method:apiMethod,params}); }
                    catch(error) { apiResult=error.payload || {error:error.message}; toast(error.message, true, error.fix); }
                    await load(false); render(); return;
                }
                case 'settings': {
                    const hadToken = String(values.token || '').trim() !== '';
                    // Страницы подключений разделены, и в каждой форме лежит
                    // своя часть настроек. Флажок, которого в этой форме нет,
                    // не трогаем: отправка false стёрла бы чужую настройку.
                    const extra = {};
                    for (const name of ['homepage','paused','posts','links','publishing_review']) {
                        if (form.elements[name]) extra[name] = !!values[name];
                    }
                    for (const name of ['source_hours','video_hours','member_sources','ai_text_daily','ai_media_daily']) {
                        if (form.elements[name]) extra[name] = Number(values[name]);
                    }
                    await act('settings',{...values,...extra});
                    if (form.elements.token) form.elements.token.value='';
                    // Сразу спрашиваем VK, принят ли токен: иначе о проблеме
                    // становится известно только из записей прошлых попыток.
                    if (hadToken) {
                        try {
                            const check = await act('token_check');
                            toast(check.ok
                                ? `Токен сохранён и принят VK: ${check.name || 'аккаунт ' + check.user_id}.`
                                : `Токен сохранён, но VK его отклонил — код ${check.vk_code}: ${check.message}`, !check.ok);
                        } catch (error) { toast(`Токен сохранён, но проверить не вышло: ${error.message}`, true); }
                    } else {
                        toast('Настройки сохранены.');
                    }
                    break;
                }
                case 'series-setup': {
                    // Настройки сетки приняты и дальше живут в seriesSetup — отдельный черновик формы не нужен.
                    delete fieldDrafts['series-setup'];
                    seriesSetup = {
                        span: 'month' === values.span ? 'month' : 'week',
                        start: values.start || '',
                        times: values.times || '',
                        weekdays: [...form.querySelectorAll('input[name="weekdays"]:checked')].map(input => input.value),
                    };
                    const planned = seriesPlan(seriesSetup);
                    if (!planned.length) throw new Error('Ни одного слота не вышло: проверьте дни недели, время и дату начала.');
                    // Написанное руками не теряется: слот с той же датой и
                    // временем переносится в новую сетку как есть.
                    const kept = new Map(seriesSlots.map(slot => [slot.at, slot]));
                    seriesSlots = planned.map(slot => kept.get(slot.at) || slot);
                    render();
                    toast(`Сетка построена: ${seriesSlots.length} слотов.`);
                    return;
                }
                case 'series-prompt': {
                    if (!seriesSlots.length) throw new Error('Сначала постройте сетку: модели нужно знать количество текстов.');
                    seriesPrompt = values.prompt || '';
                    const empties = seriesSlots.filter(slot => !slot.message.trim());
                    const targets = empties.length ? empties : seriesSlots;
                    const result = await act('series_generate', {model: aiModel, prompt: seriesPrompt, count: targets.length, group_id: Number(seriesGroup)});
                    const texts = result.posts || [];
                    targets.forEach((slot, index) => { if (texts[index]) slot.message = String(texts[index]); });
                    render();
                    toast(texts.length < targets.length
                        ? `Модель вернула ${texts.length} текстов из ${targets.length}: остальные слоты остались пустыми.`
                        : `Готово: ${texts.length} текстов разложены по слотам.`, texts.length < targets.length);
                    return;
                }
                case 'series-slot': {
                    const slot = seriesSlots[Number(values.index)];
                    if (!slot) throw new Error('Слот не найден.');
                    if (!validStamp(values.at) || new Date(values.at).getTime() < Date.now() + 60000) throw new Error('Время слота должно быть в будущем.');
                    slot.at = values.at;
                    seriesSlots.sort((a, b) => a.at < b.at ? -1 : 1);
                    slot.message = values.message || '';
                    slot.attachments = values.attachments || '';
                    slot.imagePrompt = values.image_prompt || '';
                    seriesSlotTarget = null;
                    dialog.close();
                    render();
                    return;
                }
                case 'series-groups': return;
                case 'series-bind': return;
                case 'group-passport': {
                    await act('group_passport', {id: groupOpen, passport: values.passport || ''});
                    groupPassportDraft = null;
                    toast('Паспорт сохранён: нейросеть учтёт его в постах, сериях и ответах этой группы.');
                    break;
                }
                case 'user-limits': {
                    await act('user_limits', {id: Number(values.id), limits: {text: values.text, media: values.media, replies_queue: values.replies_queue, sources: values.sources}});
                    dialog.close();
                    toast('Лимиты кабинета сохранены.');
                    break;
                }
                case 'group-news': {
                    const saved = await act('group_news_save', {id: groupOpen, news: groupNewsPayload(groupDetail)});
                    groupNewsDraft = null;
                    toast(saved.news.enabled ? `Настройки новостей сохранены: источников ${saved.news.sources.length}.` : 'Новости для этой группы выключены.');
                    break;
                }
                case 'group-material': {
                    const material = groupMaterialEdit === 'new'
                        ? {title: values.title || '', text: values.text || '', posts: !!values.posts, replies: !!values.replies}
                        : {id: groupMaterialEdit, title: values.title || '', text: values.text || ''};
                    await act('group_material_save', {id: groupOpen, material});
                    groupMaterialEdit = null; groupMaterialDraft = null;
                    toast('Материал сохранён: нейросеть учтёт его в следующих генерациях для этой группы.');
                    break;
                }
                case 'series-images': seriesImagesRun().catch(error => { seriesImageRun = null; toast(error.message, true, error.fix); render(); }); return;
                case 'series-post': {
                    if (!seriesEdit) throw new Error('Запись не найдена.');
                    if (!validStamp(values.at)) throw new Error('Укажите дату и время публикации.');
                    const post = {message: values.message || '', attachments: values.attachments || '', media: seriesEdit.draft.media.map(item => Number(item.id))};
                    // Время шлём, только если его сдвинули: иначе запись за минуту до выхода не сохранить.
                    if (values.at !== localStamp(parseDate(seriesEdit.post.scheduled_at))) post.scheduled_at = new Date(values.at).toISOString();
                    await act('publishing_update', {id: seriesEdit.id, post});
                    seriesEdit = null;
                    dialog.close();
                    toast('Запись обновлена.');
                    break;
                }
                case 'video': await act('save_video',values); dialog.close(); toast('Ролик добавлен, замер сохранён.'); break;
                case 'product': await act('product',values); dialog.close(); toast('Товар добавлен. Привяжите его к ролику через меню ролика.'); break;
                case 'source': await act('source',values); dialog.close(); toast('Источник сохранён.'); break;
                case 'vkid': {
                    const clientId = Number(values.vkid_client_id) || 0;
                    if (!clientId) throw new Error('Укажите ID приложения из консоли dev.vk.ru.');
                    await act('settings', {vkid_client_id: clientId, vkid_redirect: values.vkid_redirect || ''});
                    // Константа мешает только сохранению токена, поэтому ID
                    // запоминаем в любом случае и не уводим в VK впустую.
                    if (state.settings.vkid?.blocked_by_constant) {
                        await load();
                        toast('ID приложения сохранён. Теперь уберите VKT_ACCESS_TOKEN из wp-config.php, обновите страницу и нажмите «Подключить VK ID».');
                        return;
                    }
                    const started = await act('vkid_start', {return_to: location.href});
                    location.href = started.url;
                    return;
                }
                case 'oauth-app': {
                    const appId = Number(values.app_id) || 0;
                    if (!appId) throw new Error('Укажите ID приложения.');
                    await act('settings', {app_id: appId});
                    await load();
                    toast('ID приложения сохранён. Теперь кабинеты могут подключать токен.');
                    return;
                }
                case 'oauth': {
                    if (!String(values.code || '').trim()) {
                        toast('Сначала нажмите «Открыть VK», разрешите доступ и вставьте сюда адрес страницы.', true);
                        return;
                    }
                    const report = await act('oauth_exchange', {code: values.code});
                    await load();
                    tokenReport(report);
                    return;
                }
                case 'token': {
                    if (!String(values.token || '').trim()) throw new Error('Вставьте ключ или адрес из браузера.');
                    const report = await act('token_save', values);
                    await load();
                    tokenReport(report);
                    return;
                }
                case 'flux': {
                    if (fluxBusy) return;
                    fluxBusy = true;
                    const progress = $('#vkt-flux-progress');
                    const count = Math.max(1, Math.min(IMAGE_VARIANTS, Number(values.count) || 1));
                    const batch = Date.now();
                    const result = await drawVariants(count, async index => {
                        const run = {batch, at: new Date().toISOString(), model: values.model, status: 'pending', message: 'Задача поставлена…'};
                        try {
                            const started = await act('flux_start', {
                                model: values.model, prompt: values.prompt, safety_tolerance: Number(values.safety_tolerance),
                                seed: values.seed ? Number(values.seed) + index : 0, width: Number(values.width) || 0, height: Number(values.height) || 0,
                                output_format: values.output_format, disable_pup: !!values.disable_pup, images: fluxMedia.map(item => Number(item.id)),
                            });
                            // Ход отдельной задачи виден только когда она одна: у пачки общий счёт готовых.
                            const done = await fluxAwait(started.id, count > 1 ? () => {} : null);
                            Object.assign(run, {status: 'done', message: 'Файл сохранён в медиатеку.', media: done.media, cost: done.cost});
                            return done.media;
                        } catch (error) {
                            // Дословный ответ сервиса и есть смысл стенда: по нему видно,
                            // что именно отклонили — запрос или готовую картинку.
                            Object.assign(run, {status: 'error', message: error.message});
                            throw error;
                        } finally { fluxRuns.unshift(run); }
                    }, text => { if (progress) { progress.hidden = false; progress.textContent = text; } });
                    fluxBusy = false;
                    const failure = result.errors[0];
                    if (failure) toast(result.media.length ? `Готово ${result.media.length} из ${count}. Ошибка: ${failure.message}` : failure.message, true, failure.fix);
                    else toast(count > 1 ? `Готово: ${count} изображений в медиатеке сайта.` : 'Готово: изображение в медиатеке сайта.');
                    // Счётчик «осталось сегодня» берётся с сервера.
                    await load(false);
                    fluxRuns = fluxRuns.slice(0, 30);
                    render();
                    return;
                }
                case 'media-search': await mediaLibrary(values.search || ''); return;
                case 'ai-text': {
                    aiPrompt = values.prompt;
                    const target = $('[data-form="publishing"] textarea[name="message"]');
                    // Одна выбранная группа — пишем под неё: паспорт и её недавние темы.
                    const picked = [...($('[data-form="publishing"]')?.querySelectorAll('input[name="groups"]:checked') || [])];
                    const result = await act('ai_text',{model: aiModel, prompt:values.prompt,current:target?.value || '', group_id: 1 === picked.length ? Number(picked[0].value) : 0});
                    if (target) { target.value = result.text; target.dispatchEvent(new Event('input',{bubbles:true})); }
                    dialog.close();
                    toast('Текст готов — проверьте его перед отправкой.');
                    return;
                }
                case 'ai-image': {
                    aiPrompt = values.prompt;
                    // Выбор модели общий с «Фото к записям» серии: drawImage читает его оттуда же.
                    seriesImage.provider = values.provider; seriesImage.ratio = values.ratio || 'portrait';
                    const count = Math.max(1, Math.min(IMAGE_VARIANTS, Number(values.count) || 1));
                    const progress = $('#vkt-ai-progress');
                    const say = text => { if (progress) { progress.hidden = false; progress.textContent = text; } };
                    const result = await drawVariants(count, async () => {
                        const media = await drawImage(values.prompt, count > 1 ? () => {} : say);
                        addComposerMedia(media);
                        return media;
                    }, say);
                    const failure = result.errors[0];
                    // Окно с промптом остаётся, если не вышло ничего: его можно поправить и отправить снова.
                    if (failure && !result.media.length) throw failure;
                    dialog.close();
                    if (failure) toast(`Прикреплено ${result.media.length} из ${count}. Ошибка: ${failure.message}`, true, failure.fix);
                    else toast(count > 1 ? `${count} изображений сохранены в медиатеку и прикреплены к записи.` : 'Изображение сохранено в медиатеку и прикреплено к записи.');
                    return;
                }
                case 'ai-video': {
                    aiPrompt = values.prompt;
                    const started = await act('ai_video_start',{prompt:values.prompt,ratio:values.ratio || 'story'});
                    addComposerMedia(await awaitVideo(started.request_id));
                    dialog.close();
                    toast('Ролик сохранён в медиатеку и прикреплён к записи.');
                    return;
                }
                case 'publishing': {
                    const groups = [...form.querySelectorAll('input[name="groups"]:checked')].map(input => Number(input.value));
                    if (!groups.length) throw new Error('Выберите хотя бы одно сообщество.');
                    const scheduledAt = values.scheduled_at ? new Date(values.scheduled_at).toISOString() : '';
                    const result = await act('publishing_create',{message:values.message || '',attachments:values.attachments || '',media:composerMedia.map(item => Number(item.id)),groups,scheduled_at:scheduledAt,signed:!!values.signed,close_comments:!!values.close_comments});
                    composerMedia = [];
                    form.reset();
                    // Запись ушла — её черновик больше не нужен.
                    delete fieldDrafts.publishing;
                    toast(result.warning || (result.status === 'published' ? 'Запись опубликована.' : result.status === 'scheduled' ? 'Запись поставлена в расписание.' : 'Запись поставлена в очередь.'), !!result.warning);
                    break;
                }
                case 'import': {
                    const r = await act('sources_import',{list:values.list});
                    dialog.close();
                    toast(`Добавлено: ${r.added} из ${r.requested}. Уже были: ${r.resolved-r.added}. Не найдено в VK: ${r.missing}.${r.limited ? ' Остальные не поместились в лимит кабинета.' : ''}`, !!r.limited);
                    break;
                }
                case 'link': await act('link',{video_id:Number(values.video_id),product_id:Number(values.product_id)}); await load(false); videoDetail(values.video_id); toast('Товар привязан.'); return;
            }
            await load();
        } catch(error) { toast(error.message, true, error.fix); }
        finally { if(submit) { submit.disabled=false; submit.removeAttribute('aria-busy'); } }
    });
    root.addEventListener('change',async event=> {
        draftInput(event.target);
        scheduleDrafts();
        if (event.target.matches('[data-news]')) {
            const field = event.target;
            groupNewsDraft = {...(groupNewsDraft || {}), [field.dataset.news]: field.type === 'checkbox' ? field.checked : field.value};
            // Галочка «новостная группа» раскрывает или прячет настройки.
            if (field.type === 'checkbox' || field.dataset.news === 'method') render();
            return;
        }
        if (event.target.matches('[data-material-use]')) {
            const input = event.target;
            input.disabled = true;
            try {
                const saved = await act('group_material_save', {id: groupOpen, material: {id: input.dataset.id, [input.dataset.materialUse]: input.checked}});
                if (groupDetail) groupDetail.materials = saved.materials;
            } catch (error) { toast(error.message, true, error.fix); }
            render();
            return;
        }
        if (event.target.matches('[data-material-file]')) {
            const file = event.target.files?.[0];
            if (!file) return;
            // Больше 200 КБ — это не заметка с правилами, а документ целиком: модель его не прочтёт.
            if (file.size > 204800) { event.target.value = ''; toast('Файл больше 200 КБ. Оставьте в нём только правила для этой группы.', true); return; }
            const text = (await file.text()).replace(/\r\n/g, '\n').trim();
            groupMaterialDraft = {title: groupMaterialDraft?.title || file.name.replace(/\.[^.]+$/, ''), text: text.slice(0, 20000)};
            render();
            toast(text.length > 20000 ? 'Файл длиннее 20 000 символов — в поле попало только начало.' : 'Текст из файла подставлен — проверьте и сохраните.', text.length > 20000);
            return;
        }
        if (event.target.matches('[data-flux-upload]')) {
            const input = event.target;
            const file = input.files?.[0];
            input.value = '';
            if (!file) return;
            const label = input.closest('label');
            label?.classList.add('is-busy');
            try { addFluxMedia(await upload(file)); toast('Файл загружен и добавлен к запросу.'); }
            catch (error) { toast(error.message, true, error.fix); }
            finally { label?.classList.remove('is-busy'); }
            return;
        }
        if (event.target.matches('[data-media-upload]')) {
            const input = event.target;
            const file = input.files?.[0];
            input.value = '';
            if (!file) return;
            const label = input.closest('label');
            label?.classList.add('is-busy');
            try { addComposerMedia(await upload(file)); toast('Файл загружен и прикреплён к записи.'); }
            catch (error) { toast(error.message, true, error.fix); }
            finally { label?.classList.remove('is-busy'); }
            return;
        }
        if (event.target.matches('[data-form="posts-search"] select[name="source"]')) { event.target.form.requestSubmit(); return; }
        if (event.target.matches('[data-comments-group]')) {
            commentsGroup = Number(event.target.value) || 0;
            commentsPosts = null; commentsPost = null; commentsThread = null; commentsReplyKey = '';
            commentsSelected.clear(); commentsDrafts.clear(); commentsAiKeys.clear();
            render();
            try { await loadComments(); } catch (error) { toast(error.message, true, error.fix); }
            render();
            return;
        }
        if (event.target.matches('[data-comments-pick]')) {
            const key = event.target.dataset.commentsPick;
            if (event.target.checked) { const item = commentPayload(key); if (item) commentsSelected.set(key, item); }
            else commentsSelected.delete(key);
            render();
            return;
        }
        if (event.target.matches('[data-ai-model]')) { aiModel = event.target.value; return; }
        if (event.target.matches('[data-series-group]')) { seriesGroup = Number(event.target.value) || 0; seriesTarget = ''; render(); return; }
        if (event.target.matches('[data-series-target]')) { seriesTarget = event.target.value; render(); return; }
        if (event.target.matches('[data-series-image]')) {
            const key = event.target.dataset.seriesImage;
            seriesImage[key] = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
            // Смена поставщика меняет список форматов — панель перерисовываем целиком.
            if (key === 'provider') { render(); return; }
            const count = $('[data-series-images-count]');
            if (count) count.textContent = seriesImageTargets().length;
            return;
        }
        if (event.target.matches('[data-ai-preset]')) {
            const preset = (state.settings.ai?.presets || {})[event.target.value] || {};
            const form = event.target.form;
            if (form) { form.elements.base.value = preset.base || ''; form.elements.model.value = preset.model || ''; }
            return;
        }
        if (event.target.matches('[data-bulk]')) { commentsBulk[event.target.dataset.bulk] = event.target.type === 'checkbox' ? event.target.checked : event.target.value; return; }
        if(event.target.id==='vkt-method') { apiMethod=event.target.value; apiDraft=null; render(); }
        if(event.target.name==='token_kind') { const fields=$('#vkt-user-token-fields'); if(fields) fields.hidden = event.target.value!=='user'; }
    });
    // Код из адреса живёт около минуты: подключаем сразу по вставке, не дожидаясь кнопки.
    root.addEventListener('paste', event => {
        const field = event.target;
        if (!field.matches?.('[data-form="oauth"] input[name="code"]')) return;
        setTimeout(() => { if (/[?&#]code=/.test(field.value)) field.form.requestSubmit(); }, 0);
    });
    root.addEventListener('input',event=> {
        draftInput(event.target);
        // Текст в окне слота сразу ложится в слот: закрыли окно мимо «Сохранить слот» — набранное не пропало.
        if (event.target.closest?.('[data-form="series-slot"]')) captureSeriesDialog();
        scheduleDrafts();
        if(event.target.id==='vkt-params') apiDraft=event.target.value;
        // Тема серии и стиль картинок переживают перерисовку раздела.
        if(event.target.matches('[data-series-prompt]')) seriesPrompt=event.target.value;
        // Паспорт переживает перерисовку после обновления охватов и других действий.
        if(event.target.matches('[data-group-passport]')) groupPassportDraft=event.target.value;
        if(event.target.matches('textarea[data-news]')) groupNewsDraft={...(groupNewsDraft||{}), [event.target.dataset.news]: event.target.value};
        if(event.target.dataset.materialDraft && groupMaterialDraft) groupMaterialDraft[event.target.dataset.materialDraft]=event.target.value;
        if(event.target.dataset.seriesImage==='style') seriesImage.style=event.target.value;
        // Черновики ответов переживают перерисовку: форма собирается заново после каждого действия.
        if(event.target.dataset.draft) { commentsDrafts.set(event.target.dataset.draft, event.target.value); commentsAiKeys.delete(event.target.dataset.draft); }
        if(event.target.dataset.bulk && event.target.type !== 'checkbox') commentsBulk[event.target.dataset.bulk]=event.target.value;
    });
    window.addEventListener('hashchange', async()=> {
        view=initial(); root.classList.remove('menu-open'); $('.vkt-menu').setAttribute('aria-expanded','false');
        // Переход по ссылке «где чинить» из сообщения: само сообщение больше не нужно.
        $('#vkt-toast').hidden = true;
        if(dialog.open) dialog.close();
        if(view==='overview') { page=1; localSearch=''; sort='velocity'; }
        // Из раздела ушли — в следующий раз «Мои сообщества» открываются списком.
        if(view!=='groups') { groupDraftsSave(); groupOpen=0; groupDetail=null; groupDraftsLoad(0); }
        render();
        content.focus({preventScroll:true});
        try { await load(); } catch(error) { toast(error.message, true, error.fix); }
    });
    if (config.notice?.message) toast(config.notice.message, !!config.notice.error);
    // Служебные параметры возврата из VK не должны оставаться в адресе и попадать в закладки.
    try {
        const clean = new URL(location.href);
        const service = ['vkt_vkid', 'vkt_vkid_message', 'vkt_auth', 'vkt_auth_message'];
        if (service.some(name => clean.searchParams.has(name))) {
            service.forEach(name => clean.searchParams.delete(name));
            history.replaceState(null, '', clean.href);
        }
    } catch {}
    restoreDrafts();
    // Вкладку закрывают или уводят на страницу VK за токеном — успеваем записать последнее набранное.
    window.addEventListener('pagehide', persistDrafts);
    load().catch(error=> { content.innerHTML=heading('Не удалось загрузить данные','Проверьте вход в WordPress и доступность REST API.')+`<div class="vkt-info">${esc(error.message)}</div>${button('Повторить','reload')}`; toast(error.message, true, error.fix); });
})();

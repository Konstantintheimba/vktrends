(() => {
    'use strict';
    const config = window.vktConfig;
    const root = document.getElementById('vkt-app');
    if (!root || !config) return;
    const $ = (selector, parent = root) => parent.querySelector(selector);
    const content = $('#vkt-content');
    const dialog = $('#vkt-dialog');
    const names = {overview: 'Обзор', discover: 'Поиск трендов', posts: 'Посты', communities: 'Сообщества', publishing: 'Автопостинг', videos: 'Мои ролики', products: 'Товары', sources: 'Источники', api: 'Тест API', collector: 'Сбор данных', logs: 'Журнал', settings: 'Настройки'};
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
    const initial = () => names[location.hash.slice(1)] ? location.hash.slice(1) : config.initialView;
    let view = initial(), state = null, page = 1, localSearch = '', sort = 'velocity', searchResults = null, searchQuery = '', searchOffset = 0, searchShort = true, apiResult = null, apiMethod = 'wall.get', apiDraft = null, toastTimer, requestSequence = 0;
    // Витрина постов: своя пагинация, фильтры и режим отображения, независимые от роликов.
    let postsData = null, postsPage = 1, postsSearch = '', postsSort = 'velocity', postsSource = 0, postsMode = 'grid', postsFiltersOpen = false, postsFilters = {}, communitiesData = null, communitiesSort = 'views', publishingData = null;
    // Файлы, выбранные в конструкторе записи: ID вложений WordPress, не VK.
    let composerMedia = [], mediaLibraryItems = [], aiPrompt = '';
    function toast(message, error = false) {
        const el = $('#vkt-toast');
        el.textContent = message;
        el.classList.toggle('vkt-error', error);
        el.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { el.hidden = true; }, error ? 9000 : 5000);
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
        const modeNote = state.settings.has_token ? modeLabel(state.settings.token_mode) : '';
        $('#vkt-connection').innerHTML = `<span class="vkt-status-dot ${state.settings.has_token ? 'is-ready' : ''}"></span>${state.settings.has_token ? 'Токен сохранён' : 'API не подключён'}${modeNote ? ` · ${modeNote}` : ''}<small>${state.settings.paused ? 'Автосбор на паузе' : 'Автосбор включён'}</small>`;
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
            if (view === 'publishing') publishingData = await request('publishing');
        } catch (error) { toast(error.message, true); }
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
            `<section class="vkt-welcome"><div class="vkt-welcome-copy"><span class="vkt-pill">${state.settings.has_token ? 'РАБОЧЕЕ ПРОСТРАНСТВО' : 'НАЧНИТЕ ЗДЕСЬ'}</span><h2>${state.settings.has_token ? 'От первого видео — к растущей подборке' : 'Большие тренды начинаются с первого видео'}</h2><p>${state.settings.has_token ? 'Добавьте источники, сохраните интересные ролики и включите сбор. Новые замеры покажут, какие видео растут быстрее.' : 'Подключите VK, найдите интересные ролики и наблюдайте, как меняется их популярность.'}</p><a href="#${state.settings.has_token ? 'sources' : 'settings'}" class="vkt-button vkt-dark">${state.settings.has_token ? 'Добавить источник' : 'Подключить VK API'} <span>↗</span></a></div><div class="vkt-welcome-art" aria-hidden="true"><div class="vkt-art-orbit"></div><div class="vkt-art-card"><span>СЛЕДИТЕ ЗА РОСТОМ</span><div class="vkt-art-chart"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><small>От замера к замеру ↗</small></div><div class="vkt-art-float">${icon('arrow')}</div></div></section>` +
            `<div class="vkt-overview-grid"><section class="vkt-panel"><div class="vkt-panel-heading"><div><h2>В фокусе</h2><p>Сортировка по скорости прироста просмотров</p></div><a href="#videos">Все ролики →</a></div>${state.videos.length ? `<div class="vkt-video-grid compact">${state.videos.slice(0,3).map(v => videoCard(v)).join('')}</div>` : empty('Пока здесь тихо', 'Добавьте первое видео — здесь появится ваша подборка и её динамика.', 'discover', 'Перейти к поиску')}</section><section class="vkt-panel vkt-start-panel"><h2>Быстрый старт</h2>${[['settings','Подключите VK API','Сохраните пользовательский токен',state.settings.has_token],['discover','Найдите первые видео','Поиск или прямая ссылка',Number(s.videos)>0],['sources','Настройте мониторинг','Добавьте запрос или автора',state.sources.length>0]].map((x,i)=>`<a href="#${x[0]}" class="vkt-step"><span class="vkt-step-number ${x[3]?'done':''}">${x[3]?icon('check'):String(i+1).padStart(2,'0')}</span><div><strong>${x[1]}</strong><small>${x[2]}</small></div><span>↗</span></a>`).join('')}<div class="vkt-note">${icon('clock')}<div>Последний замер<strong>${date(s.last_measurement)}</strong></div></div></section></div>`;
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
            `<div class="vkt-info">За один обход читаем до 100 постов сообщества и берём все видео из них — вместе со счётчиками просмотров. Каждый обход добавляет новый замер уже сохранённым роликам.</div>` +
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
            (state.logs.length ? `<section class="vkt-panel vkt-table-wrap"><table><thead><tr><th>Время</th><th>Метод</th><th>Откуда</th><th>Статус</th><th>Время ответа</th><th>Сообщение</th></tr></thead><tbody>${state.logs.map(l=>`<tr><td>${date(l.created_at)}</td><td><code>${esc(l.method)}</code></td><td>${({test:'Тест API',collector:'Сборщик',manual:'Вручную',search:'Поиск',publisher:'Автопостинг'})[l.context] || esc(l.context)}</td><td>${badge(l.status==='ok'?'Успешно':`Ошибка ${Number(l.code)||''}`,l.status==='ok'?'green':'red')}</td><td>${num(l.duration_ms)} мс</td><td>${esc(l.message)}</td></tr>`).join('')}</tbody></table></section>` : empty('Запросов ещё не было', 'Выполните тест API или найдите первое видео.', 'api','Открыть тест API'));
    }
    const slotExtras = {
        user: '<div class="vkt-form-row"><label>refresh_token · необязательно<input name="refresh_token" autocomplete="off" maxlength="2048"></label><label>device_id · необязательно<input name="device_id" autocomplete="off" maxlength="2048"></label><label>client_id · необязательно<input name="client_id" autocomplete="off" maxlength="2048"></label><label>Срок жизни, сек.<input type="number" name="expires_in" min="60" max="31536000" placeholder="86400"></label></div>',
    };
    function tokenReport(report) {
        const rows = (report.checks || []).map(check => `<tr><td>${esc(check.label)}<small class="vkt-muted"><code>${esc(check.method)}</code></small></td><td>${badge(check.ok ? 'доступен' : 'отказ', check.ok ? 'green' : 'red')}</td><td>${check.code ? `код ${Number(check.code)}: ` : ''}${esc(check.message)}</td></tr>`).join('');
        modal(`<h2>Проверка · ${esc(report.title || '')}</h2><p class="vkt-muted">Плагин вызвал методы VK этим ключом и показывает дословные ответы. Записи при этом не создаются.</p><div class="vkt-table-wrap"><table><thead><tr><th>Что проверяли</th><th>Итог</th><th>Ответ VK</th></tr></thead><tbody>${rows}</tbody></table></div>${report.ok ? '' : '<div class="vkt-info vkt-info-warning">Отказы остаются видны в карточке ключа, пока не будут исправлены.</div>'}`);
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
                    <label>Ключ или адрес из браузера<input type="password" name="token" autocomplete="new-password" maxlength="2048" placeholder="${slot.has_token ? 'Оставьте пустым, чтобы не менять' : 'Вставьте ключ'}"></label>
                    ${slotExtras[slot.slot] || ''}
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">Сохранить и проверить</button>${slot.has_token ? button('Проверить', 'token-probe', `data-slot="${esc(slot.slot)}"`) + button('Удалить', 'token-forget', `data-slot="${esc(slot.slot)}"`) : ''}</div>
                </form>`}
        </section>`;
    }
    function settings() {
        const s = state.settings;
        const community = s.community || {};
        const ai = s.ai || {};
        const vkid = s.vkid || {};
        const tokenSlots = s.tokens || [];
        const oauth = s.oauth || {};
        const constantToken = s.token_source === 'wp-config.php';
        const currentKind = s.token_mode || 'service';
        const minutes = minutesLeft(s.token_expires_in);
        const expiryNote = minutes === null ? '' : s.token_refreshable ? ` · автообновление, осталось ~${minutes} мин.` : ` · без автообновления, осталось ~${minutes} мин.`;
        const modeNote = s.has_token ? `${modeLabel(s.token_mode)}${expiryNote}` : '';
        return heading('Настройки', 'Подключение к VK и параметры рабочего пространства.') +
            `<div class="vkt-settings-grid"><section class="vkt-panel"><h2>Доступ к VK API</h2><form data-form="settings" id="vkt-settings-form" class="vkt-form">
                <div class="vkt-field-status">${badge(s.has_token?'Токен сохранён':'Не подключён',s.has_token?'green':'')}<span class="vkt-muted">${constantToken?'Задан в wp-config.php':'Хранится на сервере в зашифрованном виде'}${modeNote?` · ${modeNote}`:''}</span></div>
                ${s.has_token?`<div class="vkt-token-preview"><code>${esc(s.token_preview||'')}</code><span class="vkt-muted">${Number(s.token_length)||0} символов · это то, что реально уходит в VK</span>${button('Проверить токен','token-check')}</div>${s.token_scope?`<p class="vkt-help">Права, выданные VK: <code>${esc(s.token_scope)}</code>. Для фото нужен <code>photos</code>, для публикации — <code>wall</code>.</p>`:''}`:''}
                <label>Тип токена<select name="token_kind" ${constantToken?'disabled':''}><option value="service" ${currentKind==='service'?'selected':''}>Сервисный ключ приложения</option><option value="user" ${currentKind==='user'?'selected':''}>Пользовательский токен</option></select></label>
                <label>Access token<input type="password" name="token" autocomplete="new-password" placeholder="${s.has_token?'Оставьте пустым, чтобы сохранить текущий':'Токен или весь адрес из браузера'}" ${constantToken?'disabled':''} maxlength="2048"></label><small class="vkt-help">Можно вставить целиком адрес вида <code>https://oauth.vk.com/blank.html#access_token=…&amp;expires_in=86400</code> — токен, срок жизни и тип определятся сами.</small>
                <fieldset id="vkt-user-token-fields" class="vkt-form-row" ${constantToken || currentKind!=='user'?'hidden':''}><label>refresh_token · необязательно<input name="refresh_token" autocomplete="off" maxlength="2048"></label><label>device_id · необязательно<input name="device_id" autocomplete="off" maxlength="2048"></label><label>client_id · необязательно<input name="client_id" autocomplete="off" maxlength="2048"></label><label>Срок жизни, сек.<input type="number" name="expires_in" min="60" max="86400" placeholder="Например, 86400"></label></fieldset>
                <p class="vkt-help">Для автообновления укажите refresh_token, device_id и client_id полным комплектом. Токен Standalone/Implicit можно сохранить без них, но после истечения его придётся заменить вручную. Пользовательский токен с wall и groups нужен для управления несколькими группами. Ключ отдельного сообщества подключается серверной константой и публикует только на стене своей группы. Секреты не возвращаются в браузер.</p>
                <div class="vkt-form-row"><label>Версия API<input name="api_version" value="${esc(s.api_version)}" required pattern="5\\.[0-9]{1,3}"></label></div>
                <div class="vkt-form-row"><label>Обход сообществ<select name="source_hours">${hourOptions(s.source_hours)}</select><small class="vkt-help">Посты и видео со стены.</small></label><label>Замеры роликов<select name="video_hours">${hourOptions(s.video_hours)}</select><small class="vkt-help">Точечное обновление счётчиков.</small></label></div>
                <label class="vkt-check"><input type="checkbox" name="homepage" ${s.homepage?'checked':''}>Показывать дашборд на главной</label><label class="vkt-check"><input type="checkbox" name="paused" ${s.paused?'checked':''}>Пауза автоматического сбора</label><label class="vkt-check"><input type="checkbox" name="posts" ${s.posts?'checked':''}>Сохранять посты сообществ при обходе стены</label><label class="vkt-check"><input type="checkbox" name="links" ${s.links?'checked':''}>Читать страницы товаров по ссылкам известных магазинов</label><label class="vkt-check"><input type="checkbox" name="publishing_review" ${s.publishing_review?'checked':''}>Требовать ручную проверку публикаций</label>
                <label>Сервис рендеринга страниц<input name="proxy" autocomplete="off" maxlength="500" placeholder="${s.proxy_host?`Сейчас: ${esc(s.proxy_host)} · впишите новый адрес или очистите поле`:'https://api.example.com/?api_key=КЛЮЧ&url={url}'}"><small class="vkt-help">Пустое поле ничего не меняет, дефис очищает сохранённый адрес.</small></label>
                <div class="vkt-form-actions"><button class="vkt-button vkt-primary">Сохранить настройки</button>${s.has_token && !constantToken?button('Удалить токен','delete-token'):''}</div>
                </form>
                <hr class="vkt-settings-sep">
                <h2>Ключи VK</h2>
                <p class="vkt-muted">Каждый ключ живёт в своём слоте и используется для своей задачи. Ключи не заменяют друг друга: можно и нужно держать их одновременно.</p>
                <div class="vkt-token-cards">${tokenSlots.map(tokenCard).join('')}</div>
                <label>ID своего сообщества<input type="number" name="community_id" value="${Number(s.community?.group_id) || ''}" min="0" placeholder="Например, 241464933" form="vkt-settings-form"></label>
                <hr class="vkt-settings-sep">
                <h2>Получить пользовательский токен · обмен кода</h2>
                <p class="vkt-muted">Единственный способ, который работает с сервера: браузер получает одноразовый код, а меняет его на токен сам сервер защищённым ключом. Поэтому права классические (<code>${esc(oauth.scope || '')}</code>), а привязка к IP приходится на сервер, а не на ваш браузер.</p>
                <form data-form="oauth" class="vkt-form">
                    <div class="vkt-form-row">
                        <label>ID приложения<input type="number" name="app_id" value="${Number(oauth.app_id) || ''}" min="1" placeholder="Например, 54770323"></label>
                        <label>Адрес возврата приложения<input class="vkt-code-input" value="${esc(oauth.redirect || '')}" readonly></label>
                    </div>
                    <p class="vkt-help">Нужно приложение из <strong>dev.vk.ru</strong> (не из кабинета VK ID — тот отвечает <code>Security Error</code>). Защищённый ключ этого приложения сохраните в слоте «Защищённый ключ приложения» выше.</p>
                    <div class="vkt-form-actions">${button(`${icon('arrow')} 1. Открыть страницу согласия`, 'oauth-open')}</div>
                    <label>2. Адрес из браузера после «Разрешить»<input name="code" class="vkt-code-input" placeholder="https://oauth.vk.com/blank.html?code=…" autocomplete="off"></label>
                    <p class="vkt-help">Код одноразовый и живёт около минуты — вставляйте сразу. Токен получит и сохранит сервер, в браузер он не попадёт.</p>
                    <div class="vkt-form-actions"><button class="vkt-button vkt-primary">3. Обменять код на токен</button></div>
                </form>
                <hr class="vkt-settings-sep">
                <h2>Пользовательский токен · прочие способы</h2>
                <p class="vkt-muted">Единственный способ прикладывать фото и видео: ключ сообщества этого не умеет. Есть два пути — быстрый и с автообновлением.</p>
                <h3>Способ 1 · вставить токен вручную</h3>
                <p class="vkt-muted">Работает с любым приложением, токен живёт около суток. Нажмите кнопку, разрешите доступ, затем скопируйте из браузера <strong>весь адрес целиком</strong> и вставьте его в поле «Access token» выше — плагин сам достанет токен и срок жизни и выберет нужный тип.</p>
                <div class="vkt-form-actions">${button(`${icon('arrow')} Открыть страницу VK`, 'vkid-implicit')}</div>
                <h3>Способ 2 · VK ID с автообновлением</h3>
                ${vkid.blocked_by_constant ? '<div class="vkt-info vkt-info-warning">В <code>wp-config.php</code> задан <code>VKT_ACCESS_TOKEN</code>. Эта константа всегда трактуется как сервисный ключ и имеет приоритет над настройками, поэтому пользовательский токен сохранить не получится. Удалите или закомментируйте строку и обновите страницу.</div>' : ''}
                <form data-form="vkid" class="vkt-form">
                <label>ID приложения VK ID<input type="number" name="vkid_client_id" value="${Number(vkid.client_id) || ''}" min="1" step="1" placeholder="Например, 54770323" ${vkid.locked ? 'disabled' : ''}></label>
                <label>Доверенный redirect URI<input class="vkt-code-input" name="vkid_redirect" value="${esc(vkid.redirect_uri || '')}" spellcheck="false"></label>
                <p class="vkt-help">Адрес должен быть заранее прописан в приложении как доверенный. <strong>У приложений типа VK Mini App такого раздела нет</strong> — VK отвечает <code>redirect_uri is incorrect</code>, и тогда подходит только способ 1. Возврат плагин опознаёт по своему <code>state</code>, поэтому годится любая страница сайта. Запрашиваемые права: <code>${esc(vkid.scope || '')}</code>.</p>
                <div class="vkt-form-actions"><button class="vkt-button vkt-primary">${icon('lock')} ${vkid.blocked_by_constant ? 'Сохранить ID приложения' : 'Подключить VK ID'}</button></div>
                </form></section><aside class="vkt-panel vkt-settings-help"><span class="vkt-help-icon">${icon('lock')}</span><h2>Доступ только для вас</h2><p>Главная и API-страница открываются после входа в WordPress с правами администратора.</p><hr><h3>Тестовое сообщество</h3>${community.configured ? `<p>Настроен отдельный ключ сообщества <strong>#${Number(community.group_id)}</strong>. Он не возвращается в браузер.</p>${community.callback_configured ? `<label>Адрес Callback API<input class="vkt-code-input" value="${esc(community.callback_url)}" readonly></label><p class="vkt-help">Укажите этот адрес в настройках Callback API и нажмите «Подтвердить» в VK.</p>` : '<p class="vkt-muted">Данные Callback API не настроены.</p>'}${button('Проверить ключ и права', 'community-check')}` : '<p class="vkt-muted">Отдельный ключ сообщества не настроен.</p>'}<hr><h3>Генерация xAI</h3>${ai.configured ? `<p>Ключ задан в wp-config.php и в браузер не возвращается. Модели: <code>${esc(ai.text_model)}</code>, <code>${esc(ai.image_model)}</code>, <code>${esc(ai.video_model)}</code>. Кнопки генерации доступны в конструкторе записи.</p>` : '<p class="vkt-muted">Константа <code>VKT_XAI_API_KEY</code> не задана — кнопки генерации скрыты.</p>'}<hr><h3>Публикация</h3><p>Проверено живым API: ключ сообщества с правом wall публикует только на стене этой же группы. Для нескольких управляемых групп нужен пользовательский токен. Загрузку фото и видео VK разрешает только пользовательскому токену — ключ сообщества отвечает ошибкой 27, поэтому файл уходит публичной ссылкой. По умолчанию запись без даты отправляется сразу; ручную проверку можно отдельно включить слева.</p><a href="https://dev.vk.com/ru/method/wall.post" target="_blank" rel="noopener noreferrer">wall.post в документации VK ↗</a><hr><p>Для роликов добавьте право video, для списка своих групп — groups.</p></aside></div>`;
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
            <div class="vkt-toolbar-right">${button(`${icon('filter')} Фильтры${activeFilters() ? ` <span class="vkt-nav-count">${activeFilters()}</span>` : ''}`, 'posts-filters')}<div class="vkt-chips">${[['grid', 'Витрина', 'grid'], ['table', 'Таблица', 'table']].map(([value, label, name]) => `<button type="button" class="vkt-chip-button ${postsMode === value ? 'is-active' : ''}" data-command="posts-mode" data-value="${value}">${icon(name)} ${label}</button>`).join('')}</div></div></div>${postFilterPanel()}<div class="vkt-recognized-summary">Постов с распознанным товаром: <strong>${num(summary.recognized_posts || 0)}</strong><small>Название из вложения VK или со страницы магазина. С учётом фильтров.</small>${Number(postsData.reextract_pending) ? `<small>Обновляем товары в сохранённых постах: осталось ${num(postsData.reextract_pending)}.</small>` : ''}</div>${postsData.links && Number(postsData.links.total) ? `<div class="vkt-link-summary">${icon('cart')} Страницы магазинов во всей базе: <strong>${num(postsData.links.total)}</strong> · прочитано с товаром <strong>${num(postsData.links.recognized || 0)}</strong> · в очереди <strong>${num(postsData.links.waiting || 0)}</strong> · не удалось <strong>${num(postsData.links.failed || 0)}</strong><small>Магазины из списка читаются автоматически, остальные — по кнопке «Прочитать» в карточке.</small></div>` : ''}</section>` +
            (list.length ? (postsMode === 'grid'
                ? `<div class="vkt-post-grid">${list.map(postCard).join('')}</div>`
                : `<section class="vkt-panel vkt-table-wrap"><table class="vkt-posts-table"><thead><tr><th>Пост</th><th>Товары и цены</th><th>Охваты и динамика</th><th>Вовлечение</th><th></th></tr></thead><tbody>${list.map(postRow).join('')}</tbody></table></section>`)
                + `<div class="vkt-pagination">${button('← Назад', 'posts-prev', postsPage <= 1 ? 'disabled' : '')}<span>Страница ${postsPage} из ${Math.max(1, postsData.pages)}</span>${button('Далее →', 'posts-next', postsPage >= postsData.pages ? 'disabled' : '')}</div>`
                : empty(state.sources.length ? 'Постов пока нет' : 'Сначала добавьте сообщества', state.sources.length ? 'Запустите сбор — посты появятся после первого обхода стены. Если фильтры узкие, ослабьте их.' : 'Посты собираются со стен источников. Добавьте сообщества и запустите сбор.', state.sources.length ? 'collector' : 'sources', state.sources.length ? 'Перейти к сбору' : 'Добавить источники'));
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
        if (!ai.configured) { toast('Ключ xAI не задан в wp-config.php.', true); return; }
        const titles = {text: 'Текст записи', image: 'Изображение', video: 'Видео'};
        const hints = {
            text: 'Опишите, о чём пост. Уже набранный текст уйдёт как черновик для доработки.',
            image: 'Опишите кадр. Готовый файл попадёт в медиатеку сайта и сразу прикрепится к записи.',
            video: 'Опишите сцену. Ролик длится 6 секунд и генерируется 1–3 минуты.',
        };
        const models = {text: ai.text_model, image: ai.image_model, video: ai.video_model};
        const ratios = kind === 'image' ? ai.image_ratios : kind === 'video' ? ai.video_ratios : null;
        modal(`<h2>Генерация · ${titles[kind]}</h2><p class="vkt-muted">${hints[kind]} Модель: <code>${esc(models[kind] || '')}</code>.</p><form data-form="ai-${kind}" class="vkt-form"><label>Что нужно сделать<textarea name="prompt" rows="5" required minlength="3" maxlength="5000" placeholder="Например: анонс распродажи осенней коллекции">${esc(aiPrompt)}</textarea></label>${ratios ? `<label>Формат кадра<select name="ratio">${ratioOptions(ratios)}</select></label>` : ''}<div id="vkt-ai-progress" class="vkt-help" hidden></div><button class="vkt-button vkt-primary">${icon('fire')} Сгенерировать</button></form>`);
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
        return heading('Автопостинг', 'Создавайте записи и публикуйте их сразу или по расписанию в несколько своих групп.', button(`${icon('refresh')} Обновить мои группы`, 'publishing-sync', '', true) + button('Запустить очередь', 'publishing-run')) +
            (!publishingData.status.token_ready ? '<div class="vkt-info vkt-info-warning">Для публикации сохраните в настройках пользовательский токен VK ID с разрешениями <code>wall</code> и <code>groups</code>. Сервисный ключ умеет только читать стены.</div>' : '') +
            (publishingData.status.community_only ? '<div class="vkt-info">Подключён ключ одного сообщества. Нажмите «Обновить мои группы»: плагин добавит его как доступного адресата. Этот ключ публикует только на собственной стене и только текст — медиа VK ему запрещает.</div>' : '') +
            `<div class="vkt-stats">${stat('Своих групп', num(enabled.length), 'Включены и доступны для записи', 'people')}${stat('В очереди', num((totals.queued || 0) + (totals.scheduled || 0) + (totals.draft || 0)), state.settings.publishing_review ? 'Черновики ждут подтверждения' : 'Отправка сразу или по расписанию', 'check', 'purple')}${stat('Опубликовано', num(totals.published || 0), 'Полностью во все адресаты', 'send', 'green')}${stat('С ошибкой', num((totals.failed || 0) + (totals.partial || 0)), 'Можно повторить только неудачные адресаты', 'list', 'orange')}</div>` +
            `<div class="vkt-publishing-layout"><section class="vkt-panel"><div class="vkt-panel-heading"><div><h2>Новая запись</h2><p>Один текст можно подготовить сразу для нескольких сообществ.</p></div></div>${enabled.length ? `<form data-form="publishing" class="vkt-form"><fieldset class="vkt-publishing-groups"><legend>Куда публикуем</legend>${groupChoices}</fieldset><label>Текст записи<textarea name="message" rows="9" maxlength="16000" placeholder="Напишите текст поста…"></textarea></label>${aiReady ? `<div class="vkt-ai-row">${button(`${icon('fire')} Сгенерировать текст`, 'ai-text')}<small class="vkt-help">Черновик уйдёт в модель как основа.</small></div>` : ''}<fieldset class="vkt-media"><legend>Файлы с сервера</legend>${nativeMedia ? `<div id="vkt-composer-media" class="vkt-media-chips">${composerMediaHtml()}</div><div class="vkt-media-actions"><label class="vkt-button vkt-file"><input type="file" accept="image/*,video/mp4" data-media-upload hidden>${icon('plus')} Загрузить файл</label>${button(`${icon('layers')} Из медиатеки`, 'media-library')}${aiReady ? button(`${icon('fire')} Картинка`, 'ai-image') + button(`${icon('play')} Видео`, 'ai-video') : ''}</div><small class="vkt-help">До 10 файлов. Файл уходит в VK прямо с сервера: ID вложения плагин получает сам, отдельно для каждого сообщества.</small>` : '<div class="vkt-info vkt-info-warning">VK не разрешает ключу сообщества прикладывать медиа: загрузка фото закрыта ошибкой 27, видео — ошибкой 5, а ссылка на файл отклоняется кодом 100. Обходного пути нет. Сохраните в настройках пользовательский токен VK ID с правами <code>wall</code>, <code>photos</code>, <code>groups</code> и <code>video</code> — тогда файлы и генерация картинок станут доступны. Текст публикуется и сейчас.</div>'}</fieldset><label>Вложения VK или ссылка<textarea name="attachments" rows="3" class="vkt-code-input" placeholder="photo-123_456, video-123_789 или https://example.com"></textarea><small class="vkt-help">Необязательное поле для уже существующих вложений VK. До 10 ID через запятую; внешняя ссылка — только одна, и она должна вести на страницу с превью: прямой адрес картинки VK отклоняет.</small></label><label>Дата и время публикации<input type="datetime-local" name="scheduled_at"><small class="vkt-help">Оставьте пустым — запись отправится сразу после нажатия кнопки. Время вводится в часовом поясе вашего устройства.</small></label><div class="vkt-form-row"><label class="vkt-check"><input type="checkbox" name="signed">Подписать запись моим именем</label><label class="vkt-check"><input type="checkbox" name="close_comments">Закрыть комментарии</label></div><p class="vkt-help">${state.settings.publishing_review ? 'Включена ручная проверка: запись сначала сохранится черновиком.' : 'Ручная проверка выключена: запись без даты будет опубликована сразу.'}</p><button class="vkt-button vkt-primary">${icon('send')} ${state.settings.publishing_review ? 'Сохранить на проверку' : 'Опубликовать / запланировать'}</button></form>` : empty(groups.length ? 'Нет доступных групп' : 'Подключите свои сообщества', groups.length ? 'Обновите список: право редактора могло быть отозвано, либо все группы выключены.' : 'Нажмите «Обновить мои группы»: VK вернёт сообщества, где вы администратор или редактор.')}</section>` +
            `<aside class="vkt-panel"><div class="vkt-panel-heading"><div><h2>Мои сообщества</h2><p>Отдельный список: наблюдаемые источники не получают права записи.</p></div></div>${groups.length ? `<div class="vkt-own-groups">${groups.map(group => `<div><span>${safeUrl(group.photo || '') ? `<img src="${safeUrl(group.photo)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : ''}<strong>${esc(group.name || `club${group.group_id}`)}</strong><small><a href="https://vk.com/${esc(group.screen_name || `club${group.group_id}`)}" target="_blank" rel="noopener noreferrer">${esc(group.screen_name || `club${group.group_id}`)} ↗</a></small></span><span>${Number(group.can_post) ? badge(Number(group.enabled) ? 'Включено' : 'Выключено', Number(group.enabled) ? 'green' : '') : badge('Нет права записи', 'red')}${Number(group.can_post) ? button(Number(group.enabled) ? 'Выключить' : 'Включить', 'publishing-group-toggle', `data-id="${Number(group.id)}" data-enabled="${Number(group.enabled) ? 0 : 1}"`) : ''}</span></div>`).join('')}</div>` : '<p class="vkt-muted">Список ещё не загружен из VK.</p>'}</aside></div>` +
            `<div class="vkt-section-title"><h2>История и очередь</h2><span class="vkt-muted">Последняя проверка cron: ${date(publishingData.status.last)}</span></div>` +
            (posts.length ? `<section class="vkt-panel vkt-table-wrap"><table class="vkt-publishing-table"><thead><tr><th>Запись</th><th>Когда</th><th>Статус</th><th>Сообщества</th><th></th></tr></thead><tbody>${rows}</tbody></table></section>` : empty('Публикаций пока нет', 'Создайте первую запись: она появится здесь со статусом для каждой выбранной группы.'));
    }
    function render() {
        if (!state) return;
        root.querySelectorAll('[data-nav]').forEach(el=> { const active = el.dataset.nav===view; el.classList.toggle('is-active',active); if(active) el.setAttribute('aria-current','page'); else el.removeAttribute('aria-current'); });
        $('#vkt-breadcrumb').textContent = names[view];
        content.innerHTML = ({overview,discover,posts,communities,publishing,videos,products,sources,api,collector,logs,settings})[view]();
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
            } catch (error) { toast(error.message, true); }
            finally { el.disabled = false; }
            return;
        }
        if (command==='token-check') {
            el.disabled = true;
            try {
                const r = await act('token_check');
                const life = r.expires_in === null || r.expires_in === undefined ? 'без срока' : `осталось ~${Math.round(r.expires_in/60)} мин.`;
                modal(`<h2>Проверка токена</h2><p class="vkt-muted">Плагин отправил в VK <code>users.get</code> с тем токеном, который сохранён.</p>`
                    + `<div class="vkt-detail-stats"><span>Ответ VK<strong>${r.ok?'принят':'отклонён'}</strong></span><span>Тип<strong>${esc(modeLabel(r.mode)||r.mode||'—')}</strong></span><span>Срок<strong>${esc(life)}</strong></span></div>`
                    + `<h3>Что хранится</h3><p class="vkt-code-input">${esc(r.preview||'')} · ${Number(r.length)||0} символов · источник: ${esc(r.source||'')}</p>`
                    + `<h3>Ответ VK</h3><p>${r.ok?`Аккаунт: <strong>${esc(r.name||'')}</strong> (id ${Number(r.user_id)||0})`:`Код ${Number(r.vk_code)||0}: ${esc(r.message||'')}`}</p>`
                    + (r.ok?'':'<div class="vkt-info vkt-info-warning">Если код 5 — токен просрочен, отозван или сохранён не целиком. Сверьте огрызок выше с началом и концом того, что вставляли.</div>'));
            } catch (error) { toast(error.message, true); }
            finally { el.disabled = false; }
            return;
        }
        if (command==='oauth-open') {
            const url = state.settings.oauth?.authorize_url;
            if (!url) { toast('Сначала укажите ID приложения и сохраните настройки.', true); return; }
            window.open(url, '_blank', 'noopener');
            toast('Разрешите доступ, затем скопируйте адрес из браузера в поле ниже.');
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
        if (command==='media-remove') { composerMedia = composerMedia.filter(item => Number(item.id) !== Number(el.dataset.id)); renderComposerMedia(); return; }
        if (command==='media-pick') {
            const item = mediaLibraryItems.find(media => Number(media.id) === Number(el.dataset.id));
            if (addComposerMedia(item)) dialog.close();
            return;
        }
        if (command==='ai-text' || command==='ai-image' || command==='ai-video') { aiDialog(command.slice(3)); return; }
        if (command==='video-detail') { videoDetail(el.dataset.id); return; }
        if (command==='post-detail') { postDetail(el.dataset.id); return; }
        if (command==='posts-filters') { postsFiltersOpen = !postsFiltersOpen; render(); return; }
        if (command==='posts-mode') { postsMode = el.dataset.value === 'table' ? 'table' : 'grid'; render(); return; }
        if (command==='history-range') { historyRange = el.dataset.value in historyRanges ? el.dataset.value : '24h'; renderHistory(); return; }
        if (command==='communities-sort') { communitiesSort = el.dataset.value; render(); return; }
        el.disabled = true;
        try {
            if (command==='media-library') { await mediaLibrary(); return; }
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
            if (command==='publishing-sync') { const result=await act('publishing_sync'); toast(`Синхронизировано своих групп: ${result.synced}.`); }
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
        } catch (error) { toast(error.message,true); }
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
                    catch(error) { apiResult=error.payload || {error:error.message}; toast(error.message,true); }
                    await load(false); render(); return;
                }
                case 'settings': {
                    const hadToken = String(values.token || '').trim() !== '';
                    await act('settings',{...values,homepage:!!values.homepage,paused:!!values.paused,posts:!!values.posts,links:!!values.links,publishing_review:!!values.publishing_review,source_hours:Number(values.source_hours),video_hours:Number(values.video_hours)});
                    form.elements.token.value='';
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
                case 'oauth': {
                    const appId = Number(values.app_id) || 0;
                    if (!appId) throw new Error('Укажите ID приложения.');
                    await act('settings', {app_id: appId});
                    if (!String(values.code || '').trim()) { await load(); toast('ID приложения сохранён. Теперь нажмите «Открыть страницу согласия».'); return; }
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
                case 'media-search': await mediaLibrary(values.search || ''); return;
                case 'ai-text': {
                    aiPrompt = values.prompt;
                    const target = $('[data-form="publishing"] textarea[name="message"]');
                    const result = await act('ai_text',{prompt:values.prompt,current:target?.value || ''});
                    if (target) { target.value = result.text; target.dispatchEvent(new Event('input',{bubbles:true})); }
                    dialog.close();
                    toast('Текст готов — проверьте его перед отправкой.');
                    return;
                }
                case 'ai-image': {
                    aiPrompt = values.prompt;
                    addComposerMedia(await act('ai_image',{prompt:values.prompt,ratio:values.ratio || 'portrait'}));
                    dialog.close();
                    toast('Изображение сохранено в медиатеку и прикреплено к записи.');
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
                    toast(result.warning || (result.status === 'published' ? 'Запись опубликована.' : result.status === 'scheduled' ? 'Запись поставлена в расписание.' : 'Запись поставлена в очередь.'), !!result.warning);
                    break;
                }
                case 'import': {
                    const r = await act('sources_import',{list:values.list});
                    dialog.close();
                    toast(`Добавлено: ${r.added} из ${r.requested}. Уже были: ${r.resolved-r.added}. Не найдено в VK: ${r.missing}.`);
                    break;
                }
                case 'link': await act('link',{video_id:Number(values.video_id),product_id:Number(values.product_id)}); await load(false); videoDetail(values.video_id); toast('Товар привязан.'); return;
            }
            await load();
        } catch(error) { toast(error.message,true); }
        finally { if(submit) { submit.disabled=false; submit.removeAttribute('aria-busy'); } }
    });
    root.addEventListener('change',async event=> {
        if (event.target.matches('[data-media-upload]')) {
            const input = event.target;
            const file = input.files?.[0];
            input.value = '';
            if (!file) return;
            const label = input.closest('label');
            label?.classList.add('is-busy');
            try { addComposerMedia(await upload(file)); toast('Файл загружен и прикреплён к записи.'); }
            catch (error) { toast(error.message, true); }
            finally { label?.classList.remove('is-busy'); }
            return;
        }
        if (event.target.matches('[data-form="posts-search"] select[name="source"]')) { event.target.form.requestSubmit(); return; }
        if(event.target.id==='vkt-method') { apiMethod=event.target.value; apiDraft=null; render(); }
        if(event.target.name==='token_kind') { const fields=$('#vkt-user-token-fields'); if(fields) fields.hidden = event.target.value!=='user'; }
    });
    root.addEventListener('input',event=> { if(event.target.id==='vkt-params') apiDraft=event.target.value; });
    window.addEventListener('hashchange', async()=> {
        view=initial(); root.classList.remove('menu-open'); $('.vkt-menu').setAttribute('aria-expanded','false');
        if(dialog.open) dialog.close();
        if(view==='overview') { page=1; localSearch=''; sort='velocity'; }
        render();
        content.focus({preventScroll:true});
        try { await load(); } catch(error) { toast(error.message,true); }
    });
    if (config.notice?.message) {
        toast(config.notice.message, !!config.notice.error);
        try {
            const clean = new URL(location.href);
            clean.searchParams.delete('vkt_vkid');
            clean.searchParams.delete('vkt_vkid_message');
            history.replaceState(null, '', clean.href);
        } catch {}
    }
    load().catch(error=> { content.innerHTML=heading('Не удалось загрузить данные','Проверьте вход в WordPress и доступность REST API.')+`<div class="vkt-info">${esc(error.message)}</div>${button('Повторить','reload')}`; toast(error.message,true); });
})();

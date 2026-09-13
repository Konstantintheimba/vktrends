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
const context = {
    window: {vktConfig: {initialView: 'posts', rest: 'https://example.test/wp-json/vk-trends/v1/'}, addEventListener() {}},
    document: {getElementById: () => root}, location: {hash: '#posts'}, URL, URLSearchParams, Intl, Date,
    setTimeout: () => 0, clearTimeout() {}, FormData: function (form) { return Object.entries(form.values); },
    fetch: async url => { requests.push(new URL(url)); return {ok: true, json: async () => url.includes('/state?') ? state : data}; },
};
const source = fs.readFileSync(require.resolve('../assets/dashboard.js'), 'utf8');
// Replace only the initial async load with access to the real template functions.
const tail = source.lastIndexOf('    load().catch(');
assert(tail > 0);
vm.runInNewContext(source.slice(0, tail) + `
    window.testAPI = {postProduct, postRow, posts, viewData, init(s,d) {state=s;postsData=d;}, getSource() {return postsSource;}};
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
    listeners.change({target: {matches: () => true, form: {requestSubmit() {submitted = true;}}}});
    check(submitted, 'Changing the community submits the filter');
    const form = {dataset: {form: 'posts-search'}, values: {search: 'лампа', sort: 'views', source: '2'}, querySelector: () => null};
    await listeners.submit({target: {closest: () => form}, preventDefault() {}});
    check(api.getSource() === 2, 'Selected community ID stored');
    const query = requests.find(url => url.pathname.endsWith('/posts'));
    check(query.searchParams.get('source') === '2' && query.searchParams.get('page') === '1' && query.searchParams.get('search') === 'лампа', 'Request combines community, search, sort and reset pagination');
    check(api.posts().includes('value="2" selected'), 'Selection survives re-render');
    const button = {dataset: {command: 'posts-filters-clear'}, disabled: false};
    await listeners.click({target: {closest: () => button}});
    check(api.getSource() === 0, 'Clear filters restores all communities');
    console.log(`All ${checks} offline dashboard checks passed.`);
})().catch(error => {console.error(error); process.exitCode = 1;});

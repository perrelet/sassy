/**
 * Loads the shipped assets/js/sassy.js under a minimal DOM and exercises it.
 *
 * The file has no other coverage and the worst track record in the repository: three separate
 * bugs in one phase, each invisible to PHP and to a human reading it. Assertions go through the
 * surface the file actually exposes rather than through extracted fragments, so the wiring is
 * tested and not just the functions.
 *
 * Prints one JSON object of label => bool. tests/test-js.php relays it.
 */

const { readFileSync } = require('fs');

const results = {};
const ok = (label, condition) => { results[label] = !!condition; };

// A throw should name itself rather than taking the whole run down unexplained: this harness is
// pointed at older checkouts, where the shipped file may not have the surface being asserted.
process.on('uncaughtException', error => {
    results['the harness ran to completion'] = false;
    results['error: ' + String(error && error.message).slice(0, 120)] = false;
    process.stdout.write(JSON.stringify(results));
    process.exit(0);
});

// --- DOM enough to boot ------------------------------------------------------

function element (attrs = {}) {
    const el = {
        attrs,
        listeners: {},
        children: [],
        classList: { add () {}, remove () {} },
        dataset: {},
        style: {},
        getAttribute: n => (n in el.attrs ? el.attrs[n] : null),
        setAttribute: (n, v) => { el.attrs[n] = v; },
        addEventListener: (n, fn) => { el.listeners[n] = fn; },
        appendChild: c => el.children.push(c),
        prepend: c => el.children.unshift(c),
        querySelector: () => null,
        querySelectorAll: () => [],
        remove () {},
        click: () => el.listeners.click && el.listeners.click({ preventDefault () {} }),
    };
    return el;
}

const toggles = ['diagnostics', 'meta'].map(key => {
    const anchor = element({ rel: key });
    const item = element({});
    item.querySelector = () => anchor;
    item.anchor = anchor;
    item.key = key;
    return item;
});

const store = {};
global.localStorage = { getItem: k => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = v; } };

const fetched = [];
global.fetch = url => { fetched.push(url); return new Promise(() => {}); };

const dispatched = [];
global.CustomEvent = class { constructor (type, init) { this.type = type; Object.assign(this, init); } };

let keydown = null;
let click   = null;

const copied = [];
// Node 21+ ships a read-only navigator global, so a plain assignment is silently ignored.
Object.defineProperty(global, 'navigator', { configurable: true, writable: true, value: { clipboard: { writeText: text => { copied.push(text); return Promise.resolve(); } } } });

global.document = {
    body: element(),
    readyState: 'complete',
    getElementById: () => null,
    querySelector: () => null,
    querySelectorAll: sel => (String(sel).includes('sassy-log-toggle') ? toggles : []),
    createElement: () => element(),
    addEventListener: (name, fn) => { if (name === 'keydown') keydown = fn; if (name === 'click') click = fn; },
    dispatchEvent: e => dispatched.push(e.type),
};

global.window = { sass_params: { ajax_url: '/ajax', sassy_compile_nonce: 'n', keybinding: ['ctrl+space', 'meta+space'] } };
global.location = { href: 'http://test.local/', origin: 'http://test.local' };

// --- Load the real file ------------------------------------------------------

// The plugin under test, which is not necessarily the one this harness lives in: the suite can
// be pointed at an older checkout to prove a fix fails before it.
const plugin = process.argv[2] || (__dirname + '/../..');
const source = readFileSync(plugin.replace(/\/$/, '') + '/assets/js/sassy.js', 'utf8');
// Indirect eval, so a top-level declaration lands on the global object exactly as it would in a
// browser. new Function() would scope them locally and quietly make the stray-globals assertion
// below vacuous.
(0, eval)(source);

ok('the file boots under a bare DOM', typeof global.window.sassy === 'object');
ok('it leaves no stray globals behind', typeof global.renderDiagnostic === 'undefined');
ok('a keydown listener is registered', typeof keydown === 'function');

// --- The canonical rendering -------------------------------------------------

const frame = '   ╷\n54 │   unquote("calc(100%)")\n   │   ^^^^^^^^^^^^^^^^^^^^\n   ╵';
const trace = '    _harness.scss 54:13  contain()\n    frontend.scss 46:1  root stylesheet';

const render = global.window.sassy.render;

const rendered = render({
    severity: 'deprecation',
    message: 'Global built-in functions are deprecated.\nUse string.unquote instead.',
    file: '_harness.scss', line: 54, column: 13, frame, trace,
});

ok('header composes severity, location and the first message line',
    rendered.startsWith('DEPRECATION  _harness.scss:54:13  Global built-in functions are deprecated.'));
ok('the wrapped message line survives', rendered.includes('Use string.unquote instead.'));
ok('the frame is reproduced verbatim',  rendered.includes(frame));
ok('the trace is reproduced verbatim',  rendered.includes(trace));
ok('a plain string passes through',     render('an old-style warning') === 'an old-style warning');
ok('no position renders no location',   render({ severity: 'notice', message: 'fyi' }) === 'NOTICE  fyi');

// --- Logging toggles ---------------------------------------------------------

// WordPress puts meta['class'] on the li and meta['rel'] on the anchor. Reading the key off the
// wrong element made every toggle share one storage key and silently disabled the feature.
ok('a toggle starts off',            global.window.sassy.logging('diagnostics') === false);
toggles[0].anchor.click();
ok('clicking one turns it on',       global.window.sassy.logging('diagnostics') === true);
ok('and leaves the other alone',     global.window.sassy.logging('meta') === false);
ok('each toggle has its own key',    Object.keys(store).length === 1 && Object.keys(store)[0] === 'sassy:log:diagnostics');
toggles[0].anchor.click();
ok('clicking again turns it off',    global.window.sassy.logging('diagnostics') === false);

// --- The keybinding ----------------------------------------------------------

function press (over, target = 'BODY') {
    const before = fetched.length;
    keydown(Object.assign({
        key: ' ', ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, repeat: false,
        target: { nodeName: target },
        preventDefault () { this.defaulted = true; },
        stopImmediatePropagation () {},
    }, over));
    return fetched.length > before;
}

ok('ctrl+space compiles',                   press({ ctrlKey: true }));
ok('meta+space compiles',                   press({ metaKey: true }));
ok('space alone does not',                 !press({}));
ok('an unrequested modifier disqualifies', !press({ ctrlKey: true, altKey: true }));
ok('not while typing',                     !press({ ctrlKey: true }, 'INPUT'));
ok('a held key does not repeat',           !press({ ctrlKey: true, repeat: true }));
ok('the forced request is distinct',        String(fetched[fetched.length - 1]).includes('force=1') === false);

global.window.sassy.compile(true);
ok('compile(true) asks the server to force', String(fetched[fetched.length - 1]).includes('force=1'));
ok('and names the frontend context by default', String(fetched[fetched.length - 1]).includes('hooks=frontend'));

global.window.sassy.compile(false, 'all');
ok('compile(false, "all") asks for every context', String(fetched[fetched.length - 1]).includes('hooks=all') && !String(fetched[fetched.length - 1]).includes('force=1'));

global.window.sass_params.context = 'admin,editor';
global.window.sassy.compile();
ok('the served context is the default', String(fetched[fetched.length - 1]).includes('hooks=admin%2Ceditor'));

// --- reload ------------------------------------------------------------------

const sheets = [
    { href: 'http://test.local/wp-content/scss/frontend.css' },
    { href: 'https://cdn.example.com/other.css' },
];
document.querySelectorAll = sel => (String(sel).includes('stylesheet') ? sheets : []);

global.window.sassy.reload();

ok('a same-origin sheet is re-requested', sheets[0].href.includes('sassy='));
ok('a third-party sheet is left alone',  !sheets[1].href.includes('sassy='));
ok('reload announces itself',             dispatched.includes('sassy:reload'));

// --- The admin page's attributes ---------------------------------------------

ok('a click listener is registered', typeof click === 'function');

function clickOn (el) {
    el.closest = () => el;
    el.hasAttribute = n => n in el.attrs;
    click({ target: el, preventDefault () {} });
}

clickOn(element({ 'data-sassy-copy': '/srv/site/_x.scss:3:7' }));
ok('a location copies its full path', copied[copied.length - 1] === '/srv/site/_x.scss:3:7');

const canonical = element({ id: 'd1' });
canonical.textContent = 'ERROR  _x.scss:3:7  Undefined variable.\n  ╷\n3 │ a\n  ╵';
document.getElementById = id => (id === 'd1' ? canonical : null);
clickOn(element({ 'data-sassy-copy-from': 'd1' }));
ok('a copy button copies the canonical text verbatim', copied[copied.length - 1] === canonical.textContent);

const rows = ['managed', 'third-party', 'compilable'].map(kind => element({ 'data-kind': kind }));
const filters = ['all', 'managed'].map(kind => element({ 'data-sassy-filter': kind }));
document.querySelectorAll = sel => (String(sel).includes('data-kind') ? rows : (String(sel).includes('data-sassy-filter') ? filters : []));
clickOn(filters[1]);
ok('a filter hides the other kinds',   rows[1].hidden === true && rows[2].hidden === true && rows[0].hidden === false);
ok('and presses its own button',       filters[1].attrs['aria-pressed'] === 'true' && filters[0].attrs['aria-pressed'] === 'false');
clickOn(filters[0]);
ok('all shows everything again',       rows.every(r => r.hidden === false));

const before = fetched.length;
clickOn(element({ 'data-sassy-action': 'compile' }));
ok('Compile all forces every context', fetched.length === before + 1 && String(fetched[fetched.length - 1]).includes('hooks=all') && String(fetched[fetched.length - 1]).includes('force=1'));

// --- The change poll ---------------------------------------------------------

(async () => {

    const sheet = { href: 'http://test.local/wp-content/scss/frontend.css' };
    document.querySelectorAll = sel => (String(sel).includes('stylesheet') ? [sheet] : []);

    let hash = 'aaa';
    const answer = () => ({ success: true, data: { 'd-pace-frontend': { href: 'http://test.local/wp-content/scss/frontend.css', warnings: [], meta: { hash } } } });
    global.fetch = url => { fetched.push(url); return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(answer()) }); };

    const reloadsBefore = dispatched.filter(t => t === 'sassy:reload').length;

    await global.window.sassy.poll();
    ok('the first poll only records the hash', !sheet.href.includes('sassy='));
    ok('and does not force',                   !String(fetched[fetched.length - 1]).includes('force=1'));

    await global.window.sassy.poll();
    ok('an unchanged hash reloads nothing',    !sheet.href.includes('sassy='));

    hash = 'bbb';
    await global.window.sassy.poll();
    ok('a changed hash reloads that sheet',    sheet.href.includes('sassy=bbb'));
    ok('and announces the reload',             dispatched.filter(t => t === 'sassy:reload').length === reloadsBefore + 1);

    global.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: false, data: {} }) });
    const errors = [];
    console.error = (...args) => errors.push(args.join(' '));
    await global.window.sassy.poll();
    ok('a failing endpoint is reported, not retried silently', errors.some(e => e.includes('auto-reload stopped')));

    process.stdout.write(JSON.stringify(results));

})();

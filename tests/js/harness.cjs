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
    const classes = new Set();
    const el = {
        attrs,
        listeners: {},
        children: [],
        classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) },
        dataset: {},
        style: {},
        textContent: '',
        getAttribute: n => (n in el.attrs ? el.attrs[n] : null),
        setAttribute: (n, v) => { el.attrs[n] = v; },
        addEventListener: (n, fn) => { el.listeners[n] = fn; },
        appendChild: c => { c.parent = el; el.children.push(c); },
        prepend: c => { c.parent = el; el.children.unshift(c); },
        querySelector: () => null,
        // Enough of a selector engine for '.class' over direct children, which is what the panel uses.
        querySelectorAll: sel => (String(sel).startsWith('.') ? el.children.filter(c => c.classList.contains(String(sel).slice(1))) : []),
        remove () { if (el.parent) el.parent.children = el.parent.children.filter(c => c !== el); },
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
let domReady = null;
let windowLoad = null;

const copied = [];
// Node 21+ ships a read-only navigator global, so a plain assignment is silently ignored.
Object.defineProperty(global, 'navigator', { configurable: true, writable: true, value: { clipboard: { writeText: text => { copied.push(text); return Promise.resolve(); } } } });

// A fake CSSOM for the paintbrush: two Sassy sheets, one with a map and one without, and a
// nested rule so the flattening is exercised. Declared before the file loads so the baseline
// snapshot at init sees them, as a real page's would.
const rule  = (selectorText, cssText) => ({ selectorText, style: { cssText }, constructor: { name: 'CSSStyleRule' } });
const front = { href: 'http://test.local/wp-content/scss/frontend.css', ownerNode: {}, cssRules: [
    rule('.a', 'color: red;'),
    { constructor: { name: 'CSSMediaRule' }, cssText: '@media (min-width: 1px)', cssRules: [rule('.b', 'gap: 4px;')] },
    rule('.c', 'background: var(--x);'),
    rule('.d', 'padding: 1px;'),
] };
const nomap = { href: 'http://test.local/wp-content/scss/nomap.css', ownerNode: {}, cssRules: [rule('.n', 'color: red;')] };
const links = [
    Object.assign(element(), { href: front.href + '?ver=1', sheet: front }),
    Object.assign(element(), { href: nomap.href + '?ver=1', sheet: nomap }),
];
const managed = {
    front: { href: front.href, map: front.href + '.map', source: '/srv/site/wp-content/plugins/d-pace/scss/frontend.scss' },
    nomap: { href: nomap.href, map: null, source: '/srv/site/nomap.scss' },
};

const panel = element({ 'data-sassy-sheets': JSON.stringify(managed) });

global.document = {
    body: element(),
    // Still parsing when a footer script runs, and the panel prints after the scripts do.
    readyState: 'loading',
    getElementById: id => (id === 'sassy-errors' ? panel : null),
    querySelector: () => null,
    querySelectorAll: sel => (String(sel).includes('sassy-log-toggle') ? toggles : (String(sel).includes('stylesheet') ? links : [])),
    createElement: () => element(),
    addEventListener: (name, fn) => { if (name === 'keydown') keydown = fn; if (name === 'click') click = fn; if (name === 'DOMContentLoaded') domReady = fn; },
    dispatchEvent: e => { dispatched.push(e.type); lastEvent = e; },
    styleSheets: [front, nomap],
};
let lastEvent = null;

global.window = { sass_params: { ajax_url: '/ajax', sassy_compile_nonce: 'n', keybinding: ['ctrl+space', 'meta+space'] }, addEventListener: (name, fn) => { if (name === 'load') windowLoad = fn; } };
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

// The panel is printed after the footer scripts, so booting at parse time found nothing and
// Capture showed a toast and no panel. Boot waits for DOMContentLoaded.
ok('while the document is parsing, nothing boots yet', typeof global.window.sassy === 'undefined' && typeof domReady === 'function');
global.document.readyState = 'interactive';
domReady();
ok('the file boots on DOMContentLoaded', typeof global.window.sassy === 'object');
ok('and waits for the window to load before its baseline', typeof windowLoad === 'function');
global.document.readyState = 'complete';
windowLoad();

// --- The panel ---------------------------------------------------------------

const header = panel.children[0];
ok('the panel grew a header', header && header.id === 'sassy-errors-header');
const buttons = header.children[1].children;
ok('with Copy and Dismiss',   buttons.length === 2 && buttons[0].id === 'sassy-errors-copy' && buttons[1].id === 'sassy-errors-close');
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

// --- The change poll, and errors through the panel -----------------------------

const tick = () => new Promise(resolve => setTimeout(resolve, 0));

(async () => {

    // The file reports through console.error, and this harness reports through stdout JSON.
    const errors = [];
    console.error = (...args) => errors.push(args.join(' '));

    // Reach error() the way the endpoint does: a failed response with errors keyed by node.
    global.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ success: false, data: { 'sassy-x': 'ERROR  x.scss:1:1  Expected expression.' } }) });
    global.window.sassy.compile();
    await tick(); await tick(); await tick();

    ok('a compile error shows the panel',   panel.classList.contains('show') && panel.attrs['data-kind'] === 'error');
    ok('titled SCSS Error',                 header.children[0].textContent === 'SCSS Error');
    ok('with the error text in a block',    panel.querySelectorAll('.sassy-error').length === 1 && panel.querySelectorAll('.sassy-error')[0].textContent === 'ERROR  x.scss:1:1  Expected expression.');
    buttons[0].click();
    ok('Copy yields the panel text',        copied[copied.length - 1] === 'ERROR  x.scss:1:1  Expected expression.');
    buttons[1].click();
    ok('Dismiss hides it and empties it',   !panel.classList.contains('show') && panel.querySelectorAll('.sassy-error').length === 0);


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
    await global.window.sassy.poll();
    ok('a failing endpoint is reported, not retried silently', errors.some(e => e.includes('auto-reload stopped')));

    // --- The paintbrush ----------------------------------------------------------

    const inline = [];
    document.querySelectorAll = sel => (String(sel).includes('stylesheet') ? links : (String(sel) === '[style]' ? inline : []));

    ok('the baseline was taken at load', global.window.sassy.capture !== undefined && links[0].listeners.load !== undefined);

    // The sheet's text, and a map for it encoded here rather than trusted from the code under test.
    const text = '.a{color:red}@media (min-width:1px){.b{gap:4px}}.c{background:var(--x)}.d{padding:1px}';
    const off  = s => text.indexOf(s);
    const segments = [
        [off('.a{'), 0, 0, 0], [off('color:'), 0, 1, 2],
        [off('@media'), 0, 3, 0], [off('.b{'), 0, 4, 2], [off('gap:'), 0, 5, 4],
        [off('.c{'), 0, 7, 0], [off('background:'), 0, 8, 2],
        [off('.d{'), 0, 10, 0], [off('padding:'), 0, 11, 2],
    ];
    const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    const enc = n => { let v = n < 0 ? ((-n) << 1) | 1 : n << 1, out = ''; do { let d = v & 31; v >>= 5; if (v > 0) d |= 32; out += B64[d]; } while (v > 0); return out; };
    let prev = [0, 0, 0, 0];
    const mappings = segments.map(seg => { const rel = seg.map((v, i) => v - prev[i]); prev = seg; return rel.map(enc).join(''); }).join(',');
    const map = { version: 3, sources: ['../plugins/d-pace/scss/frontend.scss'], mappings };

    global.fetch = url => {
        url = String(url);
        if (url.endsWith('.map')) return Promise.resolve({ ok: true, json: () => Promise.resolve(map) });
        return Promise.resolve({ ok: true, arrayBuffer: () => Promise.resolve(new TextEncoder().encode(text).buffer) });
    };

    // Paint: one changed, one added, one shorthand changed, one removed, one in the mapless sheet,
    // a rule created in the inspector, and an inline style.
    front.cssRules[0].style.cssText = 'color: blue;';
    front.cssRules[1].cssRules[0].style.cssText = 'gap: 4px; margin: 0;';
    front.cssRules[2].style.cssText = 'background: red;';
    front.cssRules[3].style.cssText = '';
    nomap.cssRules[0].style.cssText = 'color: green;';
    document.styleSheets.push({ href: null, ownerNode: null, cssRules: [rule('.a', 'color: green;')] });
    inline.push(Object.assign(element({ style: 'color: red' }), { tagName: 'DIV', id: 'hero' }));

    const result = await global.window.sassy.capture();

    const expected = [
        'plugins/d-pace/scss/frontend.scss:2  .a',
        '  color: red → blue',
        'plugins/d-pace/scss/frontend.scss:5  .b',
        '  + margin: 0',
        'plugins/d-pace/scss/frontend.scss:9  .c',
        '  background: var(--x) → red',
        'plugins/d-pace/scss/frontend.scss:12  .d',
        '  - padding: 1px',
        'nomap (nomap.css)  .n',
        '  color: red → green',
        'inspector-stylesheet  .a  (no source location; try /srv/site/wp-content/plugins/d-pace/scss/frontend.scss)',
        '  + color: green',
        'element.style on <div#hero>  (no stylesheet)',
        '  color: red',
        'nomap: no source map for this sheet, so no lines; a post-processor may have removed its link.',
    ].join('\n');

    ok('the patch is exactly the plan\'s format', result.patch === expected);
    if (result.patch !== expected) results['got: ' + result.patch.replace(/\n/g, ' | ')] = false;
    ok('a changed declaration maps to its own line, not the rule\'s', result.patch.includes(':2  .a'));
    ok('an added declaration maps to the rule\'s line',             result.patch.includes(':5  .b'));
    ok('a shorthand stays a shorthand',                              result.patch.split('\n').filter(l => l.includes('background')).length === 1);
    ok('the panel shows it, titled and kinded',                      panel.classList.contains('show') && panel.attrs['data-kind'] === 'capture' && header.children[0].textContent === 'Captured styles');
    ok('the panel text is the patch',                                panel.querySelectorAll('.sassy-error').map(el => el.textContent).join('\n\n') === expected);
    ok('sassy:captured carries it',                                  lastEvent && lastEvent.type === 'sassy:captured' && lastEvent.detail.patch === expected && lastEvent.detail.changes.length === 5);
    buttons[0].click();
    ok('Copy yields the patch',                                      copied[copied.length - 1] === expected);

    // A rule the text does not have: lines are withheld for that sheet, not invented.
    front.cssRules.push(rule('.e', 'color: pink;'));
    const misaligned = await global.window.sassy.capture();
    ok('a misaligned sheet withholds lines',  misaligned.patch.includes('front: its text and its rules did not align (5 blocks, 6 rules)'));
    ok('and names the sheet instead',         misaligned.patch.includes('front (frontend.css)  .e') && misaligned.patch.includes('front (frontend.css)  .a'));

    // A reload re-baselines: what was painted is now the sheet, so nothing is a change.
    front.cssRules.pop();
    links[0].listeners.load(); links[1].listeners.load();
    document.styleSheets.pop();
    inline.length = 0;
    const quiet = await global.window.sassy.capture();
    ok('after a sheet reloads, the paint is the new baseline', quiet.patch === 'Nothing changed since the last snapshot.' && quiet.changes.length === 0);

    process.stdout.write(JSON.stringify(results));

})();

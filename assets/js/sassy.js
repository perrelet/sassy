(() => {

    'use strict';

    /**
     * Mirrors Sassy\Diagnostic::render(): the header is ours, everything below it is the engine's
     * own drawing and is reproduced verbatim.
     */
    function renderDiagnostic (d) {

        if (typeof d === 'string') return d;

        const where = d.file ? d.file + (d.line ? ':' + d.line + (d.column ? ':' + d.column : '') : '') + '  ' : '';
        const lines = String(d.message || '').split(/\r?\n/);
        const parts = [d.severity.toUpperCase() + '  ' + where + lines[0]];

        if (lines.length > 1 && lines.slice(1).join('\n').trim()) parts.push(lines.slice(1).join('\n'));
        if (d.frame) parts.push(d.frame);
        if (d.trace) parts.push(d.trace);

        return parts.join('\n');

    }

    const sassy = {

        init (params) {

            this.params = params;

            const notice = document.createElement('div');
            notice.id = 'sassy-notice';
            document.body.appendChild(notice);

            this.els = {
                errors: document.getElementById('sassy-errors'),
                adminMenu: document.querySelector('#wp-admin-bar-sassy > a'),
                notice,
            };

            if (this.els.errors) this.buildPanel();

            this.noticeTimeout = null;

            this.addEventListeners();
            this.bindLogToggles();
            this.bindPage();
            this.syncPoll();
            this.armPaintbrush();

            // The declared surface, replacing the Angular reach-in a builder used to need.
            window.sassy = {
                compile: (force, hooks) => this.liveCompile(force === true, hooks),
                reload:  () => this.reloadSheets(),
                render:  (diagnostic) => renderDiagnostic(diagnostic),
                logging: (key) => this.logging(key),
                poll:    () => this.poll(),
                capture: () => this.capture(),
            };

        },

        /**
         * One panel for everything that needs a monospace block and a Copy: compile errors, and
         * the paintbrush's patch. The header follows the content.
         */
        buildPanel () {

            const header  = document.createElement('div');
            const title   = document.createElement('span');
            const actions = document.createElement('span');
            const copy    = document.createElement('button');
            const close   = document.createElement('button');

            header.id     = 'sassy-errors-header';
            title.id      = 'sassy-errors-title';
            copy.id       = 'sassy-errors-copy';
            close.id      = 'sassy-errors-close';
            copy.type     = close.type = 'button';
            copy.textContent  = 'Copy';
            close.textContent = 'Dismiss';
            title.textContent = 'SCSS Error';

            copy.addEventListener('click',  () => this.copy(this.panelText(), copy));
            close.addEventListener('click', () => this.clearErrors());

            actions.appendChild(copy);
            actions.appendChild(close);
            header.appendChild(title);
            header.appendChild(actions);
            this.els.errors.prepend(header);

            this.els.title = title;
            this.els.copy  = copy;

            if (!this.canCopy()) copy.hidden = true;

        },

        /**
         * Show the panel with these blocks. kind lands on the element for the stylesheet.
         */
        panel (title, blocks, kind) {

            if (!this.els.errors) return;

            this.els.title.textContent = title;
            this.els.errors.setAttribute('data-kind', kind);
            this.els.errors.querySelectorAll('.sassy-error').forEach(el => el.remove());

            for (const block of blocks) {
                const pre = document.createElement('pre');
                pre.classList.add('sassy-error');
                pre.textContent = block;
                this.els.errors.appendChild(pre);
            }

            this.els.errors.classList.add('show');

        },

        panelText () {

            if (!this.els.errors) return '';

            return Array.from(this.els.errors.querySelectorAll('.sassy-error')).map(el => el.textContent).join('\n\n');

        },

        /**
         * The admin page declares its behaviour in attributes; one delegated listener serves
         * them all. Copy buttons hide themselves where the clipboard API is absent (plain http)
         * rather than fail on click.
         */
        bindPage () {

            this.reloadAfterCompile = false;

            if (!this.canCopy()) {
                document.querySelectorAll('button[data-sassy-copy], button[data-sassy-copy-from]').forEach(el => { el.hidden = true; });
            }

            document.addEventListener('click', event => this.onClick(event));

        },

        canCopy () {

            return typeof navigator !== 'undefined' && !!(navigator.clipboard && navigator.clipboard.writeText);

        },

        onClick (event) {

            const target = event.target && event.target.closest
                ? event.target.closest('[data-sassy-copy], [data-sassy-copy-from], [data-sassy-action], [data-sassy-filter]')
                : null;

            if (!target) return;

            if (target.hasAttribute('data-sassy-copy')) {
                event.preventDefault();
                this.copy(target.getAttribute('data-sassy-copy'), target);
            } else if (target.hasAttribute('data-sassy-copy-from')) {
                event.preventDefault();
                const source = document.getElementById(target.getAttribute('data-sassy-copy-from'));
                if (source) this.copy(source.textContent, target);
            } else if (target.getAttribute('data-sassy-action') === 'compile') {
                event.preventDefault();
                this.reloadAfterCompile = true;
                this.liveCompile(true, 'all');
            } else if (target.hasAttribute('data-sassy-filter')) {
                event.preventDefault();
                this.filterStack(target.getAttribute('data-sassy-filter'));
            }

        },

        copy (text, el) {

            if (!this.canCopy()) return;

            navigator.clipboard.writeText(text).then(() => {
                this.showNotice('✔ Copied', 'success');
                if (el && el.setAttribute) {
                    el.setAttribute('data-copied', '1');
                    setTimeout(() => el.removeAttribute && el.removeAttribute('data-copied'), 1500);
                }
            }, () => this.showNotice('✗ Copy failed', 'error'));

        },

        filterStack (kind) {

            document.querySelectorAll('[data-sassy-filter]').forEach(button => {
                button.setAttribute('aria-pressed', button.getAttribute('data-sassy-filter') === kind ? 'true' : 'false');
            });

            document.querySelectorAll('.sassy-stack tbody tr[data-kind]').forEach(row => {
                row.hidden = kind !== 'all' && row.getAttribute('data-kind') !== kind;
            });

        },

        /**
         * Console logging is opt-in and per-browser: a shared default would spam a colleague's
         * console on a site they merely happen to be logged into.
         */
        logging (key) {

            try { return localStorage.getItem('sassy:log:' + key) === '1'; }
            catch (e) { return false; }

        },

        bindLogToggles () {

            document.querySelectorAll('#wp-admin-bar-sassy-logging .sassy-log-toggle').forEach(item => {

                const link = item.querySelector('a') || item;
                const key  = link.getAttribute('rel');

                if (!key) return;

                const reflect = () => link.setAttribute('data-on', this.logging(key) ? '1' : '0');
                reflect();

                link.addEventListener('click', event => {
                    event.preventDefault();
                    try { localStorage.setItem('sassy:log:' + key, this.logging(key) ? '0' : '1'); } catch (e) {}
                    reflect();
                    this.syncPoll();
                });

            });

        },

        /**
         * The change poll, opt-in from Logging → Auto-reload. Asks the same endpoint Live Compile
         * does, so it sees a partial edited on disk, a compile from another tab or from wp sassy
         * watch, and swaps only the sheets whose content hash moved.
         */
        syncPoll () {

            const wanted = this.logging('reload');

            if (wanted && !this.pollTimer) {
                this.hashes = {};
                this.pollTimer = setInterval(() => this.poll(), 2000);
            } else if (!wanted && this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }

        },

        poll () {

            if (this.polling) return Promise.resolve();
            if (typeof document.hidden !== 'undefined' && document.hidden) return Promise.resolve();

            this.polling = true;
            this.hashes  = this.hashes || {};

            const context = this.params.context || 'frontend';
            const url = `${this.params.ajax_url}?action=sassy_compile&nonce=${this.params.sassy_compile_nonce}&hooks=${encodeURIComponent(context)}`;

            return fetch(url, { credentials: 'same-origin' })
                .then(response => (response.ok ? response.json() : null))
                .then(payload => {

                    if (!payload || !payload.success) {
                        // Logged out, or a compile error: stop rather than knock every two seconds.
                        console.error('Sassy: auto-reload stopped; the compile endpoint did not answer cleanly.', payload && payload.data);
                        if (this.pollTimer) { clearInterval(this.pollTimer); this.pollTimer = null; }
                        return;
                    }

                    let swapped = false;

                    for (const handle in payload.data) {

                        if (!Object.prototype.hasOwnProperty.call(payload.data, handle)) continue;

                        const entry = payload.data[handle];
                        const hash  = entry && entry.meta ? entry.meta.hash : null;
                        const known = this.hashes[handle];

                        this.hashes[handle] = hash;

                        if (!hash || known === undefined || known === hash) continue;

                        const link = this.sheetFor(entry.href);
                        if (!link) continue;

                        const next = new URL(link.href);
                        next.searchParams.set('sassy', hash);
                        link.href = next.toString();
                        swapped = true;

                    }

                    if (swapped) this.emit('sassy:reload');

                })
                .catch(err => console.error('Sassy: auto-reload request failed.', err))
                .then(() => { this.polling = false; });

        },

        sheetFor (href) {

            if (typeof href !== 'string' || !href) return null;

            for (const link of document.querySelectorAll('link[rel="stylesheet"]')) {
                if (link.href.includes(href)) return link;
            }

            return null;

        },

        // --- Paintbrush, tier 1: capture and copy ------------------------------------------

        /**
         * Which sheets are Sassy's, from the panel: the printers exist only once the head has
         * printed, after the localized params were written. Baselines live here per handle.
         */
        managed () {

            if (this.sheets) return this.sheets;

            let list = {};
            try { list = JSON.parse((this.els.errors && this.els.errors.getAttribute('data-sassy-sheets')) || '{}') || {}; } catch (e) {}

            this.sheets = {};

            for (const handle in list) {
                if (!Object.prototype.hasOwnProperty.call(list, handle) || !list[handle] || !list[handle].href) continue;
                this.sheets[handle] = Object.assign({ handle, baseline: null, mapping: undefined }, list[handle]);
            }

            return this.sheets;

        },

        linkFor (sheet) {

            return this.sheetFor(sheet.href);

        },

        /**
         * Snapshot once the sheets have loaded, and again whenever one reloads: Live Compile and
         * the poll swap a link's href, and the next capture would otherwise report the whole
         * compile as a paint.
         */
        armPaintbrush () {

            const sheets = this.managed();
            const all    = () => { for (const handle in sheets) this.snapshot(handle); };

            if (document.readyState === 'complete') all();
            else if (typeof window.addEventListener === 'function') window.addEventListener('load', all);

            for (const handle in sheets) {
                const link = this.linkFor(sheets[handle]);
                if (link && link.addEventListener) link.addEventListener('load', () => { this.snapshot(handle); sheets[handle].mapping = undefined; });
            }

            this.inlineBaseline = this.inlineStyles();

        },

        rulesOf (sheet) {

            const link = this.linkFor(sheet);

            try { return this.flatten(link && link.sheet ? link.sheet.cssRules : []); }
            catch (e) { return []; }

        },

        snapshot (handle) {

            const sheet = this.managed()[handle];
            if (!sheet) return 0;

            sheet.baseline = this.rulesOf(sheet).map(rule => this.declarations(rule));

            return sheet.baseline.length;

        },

        /**
         * Every rule in document order, nested ones included, so the list aligns with the blocks
         * in the sheet's text. Statements without a block are skipped on both sides.
         */
        flatten (rules, out = []) {

            for (const rule of rules || []) {
                const kind = rule && rule.constructor ? rule.constructor.name : '';
                if (kind === 'CSSImportRule' || kind === 'CSSNamespaceRule' || kind === 'CSSLayerStatementRule') continue;
                out.push(rule);
                if (rule.cssRules) this.flatten(rule.cssRules, out);
            }

            return out;

        },

        /**
         * As the rule serialises them: the CSSOM expands background: red into nine longhands
         * with empty baselines, and cssText gives the shorthand back.
         */
        declarations (rule) {

            return this.parseDeclarations(rule && rule.style ? rule.style.cssText : '');

        },

        parseDeclarations (cssText) {

            const out = {};
            let depth = 0, quote = null, start = 0;

            const push = end => {
                const decl  = cssText.slice(start, end).trim();
                const colon = decl.indexOf(':');
                if (colon > 0) out[decl.slice(0, colon).trim()] = decl.slice(colon + 1).trim();
            };

            for (let i = 0; i < cssText.length; i++) {
                const c = cssText[i];
                if (quote) { if (c === '\\') i++; else if (c === quote) quote = null; continue; }
                if (c === '"' || c === "'") quote = c;
                else if (c === '(') depth++;
                else if (c === ')') depth--;
                else if (c === ';' && depth === 0) { push(i); start = i + 1; }
            }

            push(cssText.length);

            return out;

        },

        diff (was, now) {

            const changes = [];

            for (const prop of new Set([...Object.keys(was), ...Object.keys(now)])) {
                if (was[prop] === now[prop]) continue;
                changes.push({ prop, from: prop in was ? was[prop] : null, to: prop in now ? now[prop] : null });
            }

            return changes;

        },

        /**
         * The sheet's text and map, fetched on the first capture that needs them and kept. Bytes,
         * not text: Dart writes a byte-order mark in compressed mode that the map counts as
         * column 0 and Response.text() strips.
         */
        mapping (sheet) {

            if (sheet.mapping !== undefined) return Promise.resolve(sheet.mapping);

            sheet.mapping = null;

            if (!sheet.map) return Promise.resolve(null);

            const link = this.linkFor(sheet);
            const href = link ? link.href : sheet.href;

            return fetch(href, { credentials: 'same-origin' })
                .then(response => response.arrayBuffer())
                .then(bytes => {
                    const text = new TextDecoder('utf-8', { ignoreBOM: true }).decode(bytes);
                    return fetch(sheet.map, { credentials: 'same-origin' }).then(r => r.json()).then(map => {
                        sheet.mapping = { text, blocks: this.blocks(text), lines: this.decodeMap(String(map.mappings || '')), sources: map.sources || [] };
                        return sheet.mapping;
                    });
                })
                .catch(() => { sheet.mapping = null; return null; });

        },

        /** Twenty lines. Segments carry absolute values by the time they are stored. */
        decodeMap (mappings) {

            const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
            const lines = [];
            let src = 0, oline = 0, ocol = 0;

            for (const encoded of mappings.split(';')) {
                const segs = []; let col = 0;
                for (const seg of encoded.split(',')) {
                    if (!seg) continue;
                    const vals = []; let shift = 0, value = 0;
                    for (const ch of seg) {
                        const d = B64.indexOf(ch); value += (d & 31) << shift; shift += 5;
                        if (!(d & 32)) { vals.push(value & 1 ? -(value >> 1) : value >> 1); value = 0; shift = 0; }
                    }
                    col += vals[0];
                    if (vals.length > 1) { src += vals[1]; oline += vals[2]; ocol += vals[3]; }
                    segs.push({ col, src, oline, ocol });
                }
                lines.push(segs);
            }

            return lines;

        },

        /**
         * Every block in the text in order: depth-aware over strings, comments and nested
         * at-rules, so index n here is rule n in the flattened CSSOM.
         */
        blocks (css) {

            const blocks = [];
            let i = 0, preludeStart = 0, quote = null;

            while (i < css.length) {
                const c = css[i];
                if (quote) { if (c === '\\') i++; else if (c === quote) quote = null; }
                else if (c === '"' || c === "'") quote = c;
                else if (c === '/' && css[i + 1] === '*') { const end = css.indexOf('*/', i + 2); i = end < 0 ? css.length : end + 1; preludeStart = i + 1; }
                else if (c === '{') { const lead = css.slice(preludeStart, i); blocks.push({ start: preludeStart + Math.max(0, lead.search(/\S/)), prelude: lead.trim() }); preludeStart = i + 1; }
                else if (c === '}' || c === ';') preludeStart = i + 1;
                i++;
            }

            return blocks;

        },

        locate (mapping, offset) {

            const before = mapping.text.slice(0, offset);
            const line   = (before.match(/\n/g) || []).length;
            const col    = offset - (before.lastIndexOf('\n') + 1);
            let hit = null;

            for (const s of mapping.lines[line] || []) { if (s.col <= col) hit = s; else break; }

            if (!hit) return null;

            // Dart cites relative to the map, scssphp cites a URL; show a path either way and
            // keep the engine's own string for the server.
            const source = String(mapping.sources[hit.src] || '');
            let file = source.replace(/^(\.\.\/)+/, '');
            if (typeof location !== 'undefined' && location.origin && file.startsWith(location.origin + '/')) file = file.slice(location.origin.length + 1);

            return { file, line: hit.oline + 1, source };

        },

        /** The declaration's own position where the block's text has it, else the block's. */
        declarationOffset (mapping, index, prop) {

            const block = mapping.blocks[index];
            const next  = mapping.blocks[index + 1];
            const end   = next ? next.start : mapping.text.length;
            const at    = mapping.text.indexOf(prop + ':', block.start);

            return at > -1 && at < end ? at : block.start;

        },

        capture () {

            const sheets  = this.managed();
            const changes = [];
            const reasons = {};
            const counts  = {};

            for (const handle in sheets) {
                const sheet = sheets[handle];
                if (!sheet.baseline) this.snapshot(handle);
                const rules = this.rulesOf(sheet);
                counts[handle] = rules.length;
                rules.forEach((rule, index) => {
                    const now = this.declarations(rule), was = sheet.baseline[index] || {};
                    const selector = rule.selectorText || String(rule.cssText || '').split('{')[0].trim();
                    for (const change of this.diff(was, now)) changes.push(Object.assign({ handle, index, selector, location: null }, change));
                });
            }

            const located = Promise.all(Object.keys(sheets).filter(h => changes.some(c => c.handle === h)).map(handle => {
                const sheet = sheets[handle];
                return this.mapping(sheet).then(mapping => {
                    if (!mapping) { reasons[handle] = 'no source map for this sheet, so no lines; a post-processor may have removed its link.'; return; }
                    if (mapping.blocks.length !== counts[handle]) { reasons[handle] = `its text and its rules did not align (${mapping.blocks.length} blocks, ${counts[handle]} rules), so lines are withheld rather than wrong.`; return; }
                    for (const change of changes) {
                        if (change.handle !== handle) continue;
                        change.location = this.locate(mapping, this.declarationOffset(mapping, change.index, change.prop));
                    }
                });
            }));

            return located.then(() => {

                const extras = this.sharpEdges();
                const patch  = this.patch(changes, extras, reasons);

                this.panel('Captured styles', [patch], 'capture');
                this.showNotice(changes.length || extras.length ? '🖌️ Captured' : '🖌️ Nothing changed', 'success');
                if (this.logging('capture')) console.log(patch);
                this.emit('sassy:captured', { patch, changes, extras });

                return { patch, changes, extras };

            });

        },

        /**
         * Text first, markup second. One header per rule and source line, the plan's format:
         *   file:line  selector
         *     prop: old → new
         */
        patch (changes, extras, reasons) {

            const groups = new Map();

            for (const c of changes) {
                const sheet = this.managed()[c.handle];
                const where = c.location ? `${c.location.file}:${c.location.line}` : `${c.handle} (${String(sheet.href).split('/').pop().split('?')[0]})`;
                const key   = `${c.handle}:${c.index}:${where}`;
                if (!groups.has(key)) groups.set(key, [`${where}  ${c.selector}`]);
                const rows = groups.get(key);
                if (c.from === null)    rows.push(`  + ${c.prop}: ${c.to}`);
                else if (c.to === null) rows.push(`  - ${c.prop}: ${c.from}`);
                else                    rows.push(`  ${c.prop}: ${c.from} → ${c.to}`);
            }

            const lines = [];
            for (const rows of groups.values()) lines.push(...rows);
            for (const extra of extras) lines.push(extra.text, ...extra.rows);
            for (const handle in reasons) lines.push(`${handle}: ${reasons[handle]}`);

            return lines.length ? lines.join('\n') : 'Nothing changed since the last snapshot.';

        },

        /**
         * The plan's sharp edges: a rule created in the inspector has no source location, and an
         * element.style edit has no stylesheet. Both listed, both copy-only.
         */
        sharpEdges () {

            const extras = [];

            for (const sheet of document.styleSheets || []) {
                if (sheet.href || sheet.ownerNode) continue;
                let rules; try { rules = sheet.cssRules; } catch (e) { continue; }
                for (const rule of rules || []) {
                    const suggest = this.suggestFor(rule.selectorText);
                    extras.push({
                        kind: 'inspector',
                        text: `inspector-stylesheet  ${rule.selectorText || rule.cssText}  (no source location${suggest ? '; try ' + suggest : ''})`,
                        rows: Object.entries(this.declarations(rule)).map(([prop, value]) => `  + ${prop}: ${value}`),
                    });
                }
            }

            for (const [el, style] of this.inlineStyles()) {
                // A script that sets and clears a property leaves style="" behind; that is not a paint.
                if (!String(style || '').trim()) continue;
                if ((this.inlineBaseline && this.inlineBaseline.get(el)) === style) continue;
                extras.push({ kind: 'inline', text: `element.style on <${this.describe(el)}>  (no stylesheet)`, rows: [`  ${style}`] });
            }

            return extras;

        },

        inlineStyles () {

            return new Map(Array.from(document.querySelectorAll('[style]')).map(el => [el, el.getAttribute('style')]));

        },

        suggestFor (selector) {

            const sheets = Object.values(this.managed());

            if (selector) {
                for (const sheet of sheets) {
                    if (this.rulesOf(sheet).some(rule => rule.selectorText === selector)) return sheet.source;
                }
            }

            return sheets.length ? sheets[0].source : null;

        },

        describe (el) {

            const classes = el.classList && el.classList.length ? '.' + Array.from(el.classList).join('.') : '';

            return String(el.tagName || '').toLowerCase() + (el.id ? '#' + el.id : '') + classes;

        },

        /**
         * Re-request every same-origin stylesheet. What window.sassy.reload() is for: swapping
         * the CSS without recompiling and without a page load.
         */
        reloadSheets () {

            document.querySelectorAll('link[rel="stylesheet"]').forEach(link => {

                let href;
                try { href = new URL(link.href, location.href); } catch (e) { return; }
                if (href.origin !== location.origin) return;

                href.searchParams.set('sassy', Date.now().toString());
                link.href = href.toString();

            });

            this.emit('sassy:reload');

        },

        emit (name, detail) {

            document.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));

        },

        addEventListeners () {

            const liveCompileButton = document.getElementById('wp-admin-bar-sassy-live-compile');
            if (liveCompileButton) {
                liveCompileButton.addEventListener('click', () => this.liveCompile());
            }

            const forceButton = document.getElementById('wp-admin-bar-sassy-force-compile');
            if (forceButton) {
                forceButton.addEventListener('click', () => this.liveCompile(true));
            }

            const captureButton = document.getElementById('wp-admin-bar-sassy-capture');
            if (captureButton) {
                captureButton.addEventListener('click', () => this.capture());
            }

            document.addEventListener('keydown', this.onKeyDown.bind(this));

        },

        onKeyDown (event) {

            // Not while typing. The collision with IME and autocomplete has bitten in practice,
            // which is also why the binding is filterable at all.
            if (event.target && event.target.nodeName !== 'BODY') return;

            if (!this.matchesBinding(event)) return;

            // Claim the combination before the repeat guard: a held ctrl+shift+k should not open
            // the browser's console on the second keydown just because we only compile on the
            // first.
            event.stopImmediatePropagation();
            event.preventDefault();

            if (event.repeat) return;

            this.liveCompile();

        },

        /**
         * Each binding is "+"-separated modifiers plus a key, e.g. ctrl+space. The server sends
         * false to disable the binding entirely, leaving the admin bar button as the trigger.
         */
        matchesBinding (event) {

            const bindings = this.params.keybinding;

            if (!Array.isArray(bindings) || !bindings.length) return false;

            const key = (event.key === ' ' ? 'space' : (event.key || '').toLowerCase());

            return bindings.some(binding => {

                const parts = String(binding).toLowerCase().split('+').map(p => p.trim()).filter(Boolean);
                const wants = parts.pop();

                if (wants !== key) return false;

                const held = {
                    ctrl:  event.ctrlKey,
                    meta:  event.metaKey,
                    alt:   event.altKey,
                    shift: event.shiftKey,
                };

                // Exact: a modifier the binding did not ask for disqualifies it.
                return Object.keys(held).every(modifier => held[modifier] === parts.includes(modifier));

            });

        },

        /**
         * hooks defaults to the context this page was served in, so Live Compile in wp-admin
         * rebuilds the sheet on screen rather than the frontend's.
         */
        liveCompile (force = false, hooks = null) {

            const context = hooks || this.params.context || 'frontend';
            const url = `${this.params.ajax_url}?action=sassy_compile&nonce=${this.params.sassy_compile_nonce}&hooks=${encodeURIComponent(context)}${force ? '&force=1' : ''}`;

            this.clearErrors();
            this.showNotice('⚡ Compiling\u2026', 'pending');
            this.emit('sassy:before-compile');

            fetch(url, { credentials: 'same-origin' })
                .then(response => {

                    if (response.status === 401) {
                        const error = 'Sassy: 401 Unauthorized Access.';
                        console.error(error);
                        this.error([error]);
                        return null;
                    }

                    if (!response.ok) {
                        const error = 'Sassy: An unexpected error occurred.';
                        console.error(error);
                        this.error([error]);
                        return null;
                    }

                    return response.json();

                })
                .then(payload => {

                    if (!payload) return;

                    if (payload.success) {

                        const hadWarnings = this.reloadStyles(payload.data);

                        this.emit('sassy:compiled', {
                            diagnostics: Object.fromEntries(Object.entries(payload.data).map(([h, e]) => [h, e.warnings || []])),
                            styles: payload.data,
                        });
                        this.showNotice(hadWarnings ? '⚠ Compiled with warnings' : '✔ Compiled', hadWarnings ? 'warning' : 'success');

                        // The dashboard's Compile all: its tables are server-rendered, so show
                        // the new state the only way they can.
                        if (this.reloadAfterCompile) location.reload();

                    } else {

                        console.error('Sassy: Oops, something went wrong.');
                        console.error(payload.data);
                        this.error(payload.data);

                    }

                })
                .catch(err => {

                    const error = 'Sassy: An unexpected error occurred.';
                    console.error(error, err);
                    this.error([error]);

                });

        },

        reloadStyles (styles) {

            if (!styles) return false;

            let hadWarnings = false;

            const links = document.querySelectorAll('link[rel="stylesheet"]');

            for (const link of links) {

                for (const property in styles) {

                    if (!Object.prototype.hasOwnProperty.call(styles, property)) continue;

                    const entry = styles[property];

                    let href = null;
                    let warnings = [];
                    let meta = null;

                    if (entry && typeof entry === 'object') {
                        if (typeof entry.href === 'string' && entry.href.length > 0) {
                            href = entry.href;
                        }
                        if (Array.isArray(entry.warnings)) {
                            warnings = entry.warnings;
                        }
                        if (entry.meta && typeof entry.meta === 'object') {
                            meta = entry.meta;
                        }
                    } else if (typeof entry === 'string' && entry.length > 0) {
                        href = entry;
                    }

                    if (!href) {
                        continue;
                    }

                    if (link.href.includes(href)) {

                        const newHref = new URL(link.href);
                        newHref.searchParams.set('sassy', (meta && meta.hash) || Date.now().toString());
                        link.href = newHref.toString();

                        if (meta && this.logging('meta')) console.info(`Sassy compile info for ${property}:`, meta);

                        if (warnings.length) {
                            hadWarnings = true;
                            const block = warnings.map(renderDiagnostic).join('\n\n');
                            if (this.logging('diagnostics')) console.warn(`Sassy warnings for ${property}:\n${block}`);
                        }

                    }

                }

            }

            return hadWarnings ? 'warning' : false;

        },

        error (errors) {

            this.showNotice('\u2717 Error', 'error');

            if (this.els.adminMenu) this.els.adminMenu.innerHTML = '❌ SCSS';

            const blocks = [];

            for (const instance in errors) {
                if (Object.prototype.hasOwnProperty.call(errors, instance)) blocks.push(String(errors[instance]));
            }

            this.panel('SCSS Error', blocks, 'error');

        },

        clearErrors () {

            if (this.els.adminMenu) {

                this.els.adminMenu.innerHTML = 'SCSS';

            }

            if (this.els.errors) {

                this.els.errors.classList.remove('show');
                this.els.errors.querySelectorAll('.sassy-error').forEach(el => el.remove());

            }

        },

        showNotice (message, state) {

            if (!this.els.notice) return;

            if (this.noticeTimeout) {
                clearTimeout(this.noticeTimeout);
                this.noticeTimeout = null;
            }

            this.els.notice.textContent = message;
            this.els.notice.dataset.state = state || 'pending';
            this.els.notice.classList.add('show');

            if (state !== 'pending') {
                const delay = state === 'error' ? 4000 : 2000;
                this.noticeTimeout = setTimeout(() => this.hideNotice(), delay);
            }

        },

        hideNotice () {

            if (!this.els.notice) return;
            this.els.notice.classList.remove('show');

        },

    };

    // A footer script runs while the document is still parsing, and the panel prints after it
    // at wp_footer 30. Boot once the markup is all there.
    const boot = () => sassy.init(window.sass_params || {});

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();

})();

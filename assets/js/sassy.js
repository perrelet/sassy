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

            if (this.els.errors) {
                const header = document.createElement('div');
                header.id = 'sassy-errors-header';
                header.innerHTML = '<span>SCSS Error</span><button id="sassy-errors-close" type="button">Dismiss</button>';
                this.els.errors.prepend(header);
                header.querySelector('#sassy-errors-close').addEventListener('click', () => this.clearErrors());
            }

            this.noticeTimeout = null;

            this.addEventListeners();
            this.bindLogToggles();
            this.bindPage();

            // The declared surface, replacing the Angular reach-in a builder used to need.
            window.sassy = {
                compile: (force, hooks) => this.liveCompile(force === true, hooks),
                reload:  () => this.reloadSheets(),
                render:  (diagnostic) => renderDiagnostic(diagnostic),
                logging: (key) => this.logging(key),
            };

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
                });

            });

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

            if (this.els.adminMenu) {

                this.els.adminMenu.innerHTML = '❌ SCSS';

            }

            if (this.els.errors) {

                this.els.errors.querySelectorAll('.sassy-error').forEach(el => el.remove());

                for (const instance in errors) {

                    if (!Object.prototype.hasOwnProperty.call(errors, instance)) continue;

                    const error = errors[instance];

                    const errorNode = document.createElement('pre');
                    errorNode.classList.add('sassy-error');
                    errorNode.appendChild(document.createTextNode(error));

                    this.els.errors.appendChild(errorNode);

                }

                this.els.errors.classList.add('show');

            }

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

    sassy.init(window.sass_params || {});

})();

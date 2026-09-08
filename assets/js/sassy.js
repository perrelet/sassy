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

(() => {

    'use strict';

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

        },

        addEventListeners () {

            const liveCompileButton = document.getElementById('wp-admin-bar-sassy-live-compile');
            if (liveCompileButton) {
                liveCompileButton.addEventListener('click', () => this.liveCompile());
            }

            const keydownCallback = this.debounce(this.onKeyDown.bind(this), 250);

            if ((this.params.builder === 'oxygen') && this.params.backend) {

                // Oxygen builder uses Angular in both iframe and builder window.
                angular.element('body').on('keydown', keydownCallback);            // iframe
                parent.angular.element('body').on('keydown', keydownCallback);     // builder

            } else {

                document.addEventListener('keydown', keydownCallback);

            }

        },

        onKeyDown (event) {

            if ((this.params.builder === 'oxygen') && this.params.backend && event.originalEvent && event.originalEvent.repeat) return;
            if (!event.ctrlKey && !event.metaKey) return;
            if (event.target && event.target.nodeName !== 'BODY') return;

            let processed = false;
            const key = (event.key || '').toLowerCase();

            switch (key) {

                case ' ':
                    this.liveCompile();
                    processed = true;
                    break;

            }

            if (processed) {
                event.stopImmediatePropagation();
                event.preventDefault();
            }

        },

        liveCompile () {

            const url = `${this.params.ajax_url}?action=sassy_compile&nonce=${this.params.sassy_compile_nonce}&sassy-recompile=1`;

            this.clearErrors();
            this.showNotice('⚡ Compiling\u2026', 'pending');

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
                        this.showNotice(hadWarnings ? '⚠ Compiled with warnings' : '✔ Compiled', hadWarnings ? 'warning' : 'success');

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
                        newHref.searchParams.set('sassy', Math.random().toString());
                        link.href = newHref.toString();

                        if (meta) {
                            console.info(`Sassy compile info for ${property}:`, meta);

                            const engineItem = document.querySelector(`#wp-admin-bar-sassy-${meta.index}-engine .ab-item`);
                            if (engineItem) {
                                const engineLabel = (meta.engine || '').replace(/^Sassy\\/, '').replace(/_Engine$/, '').replace(/_/g, ' ');
                                const compileMs   = meta.compile_time ? Math.round(meta.compile_time * 1000) + 'ms' : '\u2014';
                                engineItem.textContent = `${engineLabel} \u2014 ${compileMs}`;
                            }
                        }

                        if (warnings.length) {
                            hadWarnings = true;
                            const block = warnings.map(renderDiagnostic).join('\n\n');
                            console.log(`Successfully Recompiled: ${href}`);
                            console.warn(`Sassy warnings for ${property}:\n${block}`);
                            const menuItem = document.querySelector(`#wp-admin-bar-sassy-${meta && meta.index ? meta.index : ''} [data-state]`);
                            if (menuItem) menuItem.setAttribute('data-state', 'warning');
                        } else {
                            console.log(`Successfully Recompiled: ${href}`);
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

                    const menuItem = document.querySelector(`#wp-admin-bar-sassy-${instance} [data-state]`);
                    if (menuItem) menuItem.setAttribute('data-state', 'error');

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

            const menuItems = document.querySelectorAll('#wpadminbar .sassy-file [data-state]');

            menuItems.forEach(menuItem => {
                menuItem.setAttribute('data-state', 'compiled');
            });

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

        debounce (callback, delay) {

            let timeoutId;

            return function debounced (...args) {

                const context = this;

                if (timeoutId) {
                    clearTimeout(timeoutId);
                }

                timeoutId = setTimeout(() => {
                    timeoutId = null;
                    callback.apply(context, args);
                }, delay);

            };

        },

    };

    sassy.init(window.sass_params || {});

})();

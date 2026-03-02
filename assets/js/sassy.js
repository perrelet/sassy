(() => {

    'use strict';

    const sassy = {

        init (params) {

            this.params = params;

            this.els = {
                errors: document.getElementById('sassy-errors'),
                adminMenu: document.querySelector('#wp-admin-bar-sassy > a'),
            };

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

                        this.reloadStyles(payload.data);

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

            if (!styles) return;

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

                        console.log(`Successfully Recompiled: ${href}`);

                        if (meta) {
                            console.info(`Sassy compile info for ${property}:`, meta);
                        }

                        if (warnings.length) {
                            const block = warnings.join('\n');
                            console.warn(`Sassy warnings for ${property}:\n${block}`);
                        }

                    }

                }

            }

        },

        error (errors) {

            if (this.els.adminMenu) {

                this.els.adminMenu.innerHTML = '❌ SCSS';

            }

            if (this.els.errors) {

                this.els.errors.innerHTML = '';

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
                this.els.errors.innerHTML = '';

            }

            const menuItems = document.querySelectorAll('#wpadminbar .sassy-file [data-state]');

            menuItems.forEach(menuItem => {
                menuItem.setAttribute('data-state', 'compiled');
            });

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

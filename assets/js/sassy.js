(function(params) {

    var sassy = {

        params: null,
        els: {
            errors: null
        },

        init: function (params) {

            this.params = params;

            this.els.errors = document.getElementById('sassy-errors');
            this.els.admin_menu = document.querySelector('#wp-admin-bar-sassy > a');

            this.add_event_listeners();

        },

        add_event_listeners: function () {

            let live_compile = document.getElementById('wp-admin-bar-sassy-live-compile');
            if (live_compile) live_compile.addEventListener('click', this.live_compile.bind(this));

            //

            var keydown_callback = this.debounce(this.onkeydown.bind(this), 250);

            if ((this.params.builder == 'oxygen') && this.params.backend) {

                angular.element('body').on('keydown', keydown_callback);            // iframe
                parent.angular.element('body').on('keydown', keydown_callback);     // builder

            } else {

                document.addEventListener('keydown', keydown_callback);

            }

        },

        onkeydown: function (event) {

            if ((this.params.builder == 'oxygen') && this.params.backend && event.originalEvent.repeat) return;
            if (!event.ctrlKey && !event.metaKey) return;
            if (event.target.nodeName != 'BODY') return;

            var processed = false;
            var key = event.key.toLowerCase();

            switch (key) {

                case " ":
                    this.live_compile();
                    processed = true;

            }

            if (processed) {
                event.stopImmediatePropagation();
                event.preventDefault();
            }

        },

        live_compile: function () {

            var http = new XMLHttpRequest();

            http.onreadystatechange = function() {

                if (http.readyState == XMLHttpRequest.DONE) {

                    let error = null;

                    switch (http.status) {

                        case 200:

                            let response = JSON.parse(http.responseText);

                            if (response.success) {

                                this.reload_styles(response.data);

                            } else {

                                console.error('Sassy: Oops, something went wrong.');
                                console.error(response.data);
                                this.error(response.data);

                            }

                            break;

                        case 401:

                            error = 'Sassy: 401 Unauthorized Access.';
                            console.error(error);
                            this.error([error]);
                            break;

                        default:

                            error = 'Sassy: An unexpected error occurred.';
                            console.error(error);
                            this.error([error]);

                    }

                }

            }.bind(this);
        
            http.open('GET', this.params.ajax_url + "?action=sassy_compile&nonce=" + this.params.sassy_compile_nonce + "&sassy-recompile=1", true);
            http.send();
            this.clear_errors();

        },

        reload_styles: function (styles = false) {
            
            let links = document.getElementsByTagName("link");

            for (const cl in links) {

                let link = links[cl];
                if (link.rel === "stylesheet") {

                    if (styles === false) {

                        link.href += "";

                    } else {

                        if (styles) for (const property in styles) {

                            if (link.href.includes(styles[property])) {
                                
                                link.href += "";
                                console.log("Successfully Recompiled: " + styles[property]);

                            }
    
                        }

                    }

                }

            }

        },

        error: function (errors) {

            if (this.els.admin_menu) {

                this.els.admin_menu.innerHTML = "❌ SCSS";

            }

            if (this.els.errors) {

                this.els.errors.innerHTML = "";

                for (const instance in errors) {

                    let error = errors[instance];

                    const error_node = document.createElement("pre");
                    error_node.classList.add('sassy-error');
                    error_node.appendChild(document.createTextNode(error));

                    this.els.errors.appendChild(error_node);

                    let menu_item = document.querySelector('#wp-admin-bar-sassy-' + instance + ' [data-state]');
                    if (menu_item) menu_item.setAttribute('data-state', 'error');

                }

                this.els.errors.classList.add('show');

            }

        },

        clear_errors: function () {

            if (this.els.admin_menu) {

                this.els.admin_menu.innerHTML = "SCSS";

            }

            if (this.els.errors) {

                this.els.errors.classList.remove('show');
                this.els.errors.innerHTML = "";

            }

            let menu_items = document.querySelectorAll('#wpadminbar .sassy-file [data-state]');
            if (menu_items.length > 0) for (var i = 0, menu_item; menu_item = menu_items[i]; i++) menu_item.setAttribute('data-state', 'compiled');

        },

        //

        debounce: function (callback, delay) {

            var timeout;
            return function () {
                var context = this;
                var args = arguments;
                if (timeout) {
                    clearTimeout(timeout);
                }
                timeout = setTimeout(function () {
                    timeout = null;
                    callback.apply(context, args);
                }, delay);
            }

        }

    }

    sassy.init(params);

})(sass_params);

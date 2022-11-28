(function(params) {

    var sassy = {

        params: null,

        init: function (params) {

            this.params = params;

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

                    if (http.status == 200) {

                        let response = JSON.parse(http.responseText);

                        if (response.success) {
                            this.reload_styles(response.data);
                        } else {
                            console.error('Sassy: Oops, something went wrong.', response);
                        }                        

                    } else {

                        console.error('Sassy: Oops, something went wrong.');
                        
                    }

                }

            }.bind(this);
        
            http.open('GET', this.params.ajax_url + "?action=sassy_compile&nonce=" + this.params.sassy_compile_nonce + "&sassy-recompile=1", true);
            http.send();

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

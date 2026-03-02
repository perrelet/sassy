<?php

namespace Sassy;

class Digitalis extends Integration {

    public function condition() {

        return defined("DIGITALIS_FRAMEWORK_VERSION");

    }

    public function get_variables () {

        return [
            'digitalis_path' => '"' . wp_normalize_path(DIGITALIS_FRAMEWORK_PATH) . '"',
            'digitalis_uri'  => '"' . wp_normalize_path(DIGITALIS_FRAMEWORK_URI) . '"',
        ];

    }

}
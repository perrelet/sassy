<?php

namespace Sassy;

/**
 * Is Sassy's dev surface active for this request?
 *
 * One question, one filter. Sassy models nothing about environments or roles: anything
 * expressible in PHP is expressible in the filter, and gates are about who rather than where.
 */
class Policy {

    public static function active () {

        return (bool) apply_filters('sassy-dev', current_user_can('edit_theme_options'));

    }

    /**
     * The stricter gate for writing to source files. Never implied by sassy-dev.
     */
    public static function can_write_source () {

        return static::active() && (bool) apply_filters('sassy-write-source', false);

    }

}

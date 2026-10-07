<?php

use Akibeo\Cacher\Cacher;
use Kirby\Cms\App;

if (function_exists('cacher') === false) {
    /**
     * Returns the Cacher for the current Kirby instance
     */
    function cacher(App|null $kirby = null): Cacher
    {
        return new Cacher($kirby ?? App::instance());
    }
}

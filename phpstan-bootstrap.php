<?php

require_once __DIR__.'/vendor/larastan/larastan/bootstrap.php';

if (! defined('LARAVEL_VERSION')) {
    if (class_exists(\Illuminate\Foundation\Application::class)) {
        $app = new \Illuminate\Foundation\Application(__DIR__);
        define('LARAVEL_VERSION', $app->version());
    } else {
        define('LARAVEL_VERSION', '12.0');
    }
}

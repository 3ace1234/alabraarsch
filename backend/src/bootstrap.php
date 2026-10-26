<?php

declare(strict_types=1);

use AlFalaah\Core\Env;
use AlFalaah\Core\Logger;

define('ALFALAAH_ROOT', dirname(__DIR__));
define('ALFALAAH_SRC', ALFALAAH_ROOT . '/src');
define('ALFALAAH_STORAGE', ALFALAAH_ROOT . '/storage');

spl_autoload_register(static function (string $class): void {
    $prefix = 'AlFalaah\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = ALFALAAH_SRC . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load(ALFALAAH_ROOT . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos'));

foreach ([
    ALFALAAH_STORAGE,
    ALFALAAH_STORAGE . '/logs',
    ALFALAAH_STORAGE . '/sessions',
] as $directory) {
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
}

Logger::init(ALFALAAH_STORAGE . '/logs/app.log');

<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class Logger
{
    private static string $path = '';

    public static string $requestId = '';

    public static function init(string $path): void
    {
        self::$path = $path;
    }

    public static function setRequestId(string $requestId): void
    {
        self::$requestId = $requestId;
    }

    public static function debug(string $message, array $context = []): void
    {
        if (Env::bool('APP_DEBUG', false)) {
            self::write('debug', $message, $context);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$path === '') {
            return;
        }

        $record = [
            'time' => date('c'),
            'level' => $level,
            'request_id' => self::$requestId !== '' ? self::$requestId : null,
            'message' => $message,
            'context' => $context,
        ];

        $line = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($line !== false) {
            @file_put_contents(self::$path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}

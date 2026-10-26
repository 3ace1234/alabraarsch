<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class Response
{
    public static function ok(mixed $data, int $status = 200, ?array $meta = null, array $headers = []): never
    {
        $payload = ['data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        self::json($payload, $status, $headers);
    }

    public static function error(
        string $code,
        string $message,
        int $status,
        ?array $fields = null,
        array $headers = []
    ): never {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        self::json(['error' => $error], $status, $headers);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('X-API-Version: ' . Env::get('API_VERSION', '1'));

        if (Logger::$requestId !== '') {
            header('X-Request-Id: ' . Logger::$requestId);
        }

        exit;
    }

    public static function json(mixed $payload, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('X-API-Version: ' . Env::get('API_VERSION', '1'));

        if (Logger::$requestId !== '') {
            header('X-Request-Id: ' . Logger::$requestId);
        }

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        exit;
    }
}

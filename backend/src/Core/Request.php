<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class Request
{
    private static ?array $body = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return trim((string) $_SERVER[$key]);
        }

        return match (strtoupper($name)) {
            'CONTENT_TYPE' => isset($_SERVER['CONTENT_TYPE']) ? trim((string) $_SERVER['CONTENT_TYPE']) : null,
            'CONTENT_LENGTH' => isset($_SERVER['CONTENT_LENGTH']) ? trim((string) $_SERVER['CONTENT_LENGTH']) : null,
            default => null,
        };
    }

    public static function ip(): string
    {
        // Do not trust X-Forwarded-For unless a trusted reverse proxy is
        // explicitly configured at the web-server layer.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '127.0.0.1';
    }

    public static function userAgent(): ?string
    {
        $value = self::header('User-Agent');

        return $value !== null ? mb_substr($value, 0, 255) : null;
    }

    public static function origin(): ?string
    {
        return self::header('Origin');
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** Query-string values can be arrays (?page[]=1). Only accept scalars. */
    public static function queryScalar(string $key): ?string
    {
        $value = $_GET[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public static function queryString(): string
    {
        $query = $_SERVER['QUERY_STRING'] ?? '';

        return is_string($query) ? $query : '';
    }

    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }

        $raw = file_get_contents('php://input');
        $raw = $raw === false ? '' : trim($raw);

        if ($raw === '') {
            self::$body = $_POST !== [] ? $_POST : [];
            return self::$body;
        }

        $contentType = strtolower((string) self::header('Content-Type'));
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            self::$body = $_POST;
            return self::$body;
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpException::badRequest('The request body must contain valid JSON.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw HttpException::badRequest('The request body must be a JSON object.');
        }

        self::$body = $decoded;

        return self::$body;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $body = self::body();

        return $body[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::body());
    }

    public static function isWrite(): bool
    {
        return in_array(self::method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}

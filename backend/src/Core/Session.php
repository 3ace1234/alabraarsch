<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $savePath = ALFALAAH_STORAGE . '/sessions';
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0775, true);
        }
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        $secure = Env::bool('SESSION_SECURE', self::isHttps());
        $sameSite = ucfirst(strtolower(Env::get('SESSION_SAMESITE', 'Lax')));
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }
        if ($sameSite === 'None' && !$secure) {
            $sameSite = 'Lax';
        }

        session_name(Env::get('SESSION_NAME', 'alfalaah_portal_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Env::get('SESSION_COOKIE_PATH', '/'),
            'domain' => Env::get('SESSION_COOKIE_DOMAIN', ''),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (Env::int('SESSION_LIFETIME', 120) * 60));

        if (!@session_start()) {
            throw HttpException::server('The session could not be started.');
        }

        if (!isset($_SESSION['created_at'])) {
            $_SESSION['created_at'] = time();
        }

        // Do not refresh last_activity here: authenticated requests must be able
        // to detect an idle timeout before Auth touches the session again.
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function touch(): void
    {
        $_SESSION['last_activity'] = time();
    }

    public static function checkTimeout(): bool
    {
        $lifetime = Env::int('SESSION_LIFETIME', 120) * 60;
        $lastActivity = (int) self::get('last_activity', time());

        if ($lifetime > 0 && (time() - $lastActivity) > $lifetime) {
            self::destroy();
            return false;
        }

        self::touch();

        return true;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        self::touch();
    }

    public static function csrfToken(): string
    {
        $token = self::get('csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            self::put('csrf_token', $token);
        }

        return $token;
    }

    public static function rotateCsrf(): string
    {
        $token = bin2hex(random_bytes(32));
        self::put('csrf_token', $token);

        return $token;
    }

    public static function validateCsrf(?string $token): bool
    {
        $expected = self::get('csrf_token');

        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
            session_destroy();
        }
    }

    private static function isHttps(): bool
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}

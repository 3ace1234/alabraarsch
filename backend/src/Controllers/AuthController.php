<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Audit;
use AlFalaah\Core\Auth;
use AlFalaah\Core\Csrf;
use AlFalaah\Core\Database;
use AlFalaah\Core\Env;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Logger;
use AlFalaah\Core\Request;
use AlFalaah\Core\Response;
use AlFalaah\Core\Session;
use AlFalaah\Core\Validator;

final class AuthController
{
    private const LOGIN_WINDOW_MINUTES = 15;
    private const MAX_EMAIL_FAILURES = 5;
    private const MAX_IP_FAILURES = 20;

    /** A real bcrypt hash used to equalise timing when an email is unknown. */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public static function session(): never
    {
        Session::start();
        $user = Auth::user();

        Response::ok([
            'csrf_token' => Csrf::token(),
            'user' => $user !== null ? Auth::present($user) : null,
            'server_time' => date('c'),
            'api_version' => Env::get('API_VERSION', '1'),
        ]);
    }

    public static function login(): never
    {
        Session::start();
        $data = Request::body();
        $email = Validator::email($data['email'] ?? null);
        $password = Validator::string($data['password'] ?? null, 'password', 1, 200);
        $ip = Request::ip();

        self::guardLogin($email, $ip);

        $user = Database::fetch(
            'SELECT id, uuid, email, password_hash, role, first_name, last_name, phone, status,
                    password_changed_at, last_login_at
             FROM users
             WHERE email = ?
             LIMIT 1',
            [$email]
        );

        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $passwordValid = password_verify($password, $hash);

        if ($user === null || !$passwordValid) {
            self::recordAttempt($email, $ip, false);
            Logger::warning('Failed portal sign-in', ['email' => $email, 'ip' => $ip]);
            throw new HttpException(401, 'invalid_credentials', 'Email or password is incorrect.');
        }

        if ($user['status'] !== 'active') {
            self::recordAttempt($email, $ip, false);
            throw new HttpException(
                403,
                'account_inactive',
                'This account is not active. Please contact the school office.'
            );
        }

        // Upgrade old hashes opportunistically without changing the password.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::execute(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $user['id']]
            );
        }

        Database::execute(
            'DELETE FROM login_attempts WHERE email = ? AND success = 0',
            [$email]
        );
        self::recordAttempt($email, $ip, true);
        Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);

        Auth::login((int) $user['id']);
        Audit::record('auth.login', 'user', $user['uuid'], [], (int) $user['id']);

        $fresh = Auth::user();
        if ($fresh === null) {
            throw HttpException::server('The signed-in account could not be loaded.');
        }

        Response::ok([
            'user' => Auth::present($fresh),
            'csrf_token' => Csrf::token(),
            'redirect' => 'dashboard',
        ]);
    }

    public static function logout(): never
    {
        $user = Auth::requireUser();
        Audit::record('auth.logout', 'user', $user['uuid'], [], (int) $user['id']);
        Auth::logout();

        Response::noContent();
    }

    public static function me(): never
    {
        $user = Auth::requireUser();

        Response::ok([
            'user' => Auth::present($user),
            'csrf_token' => Csrf::token(),
        ]);
    }

    public static function changePassword(): never
    {
        $user = Auth::requireUser();
        $data = Request::body();

        $current = Validator::string($data['current_password'] ?? null, 'current_password', 1, 200);
        $password = Validator::password($data['password'] ?? null, 'password');
        $confirmation = Validator::string($data['password_confirmation'] ?? null, 'password_confirmation', 1, 200);

        if (!password_verify($current, (string) $user['password_hash'])) {
            throw HttpException::validation('Your current password is incorrect.', [
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        if ($password !== $confirmation) {
            throw HttpException::validation('The new passwords do not match.', [
                'password_confirmation' => 'The passwords do not match.',
            ]);
        }

        if (password_verify($password, (string) $user['password_hash'])) {
            throw HttpException::validation('Choose a password you have not used before.', [
                'password' => 'The new password must be different from the current one.',
            ]);
        }

        Database::execute(
            'UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]
        );

        // Keep this session valid while invalidating every other session whose
        // auth_time predates the password change. Use the database timestamp as
        // well as PHP time so the comparison is not sensitive to clock drift.
        $changedAt = (int) Database::fetchValue(
            'SELECT UNIX_TIMESTAMP(password_changed_at) FROM users WHERE id = ?',
            [(int) $user['id']]
        );
        Session::put('auth_time', max(time(), $changedAt));
        Session::rotateCsrf();
        Auth::refresh();
        Audit::record('auth.password_changed', 'user', $user['uuid'], [], (int) $user['id']);

        $fresh = Auth::user();
        if ($fresh === null) {
            throw HttpException::server('The account could not be reloaded.');
        }

        Response::ok([
            'user' => Auth::present($fresh),
            'csrf_token' => Csrf::token(),
        ]);
    }

    private static function guardLogin(string $email, string $ip): void
    {
        $emailFailures = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND success = 0
               AND attempted_at > (NOW() - INTERVAL ' . self::LOGIN_WINDOW_MINUTES . ' MINUTE)',
            [$email]
        );

        if ($emailFailures >= self::MAX_EMAIL_FAILURES) {
            throw HttpException::tooManyRequests(
                'Too many failed attempts for this email. Please wait 15 minutes and try again.',
                self::LOGIN_WINDOW_MINUTES * 60
            );
        }

        $ipFailures = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip = ? AND success = 0
               AND attempted_at > (NOW() - INTERVAL ' . self::LOGIN_WINDOW_MINUTES . ' MINUTE)',
            [$ip]
        );

        if ($ipFailures >= self::MAX_IP_FAILURES) {
            throw HttpException::tooManyRequests(
                'Too many failed attempts from this network. Please wait 15 minutes and try again.',
                self::LOGIN_WINDOW_MINUTES * 60
            );
        }
    }

    private static function recordAttempt(string $email, string $ip, bool $success): void
    {
        Database::insert(
            'INSERT INTO login_attempts (email, ip, success) VALUES (?, ?, ?)',
            [$email, $ip, $success ? 1 : 0]
        );

        // Keep the audit table small without needing a cron job.
        if (random_int(1, 100) === 1) {
            Database::execute(
                'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)'
            );
        }
    }
}

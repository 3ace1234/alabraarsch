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
use AlFalaah\Services\AccountSecurity;

final class AuthController
{
    private const LOGIN_WINDOW_MINUTES = 15;
    private const MAX_EMAIL_FAILURES = 5;
    private const MAX_IP_FAILURES = 20;

    /** Session key holding the pending two-factor challenge. */
    private const TWO_FACTOR_SESSION_KEY = 'two_factor_challenge';

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
                    password_changed_at, two_factor_enabled, two_factor_secret, last_login_at
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

        if (AccountSecurity::twoFactorEnabled($user)) {
            Response::ok(self::beginTwoFactor($user));
        }

        self::completeLogin($user, 'password');
    }

    /**
     * Completes a pending two-factor challenge. The password was already
     * verified by login(), so no credentials are accepted here: only the code.
     */
    public static function verifyTwoFactor(): never
    {
        Session::start();
        $data = Request::body();
        $code = Validator::string($data['code'] ?? null, 'code', 6, 6);

        if (!preg_match('/^\d{6}$/', $code)) {
            throw HttpException::validation('Enter the six-digit code from your email.', [
                'code' => 'The code must be six digits.',
            ]);
        }

        $challenge = self::challenge();
        if ($challenge === null) {
            throw new HttpException(
                401,
                'two_factor_expired',
                'Your sign-in code has expired. Please sign in again.'
            );
        }

        if ((int) $challenge['attempts'] >= AccountSecurity::TWO_FACTOR_MAX_ATTEMPTS) {
            self::clearChallenge();
            throw HttpException::tooManyRequests(
                'Too many incorrect codes. Please sign in again.',
                0
            );
        }

        $user = self::challengeUser((int) $challenge['user_id']);

        if (!AccountSecurity::verifyTwoFactorCode((string) $user['two_factor_secret'], $code)) {
            $challenge['attempts'] = (int) $challenge['attempts'] + 1;
            Session::put(self::TWO_FACTOR_SESSION_KEY, $challenge);

            // Count bad codes with failed passwords so an attacker who already
            // knows the password cannot start a fresh challenge for unlimited
            // guesses. A successful sign-in clears these rows.
            self::recordAttempt((string) $user['email'], Request::ip(), false);
            Logger::warning('Failed two-factor code', [
                'user' => $user['uuid'],
                'ip' => Request::ip(),
            ]);

            $remaining = AccountSecurity::TWO_FACTOR_MAX_ATTEMPTS - (int) $challenge['attempts'];
            if ($remaining <= 0) {
                self::clearChallenge();
                throw HttpException::tooManyRequests(
                    'Too many incorrect codes. Please sign in again.',
                    0
                );
            }

            throw new HttpException(
                401,
                'two_factor_invalid',
                'That code is incorrect. You have ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' left.'
            );
        }

        self::clearChallenge();
        self::completeLogin($user, 'password+2fa');
    }

    public static function resendTwoFactor(): never
    {
        Session::start();
        $challenge = self::challenge();
        if ($challenge === null) {
            throw new HttpException(
                401,
                'two_factor_expired',
                'Your sign-in session has expired. Please sign in again.'
            );
        }

        $user = self::challengeUser((int) $challenge['user_id']);
        $wait = AccountSecurity::TWO_FACTOR_RESEND_SECONDS
            - (time() - (int) $challenge['last_sent_at']);
        if ($wait > 0) {
            throw HttpException::tooManyRequests(
                'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before requesting another code.',
                $wait
            );
        }

        $sent = AccountSecurity::sendTwoFactorCode(
            $user,
            AccountSecurity::twoFactorCode((string) $user['two_factor_secret'])
        );

        $challenge['last_sent_at'] = time();
        Session::put(self::TWO_FACTOR_SESSION_KEY, $challenge);

        Response::ok([
            'two_factor_required' => true,
            'email' => AccountSecurity::maskedEmail((string) $user['email']),
            'expires_in' => self::challengeSecondsLeft($challenge),
            'resend_in' => AccountSecurity::TWO_FACTOR_RESEND_SECONDS,
            'delivery' => $sent ? 'sent' : 'unavailable',
        ]);
    }

    public static function cancelTwoFactor(): never
    {
        Session::start();
        self::clearChallenge();

        Response::noContent();
    }

    public static function twoFactorSettings(): never
    {
        $user = Auth::requireUser();

        if (!AccountSecurity::twoFactorAvailable($user)) {
            throw HttpException::forbidden(
                'Email code verification is available for staff and parent accounts.'
            );
        }

        $data = Request::body();
        $enable = Validator::boolean($data['enabled'] ?? null, 'enabled', false);

        if ($enable) {
            $secret = is_string($user['two_factor_secret'] ?? null) && $user['two_factor_secret'] !== ''
                ? (string) $user['two_factor_secret']
                : AccountSecurity::newTwoFactorSecret();

            Database::execute(
                'UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?',
                [$secret, (int) $user['id']]
            );
            Audit::record('auth.two_factor_enabled', 'user', $user['uuid'], [], (int) $user['id']);
        } else {
            $current = Validator::string($data['current_password'] ?? null, 'current_password', 1, 200);
            if (!password_verify($current, (string) $user['password_hash'])) {
                throw HttpException::validation('Your current password is incorrect.', [
                    'current_password' => 'The current password is incorrect.',
                ]);
            }

            Database::execute(
                'UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?',
                [(int) $user['id']]
            );
            Audit::record('auth.two_factor_disabled', 'user', $user['uuid'], [], (int) $user['id']);
        }

        Auth::refresh();
        $fresh = Auth::user();
        if ($fresh === null) {
            throw HttpException::server('The account could not be reloaded.');
        }

        Response::ok([
            'user' => Auth::present($fresh),
            'csrf_token' => Csrf::token(),
        ]);
    }

    public static function forgotPassword(): never
    {
        Session::start();
        $data = Request::body();
        $email = Validator::email($data['email'] ?? null);
        $ip = Request::ip();

        // Always answer the same way so the endpoint cannot be used to test
        // whether an address belongs to an account.
        $response = [
            'message' => 'If that email belongs to an active account, a password reset link is on its way.',
        ];

        $user = Database::fetch(
            'SELECT id, uuid, email, role, first_name, last_name, status
             FROM users
             WHERE email = ?
             LIMIT 1',
            [$email]
        );

        // School-issued student inboxes are placeholders, so self-service reset
        // is limited to staff and parents; the office resets student accounts.
        if (
            $user === null
            || $user['status'] !== 'active'
            || !in_array((string) $user['role'], AccountSecurity::TWO_FACTOR_ROLES, true)
        ) {
            Logger::info('Password reset skipped for ineligible account', ['email' => $email, 'ip' => $ip]);
            Response::ok($response);
        }

        $recent = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM password_resets
             WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [(int) $user['id']]
        );
        if ($recent >= AccountSecurity::RESET_MAX_PER_HOUR) {
            Logger::warning('Password reset rate limit reached', ['user' => $user['uuid'], 'ip' => $ip]);
            Response::ok($response);
        }

        $ipRecent = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM password_resets
             WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [$ip]
        );
        if ($ipRecent >= AccountSecurity::RESET_MAX_PER_HOUR * 4) {
            Logger::warning('Password reset IP rate limit reached', ['ip' => $ip]);
            Response::ok($response);
        }

        $token = AccountSecurity::resetToken();
        $expiryMinutes = AccountSecurity::RESET_TOKEN_TTL_MINUTES;

        Database::transaction(static function () use ($user, $token, $ip, $expiryMinutes): void {
            Database::execute(
                'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [(int) $user['id']]
            );
            Database::insert(
                'INSERT INTO password_resets (user_id, token_hash, ip, expires_at)
                 VALUES (?, ?, ?, NOW() + INTERVAL ' . $expiryMinutes . ' MINUTE)',
                [
                    (int) $user['id'],
                    AccountSecurity::hashToken($token),
                    $ip,
                ]
            );
        });

        AccountSecurity::sendPasswordResetLink($user, $token);
        Audit::record('auth.password_reset_requested', 'user', $user['uuid'], ['ip' => $ip]);
        Logger::info('Password reset link issued', ['user' => $user['uuid'], 'ip' => $ip]);

        Response::ok($response);
    }

    public static function resetPassword(): never
    {
        Session::start();
        $data = Request::body();

        $token = Validator::string($data['token'] ?? null, 'token', 32, 128);
        $password = Validator::password($data['password'] ?? null, 'password');
        $confirmation = Validator::string($data['password_confirmation'] ?? null, 'password_confirmation', 1, 200);

        if ($password !== $confirmation) {
            throw HttpException::validation('The new passwords do not match.', [
                'password_confirmation' => 'The passwords do not match.',
            ]);
        }

        $row = Database::fetch(
            'SELECT pr.id AS reset_id, pr.user_id, pr.expires_at, pr.used_at,
                    u.uuid, u.email, u.status, u.password_hash
             FROM password_resets pr
             INNER JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ?
             LIMIT 1',
            [AccountSecurity::hashToken($token)]
        );

        if ($row === null || $row['used_at'] !== null) {
            throw new HttpException(
                400,
                'reset_invalid',
                'This reset link is invalid or has already been used. Request a new one.'
            );
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            throw new HttpException(
                400,
                'reset_expired',
                'This reset link has expired. Request a new one.'
            );
        }

        if ($row['status'] !== 'active') {
            throw new HttpException(
                403,
                'account_inactive',
                'This account is not active. Please contact the school office.'
            );
        }

        if (password_verify($password, (string) $row['password_hash'])) {
            throw HttpException::validation('Choose a password you have not used before.', [
                'password' => 'The new password must be different from the current one.',
            ]);
        }

        Database::transaction(static function () use ($row, $password): void {
            Database::execute(
                'UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $row['user_id']]
            );
            Database::execute(
                'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
                [(int) $row['user_id']]
            );
            Database::execute(
                'DELETE FROM login_attempts WHERE email = ? AND success = 0',
                [(string) $row['email']]
            );
        });

        Audit::record('auth.password_reset', 'user', $row['uuid'], [], (int) $row['user_id']);
        Logger::info('Password reset completed', ['user' => $row['uuid']]);

        Response::ok([
            'message' => 'Your password has been reset. Sign in with the new password.',
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

    /**
     * Creates or reuses the pending challenge and emails a fresh code when the
     * resend window allows it. Returns the public challenge payload.
     *
     * @return array<string, mixed>
     */
    private static function beginTwoFactor(array $user): array
    {
        $now = time();
        $existing = Session::get(self::TWO_FACTOR_SESSION_KEY);
        $reuse = is_array($existing)
            && (int) ($existing['user_id'] ?? 0) === (int) $user['id']
            && ($now - (int) ($existing['started_at'] ?? 0)) < AccountSecurity::TWO_FACTOR_TTL_SECONDS;

        if (!$reuse) {
            // Rotate the pre-authentication session id so a fixed session
            // cannot be carried into the authenticated session.
            Session::regenerate();
            $challenge = [
                'user_id' => (int) $user['id'],
                'started_at' => $now,
                'last_sent_at' => 0,
                'attempts' => 0,
            ];
        } else {
            $challenge = $existing;
        }

        $canSend = !$reuse
            || ($now - (int) $challenge['last_sent_at']) >= AccountSecurity::TWO_FACTOR_RESEND_SECONDS;

        $sent = false;
        if ($canSend) {
            $sent = AccountSecurity::sendTwoFactorCode(
                $user,
                AccountSecurity::twoFactorCode((string) $user['two_factor_secret'])
            );
            // Record the send even on failure so retries are throttled and the
            // endpoint cannot be used to spam an inbox.
            $challenge['last_sent_at'] = $now;
        }

        Session::put(self::TWO_FACTOR_SESSION_KEY, $challenge);

        return [
            'two_factor_required' => true,
            'email' => AccountSecurity::maskedEmail((string) $user['email']),
            'expires_in' => self::challengeSecondsLeft($challenge),
            'resend_in' => max(
                0,
                AccountSecurity::TWO_FACTOR_RESEND_SECONDS - ($now - (int) $challenge['last_sent_at'])
            ),
            'delivery' => $sent ? 'sent' : ($canSend ? 'unavailable' : 'already_sent'),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function challenge(): ?array
    {
        $challenge = Session::get(self::TWO_FACTOR_SESSION_KEY);
        if (!is_array($challenge) || !isset($challenge['user_id'], $challenge['started_at'])) {
            return null;
        }

        if ((time() - (int) $challenge['started_at']) > AccountSecurity::TWO_FACTOR_TTL_SECONDS) {
            self::clearChallenge();

            return null;
        }

        $challenge['attempts'] = (int) ($challenge['attempts'] ?? 0);
        $challenge['last_sent_at'] = (int) ($challenge['last_sent_at'] ?? 0);

        return $challenge;
    }

    private static function challengeSecondsLeft(array $challenge): int
    {
        return max(
            0,
            AccountSecurity::TWO_FACTOR_TTL_SECONDS - (time() - (int) $challenge['started_at'])
        );
    }

    private static function challengeUser(int $userId): array
    {
        $user = Database::fetch(
            'SELECT id, uuid, email, role, first_name, last_name, phone, status,
                    password_changed_at, two_factor_enabled, two_factor_secret, last_login_at
             FROM users
             WHERE id = ?
             LIMIT 1',
            [$userId]
        );

        if ($user === null || $user['status'] !== 'active' || !AccountSecurity::twoFactorEnabled($user)) {
            self::clearChallenge();
            throw new HttpException(
                401,
                'two_factor_expired',
                'This sign-in attempt is no longer valid. Please sign in again.'
            );
        }

        return $user;
    }

    private static function clearChallenge(): void
    {
        Session::forget(self::TWO_FACTOR_SESSION_KEY);
    }

    private static function completeLogin(array $user, string $method): never
    {
        Database::execute(
            'DELETE FROM login_attempts WHERE email = ? AND success = 0',
            [(string) $user['email']]
        );
        self::recordAttempt((string) $user['email'], Request::ip(), true);
        Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int) $user['id']]);

        Auth::login((int) $user['id']);
        Audit::record('auth.login', 'user', $user['uuid'], ['method' => $method], (int) $user['id']);

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

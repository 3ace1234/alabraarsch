<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Env;
use AlFalaah\Core\Logger;
use AlFalaah\Core\Mailer;

/**
 * Password and two-factor primitives shared by the authentication flows.
 *
 * Email codes are derived from a per-user secret with a five-minute time step
 * (the same idea as TOTP). That keeps resends consistent, avoids storing codes,
 * and lets the smoke test verify the happy path without reading an inbox.
 */
final class AccountSecurity
{
    public const TWO_FACTOR_WINDOW_SECONDS = 300;
    public const TWO_FACTOR_TTL_SECONDS = 600;
    public const TWO_FACTOR_MAX_ATTEMPTS = 5;
    public const TWO_FACTOR_RESEND_SECONDS = 30;

    /** @var list<string> */
    public const TWO_FACTOR_ROLES = ['admin', 'teacher', 'parent'];

    public const RESET_TOKEN_TTL_MINUTES = 60;
    public const RESET_MAX_PER_HOUR = 3;

    public static function twoFactorAvailable(array $user): bool
    {
        return in_array((string) ($user['role'] ?? ''), self::TWO_FACTOR_ROLES, true);
    }

    public static function twoFactorEnabled(array $user): bool
    {
        return self::twoFactorAvailable($user)
            && !empty($user['two_factor_enabled'])
            && is_string($user['two_factor_secret'] ?? null)
            && $user['two_factor_secret'] !== '';
    }

    public static function newTwoFactorSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function twoFactorCode(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::TWO_FACTOR_WINDOW_SECONDS);
        $digest = hash_hmac('sha256', 'alfalaah-2fa|' . $counter, $secret, true);
        $number = unpack('N', substr($digest, 0, 4))[1] % 1000000;

        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    public static function verifyTwoFactorCode(string $secret, string $code): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $now = time();
        foreach ([$now, $now - self::TWO_FACTOR_WINDOW_SECONDS] as $timestamp) {
            if (hash_equals(self::twoFactorCode($secret, $timestamp), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function maskedEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $visible = mb_substr($local, 0, 1);

        return $visible . str_repeat('•', max(2, mb_strlen($local) - 1)) . $domain;
    }

    public static function temporaryPassword(): string
    {
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '@#$%&*!';
        $all = $lower . $upper . $digits . $symbols;
        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($index = 0; $index < 8; $index++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($index = count($chars) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$chars[$index], $chars[$swap]] = [$chars[$swap], $chars[$index]];
        }

        return implode('', $chars);
    }

    public static function resetToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function sendTwoFactorCode(array $user, string $code): bool
    {
        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));

        $lines = [
            'Hello' . ($name !== '' ? ' ' . $name : '') . ',',
            '',
            'Your Al-Falaah School portal sign-in code is:',
            '',
            '    ' . $code,
            '',
            'The code expires in ' . intdiv(self::TWO_FACTOR_TTL_SECONDS, 60)
                . ' minutes and can only be used once.',
            'If you did not try to sign in, change your password immediately and contact the school office.',
            '',
            'Al-Falaah School',
        ];

        try {
            return Mailer::send([
                'to' => (string) $user['email'],
                'subject' => 'Your Al-Falaah sign-in code',
                'text' => implode("\n", $lines),
            ]);
        } catch (\Throwable $exception) {
            Logger::error('Two-factor code email failed', [
                'user' => $user['uuid'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public static function sendPasswordResetLink(array $user, string $token): bool
    {
        $base = rtrim(trim((string) Env::get('PORTAL_URL', '')), '/');
        if ($base === '') {
            Logger::warning('Password reset email skipped: PORTAL_URL is not configured.');

            return false;
        }

        $link = $base . '/reset-password.html?token=' . rawurlencode($token);
        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));

        $lines = [
            'Hello' . ($name !== '' ? ' ' . $name : '') . ',',
            '',
            'A password reset was requested for your Al-Falaah School portal account.',
            'Open this link to choose a new password:',
            '',
            $link,
            '',
            'The link expires in ' . self::RESET_TOKEN_TTL_MINUTES . ' minutes and can only be used once.',
            'If you did not request this, you can ignore this email; your current password still works.',
            '',
            'Al-Falaah School',
        ];

        try {
            return Mailer::send([
                'to' => (string) $user['email'],
                'subject' => 'Reset your Al-Falaah portal password',
                'text' => implode("\n", $lines),
            ]);
        } catch (\Throwable $exception) {
            Logger::error('Password reset email failed', [
                'user' => $user['uuid'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}

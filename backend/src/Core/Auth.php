<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class Auth
{
    private static ?array $user = null;

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = Session::get('auth_user_id');
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        if (!Session::checkTimeout()) {
            return null;
        }

        $row = Database::fetch(
            'SELECT u.id, u.uuid, u.email, u.password_hash, u.role, u.first_name, u.last_name,
                    u.phone, u.status, u.password_changed_at, u.last_login_at,
                    s.uuid AS student_uuid, s.admission_no, s.class_id,
                    c.uuid AS class_uuid, c.name AS class_name, c.level AS class_level
             FROM users u
             LEFT JOIN students s ON s.user_id = u.id
             LEFT JOIN classes c ON c.id = s.class_id
             WHERE u.id = ?
             LIMIT 1',
            [(int) $id]
        );

        if ($row === null || $row['status'] !== 'active') {
            Session::destroy();
            return null;
        }

        $authTime = (int) Session::get('auth_time', 0);
        if ($row['password_changed_at'] !== null && strtotime((string) $row['password_changed_at']) > $authTime) {
            // Password changed after this session was created: force re-login.
            Session::destroy();
            return null;
        }

        self::$user = $row;

        return self::$user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user !== null ? (int) $user['id'] : null;
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function is(string $role): bool
    {
        return self::role() === $role;
    }

    /** @param string|list<string> $roles */
    public static function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];

        return in_array(self::role(), $roles, true);
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    /** @param string|list<string> $roles */
    public static function requireRole(string|array $roles): array
    {
        $user = self::requireUser();
        $roles = is_array($roles) ? $roles : [$roles];

        if (!in_array($user['role'], $roles, true)) {
            throw HttpException::forbidden();
        }

        return $user;
    }

    public static function login(int $userId): void
    {
        Session::regenerate();
        Session::put('auth_user_id', $userId);
        Session::put('auth_time', time());
        Session::rotateCsrf();
        Session::touch();
        self::$user = null;
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    public static function refresh(): void
    {
        self::$user = null;
    }

    public static function present(array $user): array
    {
        $student = null;
        if (!empty($user['student_uuid'])) {
            $className = $user['class_name'] ?? null;
            $student = [
                'id' => $user['student_uuid'],
                'admission_no' => $user['admission_no'] ?? null,
                'class' => $className !== null ? [
                    'id' => $user['class_uuid'] ?? null,
                    'name' => $className,
                    'level' => $user['class_level'] ?? null,
                ] : null,
            ];
        }

        return [
            'id' => $user['uuid'],
            'email' => $user['email'],
            'role' => $user['role'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'display_name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'initials' => strtoupper(
                mb_substr((string) $user['first_name'], 0, 1) . mb_substr((string) $user['last_name'], 0, 1)
            ),
            'phone' => $user['phone'],
            'status' => $user['status'],
            'last_login_at' => $user['last_login_at'],
            'student' => $student,
        ];
    }
}

<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class Validator
{
    public static function string(
        mixed $value,
        string $field,
        int $min = 1,
        int $max = 255,
        bool $required = true
    ): ?string {
        if ($value === null || $value === '') {
            if ($required) {
                throw self::fail($field, 'This field is required.');
            }

            return null;
        }

        if (!is_scalar($value)) {
            throw self::fail($field, 'This field must be text.');
        }

        $value = trim((string) $value);
        $length = mb_strlen($value);

        if ($length < $min) {
            throw self::fail($field, "This field must be at least {$min} characters.");
        }
        if ($length > $max) {
            throw self::fail($field, "This field may not exceed {$max} characters.");
        }

        return $value;
    }

    public static function email(mixed $value, string $field = 'email', bool $required = true): ?string
    {
        $value = self::string($value, $field, 3, 190, $required);
        if ($value === null) {
            return null;
        }

        $value = strtolower($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw self::fail($field, 'Enter a valid email address.');
        }

        return $value;
    }

    public static function uuid(mixed $value, string $field = 'id', bool $required = true): ?string
    {
        $value = self::string($value, $field, 36, 36, $required);
        if ($value === null) {
            return null;
        }

        $value = strtolower($value);
        if (!Uuid::isValid($value)) {
            throw self::fail($field, 'Enter a valid identifier.');
        }

        return $value;
    }

    public static function date(mixed $value, string $field, bool $required = true): ?string
    {
        $value = self::string($value, $field, 10, 10, $required);
        if ($value === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw self::fail($field, 'Use the date format YYYY-MM-DD.');
        }

        return $value;
    }

    public static function time(mixed $value, string $field, bool $required = true): ?string
    {
        $value = self::string($value, $field, 4, 8, $required);
        if ($value === null) {
            return null;
        }

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
            throw self::fail($field, 'Use a 24-hour time such as 08:00.');
        }

        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    public static function enum(mixed $value, string $field, array $allowed, bool $required = true): ?string
    {
        $value = self::string($value, $field, 1, 60, $required);
        if ($value === null) {
            return null;
        }

        if (!in_array($value, $allowed, true)) {
            throw self::fail($field, 'Choose one of: ' . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    public static function int(
        mixed $value,
        string $field,
        ?int $min = null,
        ?int $max = null,
        bool $required = true
    ): ?int {
        if ($value === null || $value === '') {
            if ($required) {
                throw self::fail($field, 'This field is required.');
            }

            return null;
        }

        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
            throw self::fail($field, 'This field must be a whole number.');
        }

        $value = (int) $value;
        if ($min !== null && $value < $min) {
            throw self::fail($field, "This field must be at least {$min}.");
        }
        if ($max !== null && $value > $max) {
            throw self::fail($field, "This field must not exceed {$max}.");
        }

        return $value;
    }

    public static function number(
        mixed $value,
        string $field,
        float $min = 0,
        float $max = PHP_FLOAT_MAX,
        bool $required = true
    ): ?float {
        if ($value === null || $value === '') {
            if ($required) {
                throw self::fail($field, 'This field is required.');
            }

            return null;
        }

        if (!is_numeric($value)) {
            throw self::fail($field, 'This field must be a number.');
        }

        $value = round((float) $value, 2);
        if ($value < $min || $value > $max) {
            throw self::fail($field, "This field must be between {$min} and {$max}.");
        }

        return $value;
    }

    public static function boolean(mixed $value, string $field = 'value', bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false', 'off', 'no'], true)) {
            return false;
        }

        throw self::fail($field, 'This field must be true or false.');
    }

    public static function password(mixed $value, string $field = 'password'): string
    {
        $value = self::string($value, $field, 10, 72);
        assert($value !== null);

        if (
            !preg_match('/[A-Z]/', $value)
            || !preg_match('/[a-z]/', $value)
            || !preg_match('/\d/', $value)
            || !preg_match('/[^A-Za-z0-9]/', $value)
        ) {
            throw self::fail(
                $field,
                'Use at least 10 characters with an uppercase letter, lowercase letter, number and symbol.'
            );
        }

        return $value;
    }

    /** @return array{0: int, 1: int} */
    public static function pagination(?string $page, ?string $perPage, int $defaultPerPage = 25, int $maxPerPage = 100): array
    {
        $pageNumber = $page === null || $page === '' ? 1 : max(1, (int) $page);
        $pageSize = $perPage === null || $perPage === '' ? $defaultPerPage : (int) $perPage;
        $pageSize = max(1, min($maxPerPage, $pageSize));

        return [$pageNumber, $pageSize];
    }

    public static function requireFields(array $data, array $fields): void
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                $missing[$field] = 'This field is required.';
            }
        }

        if ($missing !== []) {
            throw HttpException::validation('Please complete the required fields.', $missing);
        }
    }

    private static function fail(string $field, string $message): HttpException
    {
        return HttpException::validation($message, [$field => $message]);
    }
}

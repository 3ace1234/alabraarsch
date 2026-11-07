<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Audit;
use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Request;
use AlFalaah\Core\Response;
use AlFalaah\Core\Session;
use AlFalaah\Core\Uuid;
use AlFalaah\Core\Validator;

/**
 * Public website intake: admission enquiries and contact messages.
 * These routes are unauthenticated but still require a CSRF token.
 */
final class IntakeController
{
    private const LEVELS = ['nursery', 'primary', 'junior_secondary', 'senior_secondary'];

    public static function admission(): never
    {
        $data = self::body();
        self::rejectBot($data);
        self::throttle('admission.submitted', 4);

        $applicantName = Validator::string($data['applicant_name'] ?? null, 'applicant_name', 2, 120);
        $guardianName = Validator::string($data['guardian_name'] ?? null, 'guardian_name', 2, 120);
        $phone = self::phone($data['phone'] ?? null, true);
        $email = Validator::email($data['email'] ?? null, 'email', false);
        $level = self::level($data['level'] ?? null);
        $classLabel = Validator::string($data['class_label'] ?? $data['level'] ?? null, 'class_label', 1, 60);
        $dateOfBirth = Validator::date($data['date_of_birth'] ?? null, 'date_of_birth', false);
        $gender = self::gender($data['gender'] ?? null);
        $previousSchool = Validator::string($data['previous_school'] ?? null, 'previous_school', 0, 160, false);
        $notes = Validator::string($data['notes'] ?? null, 'notes', 0, 2000, false);

        if ($dateOfBirth !== null && $dateOfBirth > date('Y-m-d')) {
            throw HttpException::validation('The date of birth cannot be in the future.', [
                'date_of_birth' => 'Choose a date in the past.',
            ]);
        }

        $uuid = Uuid::v4();
        Database::insert(
            'INSERT INTO admission_applications
                (uuid, applicant_name, guardian_name, email, phone, level, class_label,
                 date_of_birth, gender, previous_school, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $uuid,
                $applicantName,
                $guardianName,
                $email,
                $phone,
                $level,
                $classLabel,
                $dateOfBirth,
                $gender,
                $previousSchool,
                'new',
                $notes,
            ]
        );

        Audit::record('admission.submitted', 'admission', $uuid, [
            'level' => $level,
            'source' => 'website',
        ]);

        Response::ok([
            'received' => true,
            'reference' => strtoupper(substr($uuid, 0, 8)),
        ], 201);
    }

    public static function contact(): never
    {
        $data = self::body();
        self::rejectBot($data);
        self::throttle('contact.submitted', 4);

        $name = Validator::string($data['name'] ?? null, 'name', 2, 120);
        $email = Validator::email($data['email'] ?? null, 'email', false);
        $phone = self::phone($data['phone'] ?? null, false);
        $subject = Validator::string($data['subject'] ?? null, 'subject', 2, 120);
        $message = Validator::string($data['message'] ?? null, 'message', 10, 4000);

        if ($email === null && $phone === null) {
            throw HttpException::validation('Please leave an email address or phone number so we can reply.', [
                'email' => 'Enter an email address or a phone number.',
                'phone' => 'Enter a phone number or an email address.',
            ]);
        }

        $uuid = Uuid::v4();
        Database::insert(
            'INSERT INTO contact_messages (uuid, name, email, phone, subject, message, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$uuid, $name, $email, $phone, $subject, $message, 'new']
        );

        Audit::record('contact.submitted', 'contact_message', $uuid, [
            'subject' => $subject,
        ]);

        Response::ok([
            'received' => true,
            'reference' => strtoupper(substr($uuid, 0, 8)),
        ], 201);
    }

    private static function body(): array
    {
        return Request::body();
    }

    private static function rejectBot(array $data): void
    {
        $honeypot = $data['website'] ?? '';
        if (is_string($honeypot) && trim($honeypot) !== '') {
            // Bots fill hidden fields. Return a normal-looking success without storing.
            Response::ok(['received' => true], 201);
        }
    }

    private static function throttle(string $action, int $limit): void
    {
        $recent = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM audit_logs
             WHERE action = ? AND ip = ? AND created_at > (NOW() - INTERVAL 60 SECOND)',
            [$action, Request::ip()]
        );

        if ($recent >= $limit) {
            throw HttpException::tooManyRequests(
                'Too many submissions from this connection. Please wait a minute and try again.',
                60
            );
        }
    }

    private static function phone(mixed $value, bool $required): ?string
    {
        $phone = Validator::string($value, 'phone', 7, 30, $required);
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 7) {
            throw HttpException::validation('Enter a valid phone number.', [
                'phone' => 'Enter a phone number with at least 7 digits.',
            ]);
        }

        return $phone;
    }

    private static function level(mixed $value): string
    {
        $level = Validator::string($value, 'level', 1, 60);
        assert($level !== null);

        $normalised = strtolower(trim($level));
        if (in_array($normalised, self::LEVELS, true)) {
            return $normalised;
        }
        if (str_starts_with($normalised, 'nursery')) {
            return 'nursery';
        }
        if (str_starts_with($normalised, 'primary')) {
            return 'primary';
        }
        if (str_starts_with($normalised, 'jss') || str_starts_with($normalised, 'junior')) {
            return 'junior_secondary';
        }
        if (str_starts_with($normalised, 'ss') || str_starts_with($normalised, 'senior')) {
            return 'senior_secondary';
        }

        throw HttpException::validation('Choose a valid class level.', [
            'level' => 'Choose a level from Nursery to Senior Secondary.',
        ]);
    }

    private static function gender(mixed $value): ?string
    {
        $gender = Validator::string($value, 'gender', 1, 20, false);
        if ($gender === null) {
            return null;
        }

        $normalised = strtolower(trim($gender));
        if (!in_array($normalised, ['male', 'female'], true)) {
            throw HttpException::validation('Choose a valid gender.', [
                'gender' => 'Choose male or female.',
            ]);
        }

        return $normalised;
    }
}

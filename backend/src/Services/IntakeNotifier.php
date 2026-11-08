<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Env;
use AlFalaah\Core\Logger;
use AlFalaah\Core\Mailer;

/**
 * Emails the school office when a public website form is submitted.
 *
 * Notification failures are logged but never surfaced to the visitor and never
 * roll back the stored submission. The submission itself is the source of
 * truth; email is a convenience copy.
 */
final class IntakeNotifier
{
    public static function admission(array $application): void
    {
        $recipients = self::recipients(['MAIL_ADMISSIONS_TO', 'MAIL_INTAKE_TO']);
        if ($recipients === []) {
            Logger::debug('Admission notification skipped: no recipient configured.');
            return;
        }

        $reference = self::reference((string) ($application['uuid'] ?? ''));

        $lines = [
            'A new admission application was submitted from the school website.',
            '',
            'Reference:      ' . $reference,
            'Applicant:      ' . ($application['applicant_name'] ?? ''),
            'Guardian:       ' . ($application['guardian_name'] ?? ''),
            'Phone:          ' . ($application['phone'] ?? '—'),
            'Email:          ' . ($application['email'] ?? '—'),
            'Level:          ' . self::level($application['level'] ?? '')
                . ' (' . ($application['class_label'] ?? '—') . ')',
            'Date of birth:  ' . ($application['date_of_birth'] ?? '—'),
            'Gender:         ' . ($application['gender'] ?? '—'),
            'Previous school:' . ' ' . ($application['previous_school'] ?? '—'),
            'Submitted:      ' . ($application['created_at'] ?? date('Y-m-d H:i:s')),
            '',
            'Notes:',
            ($application['notes'] ?? '') !== '' ? (string) $application['notes'] : '—',
        ];

        $portal = self::portalLink('admissions');
        if ($portal !== null) {
            $lines[] = '';
            $lines[] = 'Review this application in the admin portal:';
            $lines[] = $portal;
        }

        self::send($recipients, 'New admission application — ' . $reference, implode("\n", $lines), [
            'reference' => $reference,
            'type' => 'admission',
            'reply_to' => (string) ($application['email'] ?? ''),
        ]);
    }

    public static function contact(array $message): void
    {
        $recipients = self::recipients(['MAIL_CONTACT_TO', 'MAIL_INTAKE_TO']);
        if ($recipients === []) {
            Logger::debug('Contact notification skipped: no recipient configured.');
            return;
        }

        $reference = self::reference((string) ($message['uuid'] ?? ''));

        $lines = [
            'A new message was submitted from the school website contact form.',
            '',
            'Reference: ' . $reference,
            'Name:      ' . ($message['name'] ?? ''),
            'Email:     ' . ($message['email'] ?? '—'),
            'Phone:     ' . ($message['phone'] ?? '—'),
            'Subject:   ' . ($message['subject'] ?? ''),
            'Received:  ' . ($message['created_at'] ?? date('Y-m-d H:i:s')),
            '',
            'Message:',
            (string) ($message['message'] ?? ''),
            '',
            'Reply directly to this email to answer the sender when an email address was provided.',
        ];

        $portal = self::portalLink('messages');
        if ($portal !== null) {
            $lines[] = '';
            $lines[] = 'Open the message inbox:';
            $lines[] = $portal;
        }

        self::send($recipients, 'Website message — ' . ($message['subject'] ?? $reference), implode("\n", $lines), [
            'reference' => $reference,
            'type' => 'contact',
            'reply_to' => (string) ($message['email'] ?? ''),
        ]);
    }

    /**
     * @param array<int, string> $recipients
     * @param array{reference: string, type: string, reply_to: string} $context
     */
    private static function send(array $recipients, string $subject, string $body, array $context): void
    {
        try {
            $sent = Mailer::send([
                'to' => $recipients,
                'subject' => $subject,
                'text' => $body,
                'reply_to' => $context['reply_to'],
            ]);

            Logger::info('Intake notification processed', [
                'type' => $context['type'],
                'reference' => $context['reference'],
                'delivered' => $sent,
                'driver' => Env::get('MAIL_DRIVER', 'log'),
            ]);
        } catch (\Throwable $exception) {
            Logger::error('Intake notification failed', [
                'type' => $context['type'],
                'reference' => $context['reference'],
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    private static function recipients(array $keys): array
    {
        foreach ($keys as $key) {
            $value = Env::get($key, '') ?? '';
            if (trim($value) === '') {
                continue;
            }

            $addresses = array_values(array_filter(array_map(
                static fn (string $address): string => trim($address),
                explode(',', $value)
            )));

            $valid = array_values(array_filter(
                $addresses,
                static fn (string $address): bool => (bool) filter_var($address, FILTER_VALIDATE_EMAIL)
            ));

            if ($valid !== []) {
                return $valid;
            }
        }

        return [];
    }

    private static function reference(string $uuid): string
    {
        return $uuid === '' ? 'UNKNOWN' : strtoupper(substr($uuid, 0, 8));
    }

    private static function portalLink(string $section): ?string
    {
        $base = trim(Env::get('PORTAL_URL', '') ?? '');
        if ($base === '') {
            return null;
        }

        return rtrim($base, '/') . '/dashboard.html#' . $section;
    }

    private static function level(string $level): string
    {
        return $level === '' ? '—' : ucwords(str_replace('_', ' ', $level));
    }
}

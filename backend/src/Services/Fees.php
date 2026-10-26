<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Uuid;
use PDO;

final class Fees
{
    public static function summary(int $studentId, int $termId): array
    {
        $invoices = Database::fetchAll(
            'SELECT uuid, reference, description, amount, status, issued_on, due_on
             FROM fee_invoices
             WHERE student_id = ? AND term_id = ?
             ORDER BY issued_on ASC, id ASC',
            [$studentId, $termId]
        );

        $payments = Database::fetchAll(
            'SELECT uuid, reference, amount, method, paid_on, note
             FROM fee_payments
             WHERE student_id = ? AND term_id = ?
             ORDER BY paid_on ASC, id ASC',
            [$studentId, $termId]
        );

        $total = 0.0;
        $billed = 0.0;
        $presentedInvoices = [];

        foreach ($invoices as $invoice) {
            $amount = (float) $invoice['amount'];
            $total += $amount;
            if ($invoice['status'] !== 'waived') {
                $billed += $amount;
            }

            $presentedInvoices[] = [
                'id' => $invoice['uuid'],
                'ref' => $invoice['reference'],
                'item' => $invoice['description'],
                'date' => Academics::formatDate((string) $invoice['issued_on']),
                'due_date' => Academics::formatDate((string) $invoice['due_on']),
                'amount' => round($amount, 2),
                'status' => self::statusLabel((string) $invoice['status']),
                'status_code' => $invoice['status'],
            ];
        }

        $paid = 0.0;
        $presentedPayments = [];
        foreach ($payments as $payment) {
            $amount = (float) $payment['amount'];
            $paid += $amount;
            $presentedPayments[] = [
                'id' => $payment['uuid'],
                'date' => Academics::formatDate((string) $payment['paid_on']),
                'ref' => $payment['reference'],
                'method' => $payment['method'],
                'amount' => round($amount, 2),
                'note' => $payment['note'],
            ];
        }

        $term = Database::fetch(
            'SELECT t.uuid, t.name, s.name AS session_name
             FROM terms t INNER JOIN academic_sessions s ON s.id = t.session_id
             WHERE t.id = ? LIMIT 1',
            [$termId]
        );
        $termLabel = $term !== null ? $term['name'] . ' ' . $term['session_name'] : null;

        return [
            'term' => $termLabel,
            'term_id' => $term['uuid'] ?? null,
            'total' => round($total, 2),
            'paid' => round($paid, 2),
            'balance' => round(max(0, $billed - $paid), 2),
            'due_date' => self::earliestDueDate($invoices),
            'invoices' => $presentedInvoices,
            'payments' => $presentedPayments,
        ];
    }

    public static function schoolSummary(int $termId): array
    {
        $billed = (float) (Database::fetchValue(
            "SELECT COALESCE(SUM(amount), 0) FROM fee_invoices WHERE term_id = ? AND status <> 'waived'",
            [$termId]
        ) ?? 0);

        $collected = (float) (Database::fetchValue(
            'SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE term_id = ?',
            [$termId]
        ) ?? 0);

        $outstanding = max(0, $billed - $collected);

        return [
            'billed' => round($billed, 2),
            'collected' => round($collected, 2),
            'outstanding' => round($outstanding, 2),
            'collection_rate' => $billed > 0 ? (int) round(($collected / $billed) * 100) : 0,
        ];
    }

    public static function recordPayment(
        string $invoiceUuid,
        float $amount,
        string $method,
        string $paidOn,
        int $receivedBy,
        ?string $reference = null,
        ?string $note = null
    ): array {
        return Database::transaction(static function (PDO $pdo) use (
            $invoiceUuid,
            $amount,
            $method,
            $paidOn,
            $receivedBy,
            $reference,
            $note
        ): array {
            $statement = $pdo->prepare(
                'SELECT fi.*, s.first_name, s.last_name, s.uuid AS student_uuid
                 FROM fee_invoices fi
                 INNER JOIN students s ON s.id = fi.student_id
                 WHERE fi.uuid = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute([$invoiceUuid]);
            $invoice = $statement->fetch();

            if ($invoice === false) {
                throw HttpException::notFound('That fee invoice was not found.');
            }

            if ($invoice['status'] === 'waived') {
                throw HttpException::validation('This invoice has been waived and cannot take a payment.', [
                    'invoice_id' => 'The invoice is waived.',
                ]);
            }

            $paidStatement = $pdo->prepare(
                'SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE invoice_id = ?'
            );
            $paidStatement->execute([(int) $invoice['id']]);
            $alreadyPaid = (float) $paidStatement->fetchColumn();
            $balance = max(0, (float) $invoice['amount'] - $alreadyPaid);

            if ($amount <= 0) {
                throw HttpException::validation('Enter a payment amount greater than zero.', [
                    'amount' => 'Enter an amount greater than zero.',
                ]);
            }

            if ($amount > $balance) {
                throw HttpException::validation('The payment is greater than the outstanding balance.', [
                    'amount' => 'Maximum outstanding balance is ₦' . number_format($balance, 2),
                ]);
            }

            $reference = $reference !== null && $reference !== ''
                ? $reference
                : self::generateReference();

            if ((bool) Database::fetchValue('SELECT 1 FROM fee_payments WHERE reference = ? LIMIT 1', [$reference])) {
                throw HttpException::conflict('That payment reference has already been used.');
            }

            $paymentUuid = Uuid::v4();
            $insert = $pdo->prepare(
                'INSERT INTO fee_payments
                    (uuid, invoice_id, student_id, term_id, reference, amount, method, paid_on, received_by, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $paymentUuid,
                (int) $invoice['id'],
                (int) $invoice['student_id'],
                (int) $invoice['term_id'],
                $reference,
                round($amount, 2),
                $method,
                $paidOn,
                $receivedBy,
                $note,
            ]);

            $newPaid = $alreadyPaid + $amount;
            $status = $newPaid >= (float) $invoice['amount'] ? 'paid' : 'partial';
            $update = $pdo->prepare('UPDATE fee_invoices SET status = ? WHERE id = ?');
            $update->execute([$status, (int) $invoice['id']]);

            return [
                'id' => $paymentUuid,
                'invoice_id' => $invoiceUuid,
                'student_id' => $invoice['student_uuid'],
                'student_name' => trim($invoice['first_name'] . ' ' . $invoice['last_name']),
                'reference' => $reference,
                'amount' => round($amount, 2),
                'method' => $method,
                'paid_on' => $paidOn,
                'invoice_status' => $status,
                'balance' => round(max(0, (float) $invoice['amount'] - $newPaid), 2),
            ];
        });
    }

    public static function createInvoice(
        int $studentId,
        int $termId,
        string $description,
        float $amount,
        string $issuedOn,
        string $dueOn
    ): array {
        $uuid = Uuid::v4();
        $reference = self::generateInvoiceReference($issuedOn);

        Database::insert(
            'INSERT INTO fee_invoices
                (uuid, student_id, term_id, reference, description, amount, status, issued_on, due_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, $studentId, $termId, $reference, $description, round($amount, 2), 'unpaid', $issuedOn, $dueOn]
        );

        return [
            'id' => $uuid,
            'ref' => $reference,
            'item' => $description,
            'amount' => round($amount, 2),
            'issued_on' => $issuedOn,
            'due_on' => $dueOn,
            'status' => 'Unpaid',
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'unpaid' => 'Unpaid',
            'partial' => 'Partial',
            'paid' => 'Paid',
            'waived' => 'Waived',
            default => ucfirst($status),
        };
    }

    private static function earliestDueDate(array $invoices): ?string
    {
        $dates = array_filter(array_map(
            static fn (array $invoice): ?string => $invoice['due_on'] ?? null,
            $invoices
        ));

        if ($dates === []) {
            return null;
        }

        sort($dates);

        return Academics::formatDate((string) $dates[0]);
    }

    private static function generateReference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'PSK-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
            if (!Database::fetchValue('SELECT 1 FROM fee_payments WHERE reference = ? LIMIT 1', [$reference])) {
                return $reference;
            }
        }

        throw HttpException::server('A unique payment reference could not be generated.');
    }

    private static function generateInvoiceReference(string $issuedOn): string
    {
        $year = substr($issuedOn, 2, 2);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'INV-' . $year . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            if (!Database::fetchValue('SELECT 1 FROM fee_invoices WHERE reference = ? LIMIT 1', [$reference])) {
                return $reference;
            }
        }

        throw HttpException::server('A unique invoice reference could not be generated.');
    }
}

<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Uuid;

final class Attendance
{
    private const STATUSES = ['present', 'absent', 'late', 'excused'];

    public static function summary(int $studentId, int $termId): array
    {
        $counts = Database::fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'present') AS present,
                SUM(status = 'absent') AS absent,
                SUM(status = 'late') AS late,
                SUM(status = 'excused') AS excused
             FROM attendance_records
             WHERE student_id = ? AND term_id = ?",
            [$studentId, $termId]
        ) ?? [];

        $days = (int) ($counts['total'] ?? 0);
        $present = (int) ($counts['present'] ?? 0);
        $absent = (int) ($counts['absent'] ?? 0);
        $late = (int) ($counts['late'] ?? 0);
        $excused = (int) ($counts['excused'] ?? 0);
        $rate = $days > 0 ? (int) round((($present + $late) / $days) * 100) : 0;

        return [
            'rate' => $rate,
            'days' => $days,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            'weeks' => self::weeklyTrend($studentId, $termId),
            'recent' => self::recent($studentId, $termId, 8),
        ];
    }

    /** @return list<array> */
    public static function recent(int $studentId, int $termId, int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));
        $rows = Database::fetchAll(
            "SELECT date, status, note
             FROM attendance_records
             WHERE student_id = ? AND term_id = ?
             ORDER BY date DESC
             LIMIT {$limit}",
            [$studentId, $termId]
        );

        return array_map(static function (array $row): array {
            $status = (string) $row['status'];
            $note = $row['note'];

            if ($note === null || $note === '') {
                $note = match ($status) {
                    'present' => 'Present at school',
                    'late' => 'Arrived after the register',
                    'absent' => 'No notice received',
                    'excused' => 'Authorised absence',
                    default => null,
                };
            }

            return [
                'date' => date('D, j M Y', strtotime((string) $row['date'])),
                'day' => date('l', strtotime((string) $row['date'])),
                'status' => ucfirst($status),
                'tone' => match ($status) {
                    'present' => 'ok',
                    'late' => 'warn',
                    'absent' => 'danger',
                    'excused' => 'info',
                    default => 'info',
                },
                'note' => $note,
            ];
        }, $rows);
    }

    /** @return list<array{label: string, value: int}> */
    public static function weeklyTrend(int $studentId, int $termId, int $limit = 7): array
    {
        $rows = Database::fetchAll(
            "SELECT YEARWEEK(date, 1) AS week_key,
                    MIN(date) AS week_start,
                    SUM(status IN ('present','late')) AS attended,
                    COUNT(*) AS total
             FROM attendance_records
             WHERE student_id = ? AND term_id = ?
             GROUP BY week_key
             ORDER BY week_start DESC
             LIMIT {$limit}",
            [$studentId, $termId]
        );

        $rows = array_reverse($rows);
        $result = [];
        foreach ($rows as $index => $row) {
            $total = (int) $row['total'];
            $result[] = [
                'label' => 'W' . ($index + 1),
                'value' => $total > 0 ? (int) round(((int) $row['attended'] / $total) * 100) : 0,
            ];
        }

        return $result;
    }

    public static function schoolRateForLatestDay(?string $onOrBefore = null): array
    {
        $onOrBefore ??= date('Y-m-d');
        $latest = Database::fetchValue(
            'SELECT MAX(date) FROM attendance_records WHERE date <= ?',
            [$onOrBefore]
        );

        if ($latest === null) {
            return ['date' => null, 'rate' => 0, 'present' => 0, 'total' => 0];
        }

        $row = Database::fetch(
            "SELECT COUNT(*) AS total,
                    SUM(ar.status IN ('present','late')) AS attended
             FROM attendance_records ar
             INNER JOIN students s ON s.id = ar.student_id
             WHERE ar.date = ? AND s.status = 'active'",
            [$latest]
        );

        $total = (int) ($row['total'] ?? 0);
        $present = (int) ($row['attended'] ?? 0);

        return [
            'date' => $latest,
            'rate' => $total > 0 ? (int) round(($present / $total) * 100) : 0,
            'present' => $present,
            'total' => $total,
        ];
    }

    /** @return list<array> */
    public static function pendingRegisters(int $teacherId, string $date, int $termId): array
    {
        $day = (int) date('N', strtotime($date));
        if ($day > 5) {
            return [];
        }

        $rows = Database::fetchAll(
            "SELECT c.uuid AS class_uuid, c.name AS class_name,
                    MIN(ts.period_no) AS period_no,
                    MIN(ts.starts_at) AS starts_at
             FROM timetable_slots ts
             INNER JOIN classes c ON c.id = ts.class_id
             WHERE ts.term_id = ?
               AND ts.day_of_week = ?
               AND (ts.teacher_id = ? OR c.form_teacher_id = ?)
               AND NOT EXISTS (
                   SELECT 1 FROM attendance_registers ar
                   WHERE ar.class_id = ts.class_id
                     AND ar.term_id = ts.term_id
                     AND ar.date = ?
               )
             GROUP BY c.id, c.uuid, c.name
             ORDER BY period_no ASC",
            [$termId, $day, $teacherId, $teacherId, $date]
        );

        return array_map(static fn (array $row): array => [
            'class_id' => $row['class_uuid'],
            'class_name' => $row['class_name'],
            'period' => (int) $row['period_no'],
            'starts_at' => substr((string) $row['starts_at'], 0, 5),
        ], $rows);
    }

    /** @return list<array> */
    public static function registerStudents(int $classId, int $termId, string $date): array
    {
        $rows = Database::fetchAll(
            "SELECT s.uuid, s.first_name, s.last_name, s.admission_no,
                    ar.status, ar.note
             FROM students s
             LEFT JOIN attendance_records ar ON ar.student_id = s.id AND ar.date = ?
             WHERE s.class_id = ? AND s.status = 'active'
             ORDER BY s.last_name ASC, s.first_name ASC",
            [$date, $classId]
        );

        return array_map(static fn (array $row): array => [
            'student_id' => $row['uuid'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'admission_no' => $row['admission_no'],
            'status' => $row['status'] !== null ? ucfirst((string) $row['status']) : null,
            'note' => $row['note'],
        ], $rows);
    }

    /**
     * Records a complete daily register for one class. Every active student
     * must appear exactly once, which prevents silent partial attendance.
     *
     * @param list<array{student_id: string, status: string, note?: ?string}> $records
     */
    public static function markRegister(
        int $classId,
        int $termId,
        string $date,
        array $records,
        int $markedBy
    ): array {
        if ($records === []) {
            throw HttpException::validation('Please mark every student before saving.', [
                'records' => 'The register is empty.',
            ]);
        }

        $students = Database::fetchAll(
            "SELECT id, uuid, first_name, last_name FROM students
             WHERE class_id = ? AND status = 'active'",
            [$classId]
        );

        $byUuid = [];
        foreach ($students as $student) {
            $byUuid[$student['uuid']] = $student;
        }

        $seen = [];
        $prepared = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                throw HttpException::validation('The register contains an invalid entry.', [
                    "records.{$index}" => 'Each record must be an object.',
                ]);
            }

            $uuid = \AlFalaah\Core\Validator::uuid($record['student_id'] ?? null, "records.{$index}.student_id");
            $status = \AlFalaah\Core\Validator::enum(
                $record['status'] ?? null,
                "records.{$index}.status",
                self::STATUSES
            );
            $note = \AlFalaah\Core\Validator::string(
                $record['note'] ?? null,
                "records.{$index}.note",
                0,
                190,
                false
            );

            if (!isset($byUuid[$uuid])) {
                throw HttpException::validation(
                    'One or more students do not belong to this class.',
                    ["records.{$index}.student_id" => 'This student is not in the selected class.']
                );
            }

            if (isset($seen[$uuid])) {
                throw HttpException::validation(
                    'A student appears more than once in the register.',
                    ["records.{$index}.student_id" => 'Duplicate student entry.']
                );
            }

            $seen[$uuid] = true;
            $prepared[] = [
                'student' => $byUuid[$uuid],
                'status' => $status,
                'note' => $note,
            ];
        }

        if (count($prepared) !== count($students)) {
            $missing = array_diff_key($byUuid, $seen);
            $labels = array_map(
                static fn (array $student): string => trim($student['first_name'] . ' ' . $student['last_name']),
                $missing
            );
            throw HttpException::validation(
                'Please mark every student before saving the register.',
                ['records' => 'Missing: ' . implode(', ', array_slice($labels, 0, 6))]
            );
        }

        Database::transaction(static function () use ($prepared, $date, $termId, $classId, $markedBy): void {
            foreach ($prepared as $entry) {
                Database::execute(
                    "INSERT INTO attendance_records
                        (student_id, term_id, date, status, note, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        note = VALUES(note),
                        recorded_by = VALUES(recorded_by)",
                    [
                        (int) $entry['student']['id'],
                        $termId,
                        $date,
                        $entry['status'],
                        $entry['note'],
                        $markedBy,
                    ]
                );
            }

            Database::execute(
                'INSERT INTO attendance_registers (uuid, class_id, term_id, date, marked_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE marked_by = VALUES(marked_by), marked_at = NOW()',
                [Uuid::v4(), $classId, $termId, $date, $markedBy]
            );
        });

        return [
            'class_id' => $classId,
            'date' => $date,
            'marked' => count($prepared),
        ];
    }
}

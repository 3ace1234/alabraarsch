<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Database;
use AlFalaah\Core\Uuid;
use AlFalaah\Core\Validator;

final class Assignments
{
    /** @return list<array> */
    public static function forStudent(int $studentId, int $classId, int $termId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = Database::fetchAll(
            "SELECT a.uuid, a.title, a.description, a.due_on,
                    sub.name AS subject_name,
                    sub2.status AS submission_status, sub2.submitted_at
             FROM assignments a
             INNER JOIN subjects sub ON sub.id = a.subject_id
             LEFT JOIN assignment_submissions sub2
                ON sub2.assignment_id = a.id AND sub2.student_id = ?
             WHERE a.class_id = ? AND a.term_id = ?
             ORDER BY a.due_on ASC
             LIMIT {$limit}",
            [$studentId, $classId, $termId]
        );

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        return array_map(static function (array $row) use ($today, $tomorrow): array {
            $due = (string) $row['due_on'];
            $submitted = in_array($row['submission_status'], ['submitted', 'graded'], true);

            if ($submitted) {
                $status = 'Submitted';
                $tone = 'ok';
            } elseif ($due < $today) {
                $status = 'Overdue';
                $tone = 'danger';
            } elseif ($due <= $tomorrow) {
                $status = 'Due soon';
                $tone = 'warn';
            } else {
                $status = 'Pending';
                $tone = 'info';
            }

            $dueLabel = match (true) {
                $submitted => 'Submitted ' . date('j M', strtotime((string) $row['submitted_at'])),
                $due === $today => 'Today',
                $due === $tomorrow => 'Tomorrow, ' . date('j M', strtotime($due)),
                default => date('D, j M', strtotime($due)),
            };

            return [
                'id' => $row['uuid'],
                'title' => $row['title'],
                'description' => $row['description'],
                'subject' => $row['subject_name'],
                'due' => $dueLabel,
                'due_date' => $due,
                'status' => $status,
                'tone' => $tone,
                'is_overdue' => !$submitted && $due < $today,
            ];
        }, $rows);
    }

    /** @return list<array> */
    public static function forClass(int $classId, int $termId): array
    {
        $rows = Database::fetchAll(
            'SELECT a.uuid, a.title, a.description, a.due_on, a.created_at,
                    sub.uuid AS subject_uuid, sub.name AS subject_name,
                    u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
                    (SELECT COUNT(*) FROM assignment_submissions s
                     WHERE s.assignment_id = a.id AND s.status IN (\'submitted\',\'graded\')) AS submitted_count,
                    (SELECT COUNT(*) FROM students st
                     WHERE st.class_id = a.class_id AND st.status = \'active\') AS student_count
             FROM assignments a
             INNER JOIN subjects sub ON sub.id = a.subject_id
             LEFT JOIN users u ON u.id = a.created_by
             WHERE a.class_id = ? AND a.term_id = ?
             ORDER BY a.due_on ASC',
            [$classId, $termId]
        );

        return array_map(static fn (array $row): array => [
            'id' => $row['uuid'],
            'title' => $row['title'],
            'description' => $row['description'],
            'subject' => ['id' => $row['subject_uuid'], 'name' => $row['subject_name']],
            'teacher' => !empty($row['teacher_first_name'])
                ? trim($row['teacher_first_name'] . ' ' . $row['teacher_last_name'])
                : null,
            'due_on' => $row['due_on'],
            'due_date' => Academics::formatDate($row['due_on']),
            'submitted' => (int) $row['submitted_count'],
            'students' => (int) $row['student_count'],
            'created_at' => $row['created_at'],
        ], $rows);
    }

    public static function create(
        int $classId,
        int $subjectId,
        int $termId,
        string $title,
        string $description,
        string $dueOn,
        int $createdBy
    ): array {
        $uuid = Uuid::v4();
        Database::insert(
            'INSERT INTO assignments
                (uuid, class_id, subject_id, term_id, title, description, due_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$uuid, $classId, $subjectId, $termId, $title, $description, $dueOn, $createdBy]
        );

        return [
            'id' => $uuid,
            'title' => $title,
            'description' => $description,
            'subject_id' => $subjectId,
            'class_id' => $classId,
            'due_on' => $dueOn,
        ];
    }

    public static function parseDate(mixed $value): string
    {
        return Validator::date($value, 'due_on') ?? date('Y-m-d');
    }
}

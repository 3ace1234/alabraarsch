<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Uuid;
use AlFalaah\Core\Validator;
use PDO;

final class Results
{
    public static function summary(int $studentId, int $termId, int $classId): array
    {
        $term = Database::fetch(
            'SELECT t.*, s.name AS session_name, s.uuid AS session_uuid
             FROM terms t
             INNER JOIN academic_sessions s ON s.id = t.session_id
             WHERE t.id = ? LIMIT 1',
            [$termId]
        );

        if ($term === null) {
            throw HttpException::notFound('That academic term was not found.');
        }

        $rows = Database::fetchAll(
            'SELECT sub.uuid AS subject_uuid, sub.name AS subject_name,
                    r.ca_score, r.exam_score, r.total, r.grade, r.remark
             FROM results r
             INNER JOIN subjects sub ON sub.id = r.subject_id
             WHERE r.student_id = ? AND r.term_id = ?
             ORDER BY sub.name ASC',
            [$studentId, $termId]
        );

        $subjects = array_map(static fn (array $row): array => [
            'id' => $row['subject_uuid'],
            'name' => $row['subject_name'],
            'ca' => (float) $row['ca_score'],
            'exam' => (float) $row['exam_score'],
            'total' => (float) $row['total'],
            'grade' => $row['grade'],
            'remark' => $row['remark'],
        ], $rows);

        $total = array_sum(array_column($subjects, 'total'));
        $average = $subjects !== [] ? round($total / count($subjects), 1) : null;
        $ranking = Academics::positionForStudent($studentId, $termId, $classId);

        return [
            'term' => trim($term['name'] . ', ' . $term['session_name']),
            'term_id' => $term['uuid'],
            'published' => Academics::formatDate($term['results_published_at']),
            'subjects' => $subjects,
            'average' => $average,
            'position' => $ranking['position'],
            'class_size' => $ranking['class_size'],
        ];
    }

    public static function findSheetByUuid(string $uuid): ?array
    {
        return Database::fetch(
            'SELECT rs.*, c.uuid AS class_uuid, c.name AS class_name,
                    sub.uuid AS subject_uuid, sub.name AS subject_name,
                    t.uuid AS term_uuid, t.name AS term_name, t.session_id,
                    ses.name AS session_name,
                    u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
             FROM result_sheets rs
             INNER JOIN classes c ON c.id = rs.class_id
             INNER JOIN subjects sub ON sub.id = rs.subject_id
             INNER JOIN terms t ON t.id = rs.term_id
             INNER JOIN academic_sessions ses ON ses.id = t.session_id
             LEFT JOIN users u ON u.id = rs.teacher_id
             WHERE rs.uuid = ?
             LIMIT 1',
            [$uuid]
        );
    }

    public static function presentSheet(array $sheet, int $termId): array
    {
        $entered = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM results
             WHERE result_sheet_id = ?',
            [(int) $sheet['id']]
        );

        $students = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM students WHERE class_id = ? AND status = 'active'",
            [(int) $sheet['class_id']]
        );

        return [
            'id' => $sheet['uuid'],
            'class' => ['id' => $sheet['class_uuid'], 'name' => $sheet['class_name']],
            'subject' => ['id' => $sheet['subject_uuid'], 'name' => $sheet['subject_name']],
            'term' => [
                'id' => $sheet['term_uuid'],
                'name' => $sheet['term_name'],
                'session' => $sheet['session_name'],
                'label' => $sheet['term_name'] . ', ' . $sheet['session_name'],
            ],
            'teacher' => !empty($sheet['teacher_first_name'])
                ? trim($sheet['teacher_first_name'] . ' ' . $sheet['teacher_last_name'])
                : null,
            'status' => $sheet['status'],
            'status_label' => ucfirst($sheet['status']),
            'submitted_at' => $sheet['submitted_at'],
            'approved_at' => $sheet['approved_at'],
            'review_note' => $sheet['review_note'],
            'entered' => $entered,
            'students' => $students,
        ];
    }

    public static function listForTeacher(int $teacherId, int $termId): array
    {
        $sessionId = Database::fetchValue('SELECT session_id FROM terms WHERE id = ?', [$termId]);
        if ($sessionId === null) {
            throw HttpException::notFound('That academic term was not found.');
        }
        $sessionId = (int) $sessionId;

        $rows = Database::fetchAll(
            "SELECT c.uuid AS class_uuid, c.name AS class_name,
                    sub.uuid AS subject_uuid, sub.name AS subject_name,
                    rs.uuid AS sheet_uuid, COALESCE(rs.status, 'not_started') AS sheet_status,
                    rs.submitted_at,
                    (SELECT COUNT(*) FROM students st
                        WHERE st.class_id = cs.class_id AND st.status = 'active') AS student_count,
                    (SELECT COUNT(*) FROM results r
                        INNER JOIN students st2 ON st2.id = r.student_id
                        WHERE r.term_id = ? AND r.subject_id = cs.subject_id
                          AND st2.class_id = cs.class_id) AS entered_count
             FROM class_subjects cs
             INNER JOIN classes c ON c.id = cs.class_id
             INNER JOIN subjects sub ON sub.id = cs.subject_id
             LEFT JOIN result_sheets rs
                ON rs.class_id = cs.class_id AND rs.subject_id = cs.subject_id AND rs.term_id = ?
             WHERE cs.teacher_id = ? AND cs.session_id = ?
             ORDER BY c.name ASC, sub.name ASC",
            [$termId, $termId, $teacherId, $sessionId]
        );

        return array_map(static fn (array $row): array => [
            'sheet_id' => $row['sheet_uuid'],
            'class' => ['id' => $row['class_uuid'], 'name' => $row['class_name']],
            'subject' => ['id' => $row['subject_uuid'], 'name' => $row['subject_name']],
            'status' => $row['sheet_status'],
            'status_label' => $row['sheet_status'] === 'not_started' ? 'Not started' : ucfirst($row['sheet_status']),
            'submitted_at' => $row['submitted_at'],
            'entered' => (int) $row['entered_count'],
            'students' => (int) $row['student_count'],
        ], $rows);
    }

    public static function listForAdmin(int $termId, ?string $status = null): array
    {
        $sql = 'SELECT rs.uuid AS sheet_uuid, rs.status, rs.submitted_at, rs.approved_at,
                       c.uuid AS class_uuid, c.name AS class_name,
                       sub.uuid AS subject_uuid, sub.name AS subject_name,
                       u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
                       (SELECT COUNT(*) FROM students st WHERE st.class_id = rs.class_id AND st.status = \'active\') AS student_count,
                       (SELECT COUNT(*) FROM results r WHERE r.result_sheet_id = rs.id) AS entered_count
                FROM result_sheets rs
                INNER JOIN classes c ON c.id = rs.class_id
                INNER JOIN subjects sub ON sub.id = rs.subject_id
                LEFT JOIN users u ON u.id = rs.teacher_id
                WHERE rs.term_id = ?';
        $params = [$termId];

        if ($status !== null) {
            $sql .= ' AND rs.status = ?';
            $params[] = $status;
        }

        $sql .= " ORDER BY FIELD(rs.status, 'submitted', 'draft', 'approved', 'published'), rs.submitted_at DESC";

        $rows = Database::fetchAll($sql, $params);

        return array_map(static fn (array $row): array => [
            'id' => $row['sheet_uuid'],
            'status' => $row['status'],
            'status_label' => ucfirst($row['status']),
            'class' => ['id' => $row['class_uuid'], 'name' => $row['class_name']],
            'subject' => ['id' => $row['subject_uuid'], 'name' => $row['subject_name']],
            'teacher' => !empty($row['teacher_first_name'])
                ? trim($row['teacher_first_name'] . ' ' . $row['teacher_last_name'])
                : null,
            'students' => (int) $row['student_count'],
            'entered' => (int) $row['entered_count'],
            'submitted_at' => $row['submitted_at'],
            'approved_at' => $row['approved_at'],
        ], $rows);
    }

    public static function sheetDetail(array $sheet): array
    {
        $rows = Database::fetchAll(
            "SELECT s.uuid AS student_uuid, s.first_name, s.last_name, s.admission_no,
                    r.ca_score, r.exam_score, r.total, r.grade, r.remark
             FROM students s
             LEFT JOIN results r
                ON r.student_id = s.id AND r.term_id = ? AND r.subject_id = ?
             WHERE s.class_id = ? AND s.status = 'active'
             ORDER BY s.last_name ASC, s.first_name ASC",
            [(int) $sheet['term_id'], (int) $sheet['subject_id'], (int) $sheet['class_id']]
        );

        $present = self::presentSheet($sheet, (int) $sheet['term_id']);

        return [
            'sheet' => $present,
            'students' => array_map(static fn (array $row): array => [
                'id' => $row['student_uuid'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'admission_no' => $row['admission_no'],
                'ca_score' => $row['ca_score'] !== null ? (float) $row['ca_score'] : null,
                'exam_score' => $row['exam_score'] !== null ? (float) $row['exam_score'] : null,
                'total' => $row['total'] !== null ? (float) $row['total'] : null,
                'grade' => $row['grade'],
                'remark' => $row['remark'],
            ], $rows),
        ];
    }

    public static function createSheet(int $classId, int $subjectId, int $termId, int $teacherId): array
    {
        $existing = Database::fetch(
            'SELECT * FROM result_sheets WHERE class_id = ? AND subject_id = ? AND term_id = ? LIMIT 1',
            [$classId, $subjectId, $termId]
        );

        if ($existing !== null) {
            return ['sheet' => $existing, 'created' => false];
        }

        $uuid = Uuid::v4();
        $id = Database::insert(
            "INSERT INTO result_sheets (uuid, class_id, subject_id, term_id, teacher_id, status)
             VALUES (?, ?, ?, ?, ?, 'draft')",
            [$uuid, $classId, $subjectId, $termId, $teacherId]
        );

        $sheet = Database::fetch('SELECT * FROM result_sheets WHERE id = ?', [$id]);
        if ($sheet === null) {
            throw HttpException::server('The result sheet could not be created.');
        }

        return ['sheet' => $sheet, 'created' => true];
    }

    /**
     * @param list<array{student_id: string, ca_score: mixed, exam_score: mixed}> $records
     */
    public static function saveSheet(array $sheet, array $records, int $actorId): array
    {
        if ($sheet['status'] !== 'draft') {
            throw HttpException::validation('This result sheet can no longer be edited.', [
                'sheet' => 'Only draft or returned sheets can be edited.',
            ]);
        }

        if ($records === []) {
            throw HttpException::validation('Add at least one result before saving.', [
                'results' => 'No result rows were supplied.',
            ]);
        }

        $students = Database::fetchAll(
            "SELECT id, uuid, first_name, last_name FROM students WHERE class_id = ? AND status = 'active'",
            [(int) $sheet['class_id']]
        );
        $studentMap = [];
        foreach ($students as $student) {
            $studentMap[$student['uuid']] = $student;
        }

        $prepared = [];
        $seen = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                throw HttpException::validation('Invalid result row.', [
                    "results.{$index}" => 'Each result must be an object.',
                ]);
            }

            $studentUuid = Validator::uuid($record['student_id'] ?? null, "results.{$index}.student_id");
            if (!isset($studentMap[$studentUuid])) {
                throw HttpException::validation('A student does not belong to this class.', [
                    "results.{$index}.student_id" => 'Student is not in the sheet class.',
                ]);
            }
            if (isset($seen[$studentUuid])) {
                throw HttpException::validation('A student appears more than once.', [
                    "results.{$index}.student_id" => 'Duplicate student entry.',
                ]);
            }
            $seen[$studentUuid] = true;

            $ca = Validator::number($record['ca_score'] ?? null, "results.{$index}.ca_score", 0, 40);
            $exam = Validator::number($record['exam_score'] ?? null, "results.{$index}.exam_score", 0, 60);
            $total = round((float) $ca + (float) $exam, 2);
            $graded = Academics::grade($total);

            $prepared[] = [
                'student_id' => (int) $studentMap[$studentUuid]['id'],
                'ca' => $ca,
                'exam' => $exam,
                'total' => $total,
                'grade' => $graded['grade'],
                'remark' => $graded['remark'],
            ];
        }

        Database::transaction(static function (PDO $pdo) use ($prepared, $sheet): void {
            $statement = $pdo->prepare(
                'INSERT INTO results
                    (student_id, term_id, subject_id, result_sheet_id, ca_score, exam_score, total, grade, remark)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    result_sheet_id = VALUES(result_sheet_id),
                    ca_score = VALUES(ca_score),
                    exam_score = VALUES(exam_score),
                    total = VALUES(total),
                    grade = VALUES(grade),
                    remark = VALUES(remark)'
            );

            foreach ($prepared as $result) {
                $statement->execute([
                    $result['student_id'],
                    (int) $sheet['term_id'],
                    (int) $sheet['subject_id'],
                    (int) $sheet['id'],
                    $result['ca'],
                    $result['exam'],
                    $result['total'],
                    $result['grade'],
                    $result['remark'],
                ]);
            }
        });

        $fresh = Database::fetch('SELECT * FROM result_sheets WHERE id = ?', [(int) $sheet['id']]);
        if ($fresh === null) {
            throw HttpException::server('The result sheet could not be loaded.');
        }

        return $fresh;
    }

    public static function submitSheet(array $sheet, int $actorId): array
    {
        if ($sheet['status'] !== 'draft') {
            throw HttpException::validation('Only a draft result sheet can be submitted.', [
                'sheet' => 'This sheet is already ' . $sheet['status'] . '.',
            ]);
        }

        $studentCount = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM students WHERE class_id = ? AND status = 'active'",
            [(int) $sheet['class_id']]
        );
        $enteredCount = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM results
             WHERE term_id = ? AND subject_id = ? AND result_sheet_id = ?',
            [(int) $sheet['term_id'], (int) $sheet['subject_id'], (int) $sheet['id']]
        );

        if ($studentCount === 0 || $enteredCount < $studentCount) {
            throw HttpException::validation('Enter results for every student before submitting.', [
                'results' => "Entered {$enteredCount} of {$studentCount} students.",
            ]);
        }

        Database::execute(
            "UPDATE result_sheets
             SET status = 'submitted', submitted_at = NOW(), review_note = NULL
             WHERE id = ?",
            [(int) $sheet['id']]
        );

        $fresh = Database::fetch('SELECT * FROM result_sheets WHERE id = ?', [(int) $sheet['id']]);
        if ($fresh === null) {
            throw HttpException::server('The result sheet could not be loaded.');
        }

        return $fresh;
    }

    public static function approveSheet(array $sheet, int $adminId, ?string $note = null): array
    {
        if ($sheet['status'] !== 'submitted') {
            throw HttpException::validation('Only a submitted sheet can be approved.', [
                'sheet' => 'This sheet is ' . $sheet['status'] . '.',
            ]);
        }

        Database::execute(
            "UPDATE result_sheets
             SET status = 'approved', approved_by = ?, approved_at = NOW(), review_note = ?
             WHERE id = ?",
            [$adminId, $note, (int) $sheet['id']]
        );

        $fresh = Database::fetch('SELECT * FROM result_sheets WHERE id = ?', [(int) $sheet['id']]);
        if ($fresh === null) {
            throw HttpException::server('The result sheet could not be loaded.');
        }

        return $fresh;
    }

    public static function returnSheet(array $sheet, string $note): array
    {
        if ($sheet['status'] !== 'submitted') {
            throw HttpException::validation('Only a submitted sheet can be returned for correction.', [
                'sheet' => 'This sheet is ' . $sheet['status'] . '.',
            ]);
        }

        Database::execute(
            "UPDATE result_sheets
             SET status = 'draft', review_note = ?, submitted_at = NULL
             WHERE id = ?",
            [$note, (int) $sheet['id']]
        );

        $fresh = Database::fetch('SELECT * FROM result_sheets WHERE id = ?', [(int) $sheet['id']]);
        if ($fresh === null) {
            throw HttpException::server('The result sheet could not be loaded.');
        }

        return $fresh;
    }

    public static function publishTerm(int $termId, int $adminId): array
    {
        $blocked = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM result_sheets WHERE term_id = ? AND status IN ('draft','submitted')",
            [$termId]
        );

        if ($blocked > 0) {
            throw HttpException::validation('Approve or return every result sheet before publishing.', [
                'term_id' => "{$blocked} result sheet(s) are still pending.",
            ]);
        }

        $approved = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM result_sheets WHERE term_id = ? AND status = 'approved'",
            [$termId]
        );

        if ($approved === 0) {
            throw HttpException::validation('There are no approved result sheets to publish.', [
                'term_id' => 'Nothing is ready to publish.',
            ]);
        }

        Database::transaction(static function (PDO $pdo) use ($termId, $adminId): void {
            $pdo->prepare("UPDATE result_sheets SET status = 'published' WHERE term_id = ? AND status = 'approved'")
                ->execute([$termId]);
            $pdo->prepare('UPDATE terms SET results_published_at = NOW() WHERE id = ?')
                ->execute([$termId]);
        });

        return ['term_id' => $termId, 'published_sheets' => $approved, 'published_by' => $adminId];
    }

    public static function classPerformance(int $teacherId, int $termId): array
    {
        $rows = Database::fetchAll(
            'SELECT c.uuid, c.name,
                    ROUND(AVG(r.total), 1) AS average,
                    COUNT(DISTINCT r.student_id) AS students
             FROM results r
             INNER JOIN students s ON s.id = r.student_id
             INNER JOIN classes c ON c.id = s.class_id
             WHERE r.term_id = ? AND s.class_id IN (
                 SELECT class_id FROM class_subjects WHERE teacher_id = ? AND session_id = ?
                 UNION
                 SELECT id AS class_id FROM classes WHERE form_teacher_id = ?
             )
             GROUP BY c.id, c.uuid, c.name
             ORDER BY average DESC',
            [$termId, $teacherId, Academics::currentSessionId(), $teacherId]
        );

        return array_map(static fn (array $row): array => [
            'class' => ['id' => $row['uuid'], 'name' => $row['name']],
            'average' => (float) $row['average'],
            'students' => (int) $row['students'],
        ], $rows);
    }
}

<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;

final class Students
{
    /** @var array<int, list<int>> */
    private static array $teacherClasses = [];

    public static function findByUuid(string $uuid): ?array
    {
        return Database::fetch(
            'SELECT s.*, c.uuid AS class_uuid, c.name AS class_name, c.level AS class_level,
                    u.uuid AS user_uuid, u.email AS user_email, u.phone AS user_phone,
                    ft.first_name AS form_teacher_first, ft.last_name AS form_teacher_last
             FROM students s
             INNER JOIN classes c ON c.id = s.class_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN users ft ON ft.id = c.form_teacher_id
             WHERE s.uuid = ?
             LIMIT 1',
            [$uuid]
        );
    }

    public static function findById(int $id): ?array
    {
        $uuid = Database::fetchValue('SELECT uuid FROM students WHERE id = ?', [$id]);
        if ($uuid === null) {
            return null;
        }

        return self::findByUuid((string) $uuid);
    }

    public static function requireByUuid(string $uuid): array
    {
        $student = self::findByUuid($uuid);
        if ($student === null) {
            throw HttpException::notFound('That student was not found.');
        }

        return $student;
    }

    /** @return list<array> */
    public static function guardians(int $studentId): array
    {
        return Database::fetchAll(
            'SELECT u.uuid, u.first_name, u.last_name, u.email, u.phone,
                    sg.relationship, sg.is_primary
             FROM student_guardians sg
             INNER JOIN users u ON u.id = sg.user_id
             WHERE sg.student_id = ?
             ORDER BY sg.is_primary DESC, sg.created_at ASC',
            [$studentId]
        );
    }

    public static function present(array $student, bool $withGuardian = true): array
    {
        $guardians = $withGuardian ? self::guardians((int) $student['id']) : [];
        $primary = $guardians[0] ?? null;

        return [
            'id' => $student['uuid'],
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
            'name' => trim($student['first_name'] . ' ' . $student['last_name']),
            'initials' => strtoupper(
                mb_substr((string) $student['first_name'], 0, 1) . mb_substr((string) $student['last_name'], 0, 1)
            ),
            'admission_no' => $student['admission_no'],
            'class' => [
                'id' => $student['class_uuid'],
                'name' => $student['class_name'],
                'level' => $student['class_level'],
                'level_label' => Academics::levelLabel($student['class_level']),
            ],
            'level' => Academics::levelLabel($student['class_level']),
            'house' => $student['house'],
            'status' => $student['status'],
            'date_of_birth' => $student['date_of_birth'],
            'gender' => $student['gender'],
            'admitted_on' => $student['admitted_on'],
            'form_teacher' => !empty($student['form_teacher_first'])
                ? trim($student['form_teacher_first'] . ' ' . $student['form_teacher_last'])
                : null,
            'guardian' => $primary !== null ? [
                'name' => trim($primary['first_name'] . ' ' . $primary['last_name']),
                'relation' => ucfirst($primary['relationship']),
                'phone' => $primary['phone'],
                'email' => $primary['email'],
            ] : null,
            'guardians' => array_map(static fn (array $guardian): array => [
                'id' => $guardian['uuid'],
                'name' => trim($guardian['first_name'] . ' ' . $guardian['last_name']),
                'relation' => ucfirst($guardian['relationship']),
                'phone' => $guardian['phone'],
                'email' => $guardian['email'],
                'is_primary' => (bool) $guardian['is_primary'],
            ], $guardians),
            'account' => !empty($student['user_uuid']) ? [
                'id' => $student['user_uuid'],
                'email' => $student['user_email'],
            ] : null,
        ];
    }

    public static function canView(array $user, array $student): bool
    {
        if ($user['role'] === 'admin') {
            return true;
        }

        if ($user['role'] === 'student') {
            return (int) ($student['user_id'] ?? 0) === (int) $user['id'];
        }

        if ($user['role'] === 'parent') {
            return self::isGuardian((int) $user['id'], (int) $student['id']);
        }

        if ($user['role'] === 'teacher') {
            return in_array((int) $student['class_id'], self::teacherClassIds((int) $user['id']), true);
        }

        return false;
    }

    public static function requireViewable(string $uuid, ?array $user = null): array
    {
        $user ??= \AlFalaah\Core\Auth::requireUser();
        $student = self::findByUuid($uuid);

        // Return 404 rather than 403 so unauthorised users cannot enumerate
        // student records by watching status-code differences.
        if ($student === null || !self::canView($user, $student)) {
            throw HttpException::notFound('That student was not found or is not available to your account.');
        }

        return $student;
    }

    public static function isGuardian(int $userId, int $studentId): bool
    {
        return (bool) Database::fetchValue(
            'SELECT 1 FROM student_guardians WHERE user_id = ? AND student_id = ? LIMIT 1',
            [$userId, $studentId]
        );
    }

    /** @return list<array> */
    public static function childrenOf(int $parentUserId): array
    {
        return Database::fetchAll(
            'SELECT s.*, c.uuid AS class_uuid, c.name AS class_name, c.level AS class_level,
                    u.uuid AS user_uuid, u.email AS user_email, u.phone AS user_phone,
                    ft.first_name AS form_teacher_first, ft.last_name AS form_teacher_last,
                    sg.relationship
             FROM student_guardians sg
             INNER JOIN students s ON s.id = sg.student_id
             INNER JOIN classes c ON c.id = s.class_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN users ft ON ft.id = c.form_teacher_id
             WHERE sg.user_id = ? AND s.status = \'active\'
             ORDER BY s.first_name ASC, s.last_name ASC',
            [$parentUserId]
        );
    }

    /** @return list<int> */
    public static function teacherClassIds(int $teacherUserId): array
    {
        if (array_key_exists($teacherUserId, self::$teacherClasses)) {
            return self::$teacherClasses[$teacherUserId];
        }

        $sessionId = Academics::currentSessionId();
        $rows = Database::fetchAll(
            'SELECT DISTINCT class_id FROM (
                SELECT class_id FROM class_subjects WHERE teacher_id = ? AND session_id = ?
                UNION
                SELECT id AS class_id FROM classes WHERE form_teacher_id = ?
            ) assigned',
            [$teacherUserId, $sessionId, $teacherUserId]
        );

        self::$teacherClasses[$teacherUserId] = array_map(
            static fn (array $row): int => (int) $row['class_id'],
            $rows
        );

        return self::$teacherClasses[$teacherUserId];
    }

    public static function teacherCanManageClass(int $teacherUserId, int $classId): bool
    {
        return in_array($classId, self::teacherClassIds($teacherUserId), true);
    }

    public static function teacherCanManageSubject(int $teacherUserId, int $classId, int $subjectId): bool
    {
        return (bool) Database::fetchValue(
            'SELECT 1 FROM class_subjects
             WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND session_id = ?
             LIMIT 1',
            [$teacherUserId, $classId, $subjectId, Academics::currentSessionId()]
        );
    }

    public static function clearTeacherCache(): void
    {
        self::$teacherClasses = [];
    }

    /**
     * @return array{rows: list<array>, total: int}
     */
    public static function paginate(
        string $search = '',
        ?string $classUuid = null,
        ?string $status = null,
        int $page = 1,
        int $perPage = 25,
        ?array $restrictClassIds = null
    ): array {
        $where = ['1 = 1'];
        $params = [];

        if ($restrictClassIds !== null) {
            $restrictClassIds = array_values(array_map('intval', $restrictClassIds));
            if ($restrictClassIds === []) {
                return ['rows' => [], 'total' => 0];
            }
            $where[] = 's.class_id IN (' . implode(',', $restrictClassIds) . ')';
        }

        if ($search !== '') {
            $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_no LIKE ? OR CONCAT(s.first_name, \' \', s.last_name) LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }

        if ($classUuid !== null) {
            $class = Academics::classByUuid($classUuid);
            $where[] = 's.class_id = ?';
            $params[] = $class['id'];
        }

        if ($status !== null) {
            $where[] = 's.status = ?';
            $params[] = $status;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM students s WHERE {$whereSql}",
            $params
        );

        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            "SELECT s.*, c.uuid AS class_uuid, c.name AS class_name, c.level AS class_level,
                    u.uuid AS user_uuid, u.email AS user_email, u.phone AS user_phone,
                    ft.first_name AS form_teacher_first, ft.last_name AS form_teacher_last
             FROM students s
             INNER JOIN classes c ON c.id = s.class_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN users ft ON ft.id = c.form_teacher_id
             WHERE {$whereSql}
             ORDER BY s.last_name ASC, s.first_name ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }
}

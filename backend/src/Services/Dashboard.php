<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Auth;
use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;

final class Dashboard
{
    public static function forUser(array $user): array
    {
        $term = Academics::currentTerm();

        return match ($user['role']) {
            'student' => self::student($user, $term),
            'parent' => self::parent($user, $term),
            'teacher' => self::teacher($user, $term),
            'admin' => self::admin($user, $term),
            default => throw HttpException::forbidden(),
        };
    }

    private static function student(array $user, array $term): array
    {
        $student = self::studentForUser($user);
        $published = Academics::latestPublishedTerm((int) $student['id']);
        $resultTermId = $published !== null ? (int) $published['id'] : (int) $term['id'];

        $results = Results::summary((int) $student['id'], $resultTermId, (int) $student['class_id']);
        $attendance = Attendance::summary((int) $student['id'], (int) $term['id']);
        $fees = Fees::summary((int) $student['id'], (int) $term['id']);

        return [
            'role' => 'student',
            'user' => Auth::present($user),
            'term' => Academics::presentTerm($term),
            'student' => Students::present($student),
            'stats' => [
                'attendance_rate' => $attendance['rate'],
                'average' => $results['average'],
                'position' => $results['position'],
                'class_size' => $results['class_size'],
                'fee_balance' => $fees['balance'],
                'fee_total' => $fees['total'],
                'unread_notifications' => Notifications::unreadCount((int) $user['id']),
            ],
            'up_next' => Timetable::studentUpNext((int) $student['class_id'], (int) $term['id'], 3),
            'results' => $results,
            'attendance' => $attendance,
            'fees' => $fees,
            'assignments' => Assignments::forStudent(
                (int) $student['id'],
                (int) $student['class_id'],
                (int) $term['id']
            ),
            'announcements' => Announcements::listForRole('student', 4),
        ];
    }

    private static function parent(array $user, array $term): array
    {
        $children = Students::childrenOf((int) $user['id']);
        if ($children === []) {
            throw HttpException::notFound('No student records are linked to this guardian account.');
        }

        $summaries = [];
        foreach ($children as $child) {
            $summaries[] = self::childSummary($child, $term);
        }

        $active = $summaries[0];

        return [
            'role' => 'parent',
            'user' => Auth::present($user),
            'term' => Academics::presentTerm($term),
            'children' => $summaries,
            'active_child' => $active['student'],
            'student' => $active['student'],
            'stats' => $active['stats'],
            'up_next' => $active['up_next'],
            'results' => $active['results'],
            'attendance' => $active['attendance'],
            'fees' => $active['fees'],
            'assignments' => $active['assignments'],
            'announcements' => Announcements::listForRole('parent', 4),
        ];
    }

    private static function teacher(array $user, array $term): array
    {
        $classIds = Students::teacherClassIds((int) $user['id']);
        $classes = [];
        if ($classIds !== []) {
            $placeholders = implode(',', array_fill(0, count($classIds), '?'));
            $rows = Database::fetchAll(
                "SELECT c.uuid, c.name, c.level, COUNT(s.id) AS student_count
                 FROM classes c
                 LEFT JOIN students s ON s.class_id = c.id AND s.status = 'active'
                 WHERE c.id IN ({$placeholders})
                 GROUP BY c.id, c.uuid, c.name, c.level
                 ORDER BY c.name ASC",
                $classIds
            );
            $classes = array_map(static fn (array $row): array => [
                'id' => $row['uuid'],
                'name' => $row['name'],
                'level' => Academics::levelLabel($row['level']),
                'students' => (int) $row['student_count'],
            ], $rows);
        }

        $performance = [];
        foreach (Results::classPerformance((int) $user['id'], (int) $term['id']) as $item) {
            $performance[$item['class']['id']] = $item['average'];
        }
        foreach ($classes as &$class) {
            $class['average'] = $performance[$class['id']] ?? null;
        }
        unset($class);

        $today = date('Y-m-d');
        $schedule = Timetable::teacherDay((int) $user['id'], $today, (int) $term['id']);
        $pending = Attendance::pendingRegisters((int) $user['id'], $today, (int) $term['id']);
        $sheets = Results::listForTeacher((int) $user['id'], (int) $term['id']);
        $outstandingSheets = array_values(array_filter(
            $sheets,
            static fn (array $sheet): bool => !in_array($sheet['status'], ['approved', 'published'], true)
        ));

        $studentCount = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM students
             WHERE status = 'active' AND class_id IN (" .
                ($classIds === [] ? '0' : implode(',', array_map('intval', $classIds))) . ')'
        );

        return [
            'role' => 'teacher',
            'user' => Auth::present($user),
            'term' => Academics::presentTerm($term),
            'teacher' => [
                'name' => trim($user['first_name'] . ' ' . $user['last_name']),
                'initials' => strtoupper(mb_substr((string) $user['first_name'], 0, 1) . mb_substr((string) $user['last_name'], 0, 1)),
                'class_count' => count($classes),
            ],
            'stats' => [
                'classes' => count($classes),
                'students' => $studentCount,
                'lessons_today' => count($schedule),
                'pending_registers' => count($pending),
                'sheets_outstanding' => count($outstandingSheets),
                'unread_notifications' => Notifications::unreadCount((int) $user['id']),
            ],
            'classes' => $classes,
            'schedule' => $schedule,
            'pending_registers' => $pending,
            'result_sheets' => array_slice($outstandingSheets, 0, 6),
            'class_performance' => array_values($performance),
            'announcements' => Announcements::listForRole('teacher', 4),
        ];
    }

    private static function admin(array $user, array $term): array
    {
        $studentCount = (int) Database::fetchValue("SELECT COUNT(*) FROM students WHERE status = 'active'");
        $teacherCount = (int) Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'active'");
        $adminCount = (int) Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
        $attendance = Attendance::schoolRateForLatestDay();
        $finance = Fees::schoolSummary((int) $term['id']);

        $levelRows = Database::fetchAll(
            "SELECT c.level, COUNT(DISTINCT c.id) AS classes, COUNT(s.id) AS students
             FROM classes c
             LEFT JOIN students s ON s.class_id = c.id AND s.status = 'active'
             GROUP BY c.level
             ORDER BY FIELD(c.level, 'nursery', 'primary', 'junior_secondary', 'senior_secondary')"
        );

        $levels = array_map(static fn (array $row): array => [
            'level' => $row['level'],
            'label' => Academics::levelLabel($row['level']),
            'classes' => (int) $row['classes'],
            'students' => (int) $row['students'],
        ], $levelRows);

        $pipeline = [
            'new' => 0,
            'assessment' => 0,
            'offer' => 0,
            'enrolled' => 0,
            'declined' => 0,
        ];
        foreach (Database::fetchAll('SELECT status, COUNT(*) AS total FROM admission_applications GROUP BY status') as $row) {
            $pipeline[$row['status']] = (int) $row['total'];
        }

        $approvalRows = Results::listForAdmin((int) $term['id'], 'submitted');
        $recentApplications = array_map(static function (array $row): array {
            return [
                'id' => $row['uuid'],
                'applicant_name' => $row['applicant_name'],
                'guardian_name' => $row['guardian_name'],
                'level' => $row['level'],
                'level_label' => Academics::levelLabel($row['level']),
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'date' => Academics::formatDate($row['created_at']),
            ];
        }, Database::fetchAll(
            'SELECT uuid, applicant_name, guardian_name, level, status, created_at
             FROM admission_applications
             ORDER BY created_at DESC
             LIMIT 5'
        ));

        return [
            'role' => 'admin',
            'user' => Auth::present($user),
            'term' => Academics::presentTerm($term),
            'stats' => [
                'students' => $studentCount,
                'teachers' => $teacherCount,
                'administrators' => $adminCount,
                'staff' => $teacherCount + $adminCount,
                'attendance_rate' => $attendance['rate'],
                'attendance_date' => $attendance['date'],
                'fees_collected' => $finance['collected'],
                'fees_outstanding' => $finance['outstanding'],
                'fees_collection_rate' => $finance['collection_rate'],
                'pending_approvals' => count($approvalRows),
                'unread_notifications' => Notifications::unreadCount((int) $user['id']),
            ],
            'finance' => $finance,
            'levels' => $levels,
            'admissions' => [
                'pipeline' => $pipeline,
                'recent' => $recentApplications,
            ],
            'approvals' => array_slice($approvalRows, 0, 5),
            'announcements' => Announcements::listForRole('admin', 4),
        ];
    }

    private static function childSummary(array $child, array $term): array
    {
        $published = Academics::latestPublishedTerm((int) $child['id']);
        $resultTermId = $published !== null ? (int) $published['id'] : (int) $term['id'];
        $results = Results::summary((int) $child['id'], $resultTermId, (int) $child['class_id']);
        $attendance = Attendance::summary((int) $child['id'], (int) $term['id']);
        $fees = Fees::summary((int) $child['id'], (int) $term['id']);

        return [
            'student' => Students::present($child),
            'stats' => [
                'attendance_rate' => $attendance['rate'],
                'average' => $results['average'],
                'position' => $results['position'],
                'class_size' => $results['class_size'],
                'fee_balance' => $fees['balance'],
                'fee_total' => $fees['total'],
            ],
            'up_next' => Timetable::studentUpNext((int) $child['class_id'], (int) $term['id'], 3),
            'results' => $results,
            'attendance' => $attendance,
            'fees' => $fees,
            'assignments' => Assignments::forStudent(
                (int) $child['id'],
                (int) $child['class_id'],
                (int) $term['id']
            ),
        ];
    }

    private static function studentForUser(array $user): array
    {
        if (empty($user['student_uuid'])) {
            throw HttpException::forbidden('This account is not linked to a student record.');
        }

        $student = Students::findByUuid((string) $user['student_uuid']);
        if ($student === null) {
            throw HttpException::notFound('The linked student record was not found.');
        }

        return $student;
    }
}

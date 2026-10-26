<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Auth;
use AlFalaah\Core\Database;
use AlFalaah\Core\Response;
use AlFalaah\Services\Academics;
use AlFalaah\Services\Students;

final class ReferenceController
{
    public static function terms(): never
    {
        Auth::requireUser();
        $terms = array_map(
            static function (array $term): array {
                $presented = Academics::presentTerm($term);
                $presented['is_published'] = $term['results_published_at'] !== null;

                return $presented;
            },
            Academics::allTerms()
        );

        Response::ok($terms);
    }

    public static function classes(): never
    {
        $user = Auth::requireUser();
        $where = '';
        $params = [];

        if ($user['role'] === 'teacher') {
            $classIds = Students::teacherClassIds((int) $user['id']);
            if ($classIds === []) {
                Response::ok([]);
            }
            $where = 'WHERE c.id IN (' . implode(',', array_map('intval', $classIds)) . ')';
        } elseif ($user['role'] === 'student') {
            $where = 'WHERE c.id = (SELECT class_id FROM students WHERE user_id = ? LIMIT 1)';
            $params[] = (int) $user['id'];
        } elseif ($user['role'] === 'parent') {
            $where = 'WHERE c.id IN (
                SELECT s.class_id FROM student_guardians sg
                INNER JOIN students s ON s.id = sg.student_id
                WHERE sg.user_id = ?
            )';
            $params[] = (int) $user['id'];
        }

        $rows = Database::fetchAll(
            "SELECT c.*, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
                    (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = 'active') AS student_count
             FROM classes c
             LEFT JOIN users u ON u.id = c.form_teacher_id
             {$where}
             ORDER BY c.name ASC",
            $params
        );

        Response::ok(array_map(static function (array $row): array {
            $class = Academics::presentClass($row);
            $class['students'] = (int) $row['student_count'];

            return $class;
        }, $rows));
    }

    public static function subjects(): never
    {
        Auth::requireUser();
        $rows = Database::fetchAll('SELECT * FROM subjects ORDER BY name ASC');

        Response::ok(array_map(static fn (array $row): array => [
            'id' => $row['uuid'],
            'name' => $row['name'],
            'code' => $row['code'],
        ], $rows));
    }

    public static function teachers(): never
    {
        Auth::requireRole('admin');
        $rows = Database::fetchAll(
            "SELECT uuid, first_name, last_name, email FROM users
             WHERE role = 'teacher' AND status = 'active'
             ORDER BY last_name ASC, first_name ASC"
        );

        Response::ok(array_map(static fn (array $row): array => [
            'id' => $row['uuid'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'email' => $row['email'],
        ], $rows));
    }
}

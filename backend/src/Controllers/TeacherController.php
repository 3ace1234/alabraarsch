<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Audit;
use AlFalaah\Core\Auth;
use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Request;
use AlFalaah\Core\Response;
use AlFalaah\Core\Validator;
use AlFalaah\Services\Academics;
use AlFalaah\Services\Assignments;
use AlFalaah\Services\Attendance;
use AlFalaah\Services\Notifications;
use AlFalaah\Services\Results;
use AlFalaah\Services\Students;

final class TeacherController
{
    public static function classes(): never
    {
        $user = Auth::requireRole('teacher');
        $classIds = Students::teacherClassIds((int) $user['id']);

        if ($classIds === []) {
            Response::ok([]);
        }

        $where = 'WHERE c.id IN (' . implode(',', array_map('intval', $classIds)) . ')';

        $rows = Database::fetchAll(
            "SELECT c.*, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
                    (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = 'active') AS student_count
             FROM classes c
             LEFT JOIN users u ON u.id = c.form_teacher_id
             {$where}
             ORDER BY c.name ASC"
        );

        $performance = [];
        foreach (Results::classPerformance((int) $user['id'], (int) Academics::currentTerm()['id']) as $item) {
            $performance[$item['class']['id']] = $item['average'];
        }

        Response::ok(array_map(static function (array $row) use ($performance): array {
            $class = Academics::presentClass($row);
            $class['students'] = (int) $row['student_count'];
            $class['average'] = $performance[$class['id']] ?? null;

            return $class;
        }, $rows));
    }

    public static function schedule(): never
    {
        $user = Auth::requireRole('teacher');
        $date = self::dateFromRequest(Request::query('date'), true);
        $term = Academics::currentTerm();

        Response::ok([
            'date' => $date,
            'term' => Academics::presentTerm($term),
            'lessons' => \AlFalaah\Services\Timetable::teacherDay((int) $user['id'], $date, (int) $term['id']),
            'pending_registers' => Attendance::pendingRegisters((int) $user['id'], $date, (int) $term['id']),
        ]);
    }

    public static function attendance(): never
    {
        $user = Auth::requireRole('teacher');
        $class = self::classForTeacher($user, Request::query('class_id'));
        $term = Academics::currentTerm();
        $date = self::attendanceDate(Request::query('date'), $term);

        $register = Database::fetch(
            'SELECT marked_at FROM attendance_registers WHERE class_id = ? AND term_id = ? AND date = ? LIMIT 1',
            [(int) $class['id'], (int) $term['id'], $date]
        );

        Response::ok([
            'class' => Academics::presentClass($class),
            'term' => Academics::presentTerm($term),
            'date' => $date,
            'marked' => $register !== null,
            'marked_at' => $register['marked_at'] ?? null,
            'students' => Attendance::registerStudents((int) $class['id'], (int) $term['id'], $date),
        ]);
    }

    public static function markAttendance(): never
    {
        $user = Auth::requireRole('teacher');
        $data = Request::body();
        $class = self::classForTeacher($user, $data['class_id'] ?? null);
        $term = self::termFromValue($data['term_id'] ?? null);
        $date = self::attendanceDate($data['date'] ?? null, $term);

        if (!array_key_exists('records', $data) || !is_array($data['records'])) {
            throw HttpException::validation('Provide a complete attendance register.', [
                'records' => 'Records must be an array.',
            ]);
        }

        $result = Attendance::markRegister(
            (int) $class['id'],
            (int) $term['id'],
            $date,
            $data['records'],
            (int) $user['id']
        );

        Audit::record('attendance.marked', 'class', $class['uuid'], [
            'date' => $date,
            'students' => $result['marked'],
        ], (int) $user['id']);

        Response::ok([
            'class' => Academics::presentClass($class),
            'date' => $date,
            'marked' => $result['marked'],
        ]);
    }

    public static function resultSheets(): never
    {
        $user = Auth::requireRole('teacher');
        $term = self::termFromValue(Request::query('term_id'));

        Response::ok([
            'term' => Academics::presentTerm($term),
            'sheets' => Results::listForTeacher((int) $user['id'], (int) $term['id']),
        ]);
    }

    public static function createResultSheet(): never
    {
        $user = Auth::requireRole('teacher');
        $data = Request::body();
        $class = self::classForTeacher($user, $data['class_id'] ?? null);
        $subject = Academics::subjectByUuid(Validator::uuid($data['subject_id'] ?? null, 'subject_id'));
        $term = self::termFromValue($data['term_id'] ?? null);

        if ($user['role'] === 'teacher' && !Students::teacherCanManageSubject(
            (int) $user['id'],
            (int) $class['id'],
            (int) $subject['id']
        )) {
            throw HttpException::forbidden('You are not assigned to teach this subject in that class.');
        }

        $result = Results::createSheet(
            (int) $class['id'],
            (int) $subject['id'],
            (int) $term['id'],
            (int) $user['id']
        );

        $sheet = $result['sheet'];
        if ($user['role'] === 'teacher' && $sheet['teacher_id'] !== null && (int) $sheet['teacher_id'] !== (int) $user['id']) {
            throw HttpException::forbidden('That result sheet belongs to another teacher.');
        }

        if ($result['created']) {
            Audit::record('results.sheet_created', 'result_sheet', $sheet['uuid'], [], (int) $user['id']);
        }

        $full = Results::findSheetByUuid($sheet['uuid']);
        if ($full === null) {
            throw HttpException::server('The result sheet could not be loaded.');
        }

        Response::ok(Results::sheetDetail($full), $result['created'] ? 201 : 200);
    }

    public static function showResultSheet(array $params): never
    {
        $user = Auth::requireRole(['teacher', 'admin']);
        $sheet = self::viewableSheet($user, $params['id'] ?? null);

        Response::ok(Results::sheetDetail($sheet));
    }

    public static function saveResultSheet(array $params): never
    {
        $user = Auth::requireRole('teacher');
        $sheet = self::ownedSheet($user, $params['id'] ?? null);
        $data = Request::body();

        if (!array_key_exists('results', $data) || !is_array($data['results'])) {
            throw HttpException::validation('Provide result rows to save.', [
                'results' => 'Results must be an array.',
            ]);
        }

        $fresh = Results::saveSheet($sheet, $data['results'], (int) $user['id']);
        $full = Results::findSheetByUuid($fresh['uuid']);
        if ($full === null) {
            throw HttpException::server('The result sheet could not be reloaded.');
        }

        Audit::record('results.sheet_saved', 'result_sheet', $fresh['uuid'], [], (int) $user['id']);

        Response::ok(Results::sheetDetail($full));
    }

    public static function submitResultSheet(array $params): never
    {
        $user = Auth::requireRole('teacher');
        $sheet = self::ownedSheet($user, $params['id'] ?? null);
        $fresh = Results::submitSheet($sheet, (int) $user['id']);
        $full = Results::findSheetByUuid($fresh['uuid']);

        if ($full === null) {
            throw HttpException::server('The result sheet could not be reloaded.');
        }

        Audit::record('results.sheet_submitted', 'result_sheet', $fresh['uuid'], [], (int) $user['id']);

        Notifications::createForAudiences(
            ['admin'],
            'Result sheet submitted',
            $full['class_name'] . ' ' . $full['subject_name'] . ' is awaiting approval.',
            'result',
            'result_sheet',
            $fresh['uuid'],
            (int) $user['id']
        );

        Response::ok(Results::sheetDetail($full));
    }

    public static function assignments(): never
    {
        $user = Auth::requireRole('teacher');
        $class = self::classForTeacher($user, Request::query('class_id'));
        $term = self::termFromValue(Request::query('term_id'));

        Response::ok([
            'class' => Academics::presentClass($class),
            'term' => Academics::presentTerm($term),
            'assignments' => Assignments::forClass((int) $class['id'], (int) $term['id']),
        ]);
    }

    public static function createAssignment(): never
    {
        $user = Auth::requireRole('teacher');
        $data = Request::body();
        $class = self::classForTeacher($user, $data['class_id'] ?? null);
        $subject = Academics::subjectByUuid(Validator::uuid($data['subject_id'] ?? null, 'subject_id'));
        $term = self::termFromValue($data['term_id'] ?? null);

        if (!Students::teacherCanManageSubject((int) $user['id'], (int) $class['id'], (int) $subject['id'])) {
            throw HttpException::forbidden('You are not assigned to teach this subject in that class.');
        }

        $title = Validator::string($data['title'] ?? null, 'title', 3, 160);
        $description = Validator::string($data['description'] ?? null, 'description', 0, 4000, false);
        $dueOn = Validator::date($data['due_on'] ?? null, 'due_on');
        if ($dueOn < date('Y-m-d')) {
            throw HttpException::validation('The due date cannot be in the past.', [
                'due_on' => 'Choose today or a future date.',
            ]);
        }

        $assignment = Assignments::create(
            (int) $class['id'],
            (int) $subject['id'],
            (int) $term['id'],
            $title,
            $description ?? '',
            $dueOn,
            (int) $user['id']
        );

        Audit::record('assignment.created', 'assignment', $assignment['id'], [
            'class' => $class['name'],
            'subject' => $subject['name'],
        ], (int) $user['id']);

        Response::ok($assignment, 201);
    }

    public static function classStudents(): never
    {
        $user = Auth::requireRole('teacher');
        $class = self::classForTeacher($user, Request::query('class_id'));
        $term = self::termFromValue(Request::query('term_id'));
        $today = date('Y-m-d');

        $rows = Database::fetchAll(
            "SELECT s.uuid, s.first_name, s.last_name, s.admission_no, s.house,
                    ar.status AS attendance_today,
                    (SELECT ROUND(AVG(r.total), 1) FROM results r
                     WHERE r.student_id = s.id AND r.term_id = ?) AS average
             FROM students s
             LEFT JOIN attendance_records ar
                ON ar.student_id = s.id AND ar.date = ?
             WHERE s.class_id = ? AND s.status = 'active'
             ORDER BY s.last_name ASC, s.first_name ASC",
            [(int) $term['id'], $today, (int) $class['id']]
        );

        Response::ok([
            'class' => Academics::presentClass($class),
            'term' => Academics::presentTerm($term),
            'date' => $today,
            'students' => array_map(static fn (array $row): array => [
                'id' => $row['uuid'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'admission_no' => $row['admission_no'],
                'house' => $row['house'],
                'attendance_today' => $row['attendance_today'] !== null
                    ? ucfirst((string) $row['attendance_today'])
                    : null,
                'average' => $row['average'] !== null ? (float) $row['average'] : null,
            ], $rows),
        ]);
    }

    private static function classForTeacher(array $user, mixed $classUuid): array
    {
        $class = Academics::classByUuid(Validator::uuid($classUuid, 'class_id'));

        if ($user['role'] === 'teacher' && !Students::teacherCanManageClass((int) $user['id'], (int) $class['id'])) {
            throw HttpException::notFound('That class was not found or is not assigned to you.');
        }

        return $class;
    }

    private static function viewableSheet(array $user, mixed $uuid): array
    {
        $sheet = Results::findSheetByUuid(Validator::uuid($uuid, 'id'));
        if ($sheet === null) {
            throw HttpException::notFound('That result sheet was not found.');
        }

        if ($user['role'] === 'admin') {
            return $sheet;
        }

        if ((int) $sheet['teacher_id'] === (int) $user['id']) {
            return $sheet;
        }

        if (Students::teacherCanManageSubject((int) $user['id'], (int) $sheet['class_id'], (int) $sheet['subject_id'])) {
            return $sheet;
        }

        throw HttpException::notFound('That result sheet was not found or is not available to you.');
    }

    private static function ownedSheet(array $user, mixed $uuid): array
    {
        $sheet = self::viewableSheet($user, $uuid);
        if ($user['role'] !== 'admin' && (int) $sheet['teacher_id'] !== (int) $user['id']) {
            throw HttpException::forbidden('Only the subject teacher can edit this result sheet.');
        }

        return $sheet;
    }

    private static function termFromValue(mixed $value): array
    {
        $uuid = Validator::uuid($value, 'term_id', false);

        return $uuid !== null ? Academics::termByUuid($uuid) : Academics::currentTerm();
    }

    private static function dateFromRequest(mixed $value, bool $allowFuture = false): string
    {
        $date = Validator::date($value, 'date', false) ?? date('Y-m-d');
        if (!$allowFuture && $date > date('Y-m-d')) {
            throw HttpException::validation('Attendance cannot be recorded for a future date.', [
                'date' => 'Choose today or an earlier school day.',
            ]);
        }

        return $date;
    }

    private static function attendanceDate(mixed $value, ?array &$term = null): string
    {
        $term ??= Academics::currentTerm();
        $date = self::dateFromRequest($value);

        if ((int) date('N', strtotime($date)) > 5) {
            throw HttpException::validation('Attendance is only recorded on school days (Monday–Friday).', [
                'date' => 'Choose a weekday.',
            ]);
        }

        if ($date < $term['starts_on'] || $date > $term['ends_on']) {
            throw HttpException::validation('The date falls outside the current term.', [
                'date' => 'Choose a date within the current term.',
            ]);
        }

        return $date;
    }
}

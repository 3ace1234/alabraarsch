<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Audit;
use AlFalaah\Core\Auth;
use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Request;
use AlFalaah\Core\Response;
use AlFalaah\Core\Uuid;
use AlFalaah\Core\Validator;
use AlFalaah\Services\Academics;
use AlFalaah\Services\Announcements;
use AlFalaah\Services\Fees;
use AlFalaah\Services\Notifications;
use AlFalaah\Services\Results;
use AlFalaah\Services\Students;
use AlFalaah\Services\Timetable;

final class AdminController
{
    public static function overview(): never
    {
        $user = Auth::requireRole('admin');

        Response::ok(\AlFalaah\Services\Dashboard::forUser($user));
    }

    public static function createStudent(): never
    {
        $user = Auth::requireRole('admin');
        $data = Request::body();

        $firstName = Validator::string($data['first_name'] ?? null, 'first_name', 1, 80);
        $lastName = Validator::string($data['last_name'] ?? null, 'last_name', 1, 80);
        $class = Academics::classByUuid(Validator::uuid($data['class_id'] ?? null, 'class_id'));
        $admissionNo = Validator::string($data['admission_no'] ?? null, 'admission_no', 3, 30, false);
        if ($admissionNo === null) {
            $admissionNo = self::generateAdmissionNo();
        }

        $dateOfBirth = Validator::date($data['date_of_birth'] ?? null, 'date_of_birth', false);
        $gender = Validator::enum($data['gender'] ?? null, 'gender', ['male', 'female'], false);
        $house = Validator::string($data['house'] ?? null, 'house', 0, 60, false);
        $status = Validator::enum(
            $data['status'] ?? 'active',
            'status',
            ['active', 'graduated', 'withdrawn']
        );
        $admittedOn = Validator::date($data['admitted_on'] ?? null, 'admitted_on', false);

        $uuid = Uuid::v4();
        Database::insert(
            'INSERT INTO students
                (uuid, admission_no, first_name, last_name, date_of_birth, gender,
                 class_id, house, status, admitted_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $uuid,
                $admissionNo,
                $firstName,
                $lastName,
                $dateOfBirth,
                $gender,
                (int) $class['id'],
                $house,
                $status,
                $admittedOn,
            ]
        );

        Audit::record('student.created', 'student', $uuid, [
            'admission_no' => $admissionNo,
            'class' => $class['name'],
        ], (int) $user['id']);

        $student = Students::requireByUuid($uuid);
        Response::ok(Students::present($student), 201);
    }

    public static function updateStudent(array $params): never
    {
        $user = Auth::requireRole('admin');
        $student = Students::requireByUuid(Validator::uuid($params['id'] ?? null, 'id'));
        $data = Request::body();

        $fields = [];
        $values = [];

        if (array_key_exists('first_name', $data)) {
            $fields[] = 'first_name = ?';
            $values[] = Validator::string($data['first_name'], 'first_name', 1, 80);
        }
        if (array_key_exists('last_name', $data)) {
            $fields[] = 'last_name = ?';
            $values[] = Validator::string($data['last_name'], 'last_name', 1, 80);
        }
        if (array_key_exists('admission_no', $data)) {
            $fields[] = 'admission_no = ?';
            $values[] = Validator::string($data['admission_no'], 'admission_no', 3, 30);
        }
        if (array_key_exists('class_id', $data)) {
            $class = Academics::classByUuid(Validator::uuid($data['class_id'], 'class_id'));
            $fields[] = 'class_id = ?';
            $values[] = (int) $class['id'];
        }
        if (array_key_exists('date_of_birth', $data)) {
            $fields[] = 'date_of_birth = ?';
            $values[] = Validator::date($data['date_of_birth'], 'date_of_birth', false);
        }
        if (array_key_exists('gender', $data)) {
            $fields[] = 'gender = ?';
            $values[] = Validator::enum($data['gender'], 'gender', ['male', 'female'], false);
        }
        if (array_key_exists('house', $data)) {
            $fields[] = 'house = ?';
            $values[] = Validator::string($data['house'], 'house', 0, 60, false);
        }
        if (array_key_exists('status', $data)) {
            $fields[] = 'status = ?';
            $values[] = Validator::enum($data['status'], 'status', ['active', 'graduated', 'withdrawn']);
        }
        if (array_key_exists('admitted_on', $data)) {
            $fields[] = 'admitted_on = ?';
            $values[] = Validator::date($data['admitted_on'], 'admitted_on', false);
        }

        if ($fields === []) {
            throw HttpException::validation('There is nothing to update.', [
                'student' => 'No supported fields were supplied.',
            ]);
        }

        $values[] = (int) $student['id'];
        Database::execute('UPDATE students SET ' . implode(', ', $fields) . ' WHERE id = ?', $values);

        Audit::record('student.updated', 'student', $student['uuid'], [
            'fields' => array_map(
                static fn (string $field): string => explode(' ', $field)[0],
                $fields
            ),
        ], (int) $user['id']);

        $fresh = Students::requireByUuid($student['uuid']);
        Response::ok(Students::present($fresh));
    }

    public static function announcements(): never
    {
        Auth::requireRole('admin');
        $status = Validator::enum(
            Request::query('status'),
            'status',
            ['draft', 'published', 'archived'],
            false
        );
        $limit = (int) (Request::queryScalar('limit') ?? 50);

        Response::ok(Announcements::listForAdmin($status, $limit));
    }

    public static function createAnnouncement(): never
    {
        $user = Auth::requireRole('admin');
        $announcement = Announcements::create(Request::body(), (int) $user['id']);

        Audit::record('announcement.created', 'announcement', $announcement['id'], [
            'status' => $announcement['status'] ?? 'draft',
        ], (int) $user['id']);

        Response::ok($announcement, 201);
    }

    public static function updateAnnouncement(array $params): never
    {
        $user = Auth::requireRole('admin');
        $uuid = Validator::uuid($params['id'] ?? null, 'id');
        $announcement = Announcements::update($uuid, Request::body(), (int) $user['id']);

        Audit::record('announcement.updated', 'announcement', $uuid, [], (int) $user['id']);

        Response::ok($announcement);
    }

    public static function archiveAnnouncement(array $params): never
    {
        $user = Auth::requireRole('admin');
        $uuid = Validator::uuid($params['id'] ?? null, 'id');
        Announcements::archive($uuid);

        Audit::record('announcement.archived', 'announcement', $uuid, [], (int) $user['id']);

        Response::noContent();
    }

    public static function resultSheets(): never
    {
        Auth::requireRole('admin');
        $term = self::termFromValue(Request::query('term_id'));
        $status = Validator::enum(
            Request::query('status'),
            'status',
            ['draft', 'submitted', 'approved', 'published'],
            false
        );

        Response::ok([
            'term' => Academics::presentTerm($term),
            'sheets' => Results::listForAdmin((int) $term['id'], $status),
        ]);
    }

    public static function approveResultSheet(array $params): never
    {
        $user = Auth::requireRole('admin');
        $sheet = self::sheet(Validator::uuid($params['id'] ?? null, 'id'));
        $note = Validator::string(Request::input('note'), 'note', 0, 500, false);
        $fresh = Results::approveSheet($sheet, (int) $user['id'], $note);

        Audit::record('results.sheet_approved', 'result_sheet', $fresh['uuid'], [], (int) $user['id']);
        self::notifyTeacher($sheet, 'Result sheet approved', $sheet['class_name'] . ' ' . $sheet['subject_name'] . ' has been approved.', $user);

        $full = Results::findSheetByUuid($fresh['uuid']);
        if ($full === null) {
            throw HttpException::server('The result sheet could not be reloaded.');
        }

        Response::ok(Results::sheetDetail($full));
    }

    public static function returnResultSheet(array $params): never
    {
        $user = Auth::requireRole('admin');
        $sheet = self::sheet(Validator::uuid($params['id'] ?? null, 'id'));
        $note = Validator::string(Request::input('note'), 'note', 3, 500);
        $fresh = Results::returnSheet($sheet, $note);

        Audit::record('results.sheet_returned', 'result_sheet', $fresh['uuid'], ['note' => $note], (int) $user['id']);
        self::notifyTeacher($sheet, 'Result sheet returned for correction', $note, $user);

        $full = Results::findSheetByUuid($fresh['uuid']);
        if ($full === null) {
            throw HttpException::server('The result sheet could not be reloaded.');
        }

        Response::ok(Results::sheetDetail($full));
    }

    public static function publishTerm(array $params): never
    {
        $user = Auth::requireRole('admin');
        $term = Academics::termByUuid(Validator::uuid($params['id'] ?? null, 'id'));
        $result = Results::publishTerm((int) $term['id'], (int) $user['id']);

        Audit::record('results.term_published', 'term', $term['uuid'], [
            'sheets' => $result['published_sheets'],
        ], (int) $user['id']);

        Notifications::createForAudiences(
            ['student', 'parent'],
            'Results are ready',
            'Your ' . $term['name'] . ' results for ' . $term['session_name'] . ' have been published.',
            'result',
            'term',
            $term['uuid'],
            (int) $user['id']
        );

        $fresh = Academics::termByUuid($term['uuid']);
        Response::ok([
            'term' => Academics::presentTerm($fresh),
            'published_sheets' => $result['published_sheets'],
        ]);
    }

    public static function admissions(): never
    {
        Auth::requireRole('admin');
        [$page, $perPage] = Validator::pagination(
            self::scalarQuery('page'),
            self::scalarQuery('per_page')
        );
        $status = Validator::enum(
            Request::query('status'),
            'status',
            ['new', 'assessment', 'offer', 'enrolled', 'declined'],
            false
        );
        $level = Validator::enum(
            Request::query('level'),
            'level',
            ['nursery', 'primary', 'junior_secondary', 'senior_secondary'],
            false
        );
        $search = Validator::string(Request::query('search'), 'search', 0, 100, false) ?? '';

        $where = ['1 = 1'];
        $params = [];
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($level !== null) {
            $where[] = 'level = ?';
            $params[] = $level;
        }
        if ($search !== '') {
            $where[] = '(applicant_name LIKE ? OR guardian_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM admission_applications WHERE ' . $whereSql, $params);
        $offset = ($page - 1) * $perPage;
        $rows = Database::fetchAll(
            "SELECT * FROM admission_applications WHERE {$whereSql}
             ORDER BY FIELD(status, 'new','assessment','offer','enrolled','declined'), created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        Response::ok(array_map(
            static fn (array $row): array => self::presentAdmission($row),
            $rows
        ), 200, [
            'pagination' => Database::paginate($total, $page, $perPage),
        ]);
    }

    public static function updateAdmission(array $params): never
    {
        $user = Auth::requireRole('admin');
        $uuid = Validator::uuid($params['id'] ?? null, 'id');
        $admission = Database::fetch('SELECT * FROM admission_applications WHERE uuid = ? LIMIT 1', [$uuid]);
        if ($admission === null) {
            throw HttpException::notFound('That admission enquiry was not found.');
        }

        $data = Request::body();
        $fields = [];
        $values = [];

        if (array_key_exists('status', $data)) {
            $fields[] = 'status = ?';
            $values[] = Validator::enum($data['status'], 'status', [
                'new', 'assessment', 'offer', 'enrolled', 'declined',
            ]);
        }
        if (array_key_exists('notes', $data)) {
            $fields[] = 'notes = ?';
            $values[] = Validator::string($data['notes'], 'notes', 0, 4000, false);
        }

        if ($fields === []) {
            throw HttpException::validation('There is nothing to update.', [
                'admission' => 'No supported fields were supplied.',
            ]);
        }

        $values[] = (int) $admission['id'];
        Database::execute('UPDATE admission_applications SET ' . implode(', ', $fields) . ' WHERE id = ?', $values);

        Audit::record('admission.updated', 'admission', $uuid, [], (int) $user['id']);

        $fresh = Database::fetch('SELECT * FROM admission_applications WHERE id = ?', [(int) $admission['id']]);
        if ($fresh === null) {
            throw HttpException::server('The admission enquiry could not be reloaded.');
        }

        Response::ok(self::presentAdmission($fresh));
    }

    public static function finance(): never
    {
        Auth::requireRole('admin');
        $term = self::termFromValue(Request::query('term_id'));

        $rows = Database::fetchAll(
            "SELECT fi.uuid, fi.reference, fi.description, fi.amount, fi.status, fi.issued_on, fi.due_on,
                    s.uuid AS student_uuid, s.first_name, s.last_name, s.admission_no,
                    c.name AS class_name,
                    COALESCE((SELECT SUM(fp.amount) FROM fee_payments fp WHERE fp.invoice_id = fi.id), 0) AS paid
             FROM fee_invoices fi
             INNER JOIN students s ON s.id = fi.student_id
             INNER JOIN classes c ON c.id = s.class_id
             WHERE fi.term_id = ?
             ORDER BY fi.issued_on DESC, fi.id DESC
             LIMIT 200",
            [(int) $term['id']]
        );

        $payments = Database::fetchAll(
            "SELECT fp.uuid, fp.reference, fp.amount, fp.method, fp.paid_on,
                    s.uuid AS student_uuid, s.first_name, s.last_name
             FROM fee_payments fp
             INNER JOIN students s ON s.id = fp.student_id
             WHERE fp.term_id = ?
             ORDER BY fp.paid_on DESC, fp.id DESC
             LIMIT 25",
            [(int) $term['id']]
        );

        Response::ok([
            'term' => Academics::presentTerm($term),
            'summary' => Fees::schoolSummary((int) $term['id']),
            'invoices' => array_map(static function (array $row): array {
                $amount = (float) $row['amount'];
                $paid = (float) $row['paid'];

                return [
                    'id' => $row['uuid'],
                    'ref' => $row['reference'],
                    'item' => $row['description'],
                    'student' => [
                        'id' => $row['student_uuid'],
                        'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                        'admission_no' => $row['admission_no'],
                        'class' => $row['class_name'],
                    ],
                    'amount' => round($amount, 2),
                    'paid' => round($paid, 2),
                    'balance' => round(max(0, $amount - $paid), 2),
                    'status' => $row['status'],
                    'status_label' => Fees::statusLabel($row['status']),
                    'issued_on' => $row['issued_on'],
                    'due_on' => $row['due_on'],
                ];
            }, $rows),
            'recent_payments' => array_map(static fn (array $row): array => [
                'id' => $row['uuid'],
                'ref' => $row['reference'],
                'student' => [
                    'id' => $row['student_uuid'],
                    'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                ],
                'amount' => (float) $row['amount'],
                'method' => $row['method'],
                'paid_on' => $row['paid_on'],
            ], $payments),
        ]);
    }

    public static function recordPayment(): never
    {
        $user = Auth::requireRole('admin');
        $data = Request::body();

        $invoiceId = Validator::uuid($data['invoice_id'] ?? null, 'invoice_id');
        $amount = Validator::number($data['amount'] ?? null, 'amount', 0.01, 10_000_000);
        $method = Validator::string($data['method'] ?? null, 'method', 2, 40);
        $paidOn = Validator::date($data['paid_on'] ?? date('Y-m-d'), 'paid_on');
        $reference = Validator::string($data['reference'] ?? null, 'reference', 0, 40, false);
        $note = Validator::string($data['note'] ?? null, 'note', 0, 190, false);

        if ($paidOn > date('Y-m-d')) {
            throw HttpException::validation('The payment date cannot be in the future.', [
                'paid_on' => 'Choose today or an earlier date.',
            ]);
        }

        $payment = Fees::recordPayment(
            $invoiceId,
            (float) $amount,
            $method,
            $paidOn,
            (int) $user['id'],
            $reference,
            $note
        );

        Audit::record('fees.payment_recorded', 'fee_payment', $payment['id'], [
            'invoice' => $invoiceId,
            'amount' => $payment['amount'],
        ], (int) $user['id']);

        Response::ok($payment, 201);
    }

    public static function createInvoice(): never
    {
        $user = Auth::requireRole('admin');
        $data = Request::body();

        $student = Students::requireByUuid(Validator::uuid($data['student_id'] ?? null, 'student_id'));
        $term = self::termFromValue($data['term_id'] ?? null);
        $description = Validator::string($data['description'] ?? null, 'description', 2, 160);
        $amount = Validator::number($data['amount'] ?? null, 'amount', 0.01, 10_000_000);
        $issuedOn = Validator::date($data['issued_on'] ?? date('Y-m-d'), 'issued_on');
        $dueOn = Validator::date($data['due_on'] ?? null, 'due_on');

        if ($dueOn < $issuedOn) {
            throw HttpException::validation('The due date cannot be before the issue date.', [
                'due_on' => 'Choose a date on or after the issue date.',
            ]);
        }

        $invoice = Fees::createInvoice(
            (int) $student['id'],
            (int) $term['id'],
            $description,
            (float) $amount,
            $issuedOn,
            $dueOn
        );

        Audit::record('fees.invoice_created', 'fee_invoice', $invoice['id'], [
            'student' => $student['uuid'],
            'amount' => $invoice['amount'],
        ], (int) $user['id']);

        Response::ok($invoice, 201);
    }

    public static function timetable(): never
    {
        Auth::requireRole('admin');
        $class = Academics::classByUuid(Validator::uuid(Request::query('class_id'), 'class_id'));
        $term = self::termFromValue(Request::query('term_id'));

        Response::ok([
            'class' => Academics::presentClass($class),
            'term' => Academics::presentTerm($term),
            'timetable' => Timetable::forClass((int) $class['id'], (int) $term['id']),
        ]);
    }

    public static function createTimetableSlot(): never
    {
        $user = Auth::requireRole('admin');
        $data = Request::body();
        $class = Academics::classByUuid(Validator::uuid($data['class_id'] ?? null, 'class_id'));
        $subject = Academics::subjectByUuid(Validator::uuid($data['subject_id'] ?? null, 'subject_id'));
        $term = self::termFromValue($data['term_id'] ?? null);

        $teacherId = null;
        $teacherUuid = Validator::uuid($data['teacher_id'] ?? null, 'teacher_id', false);
        if ($teacherUuid !== null) {
            $teacher = Database::fetch(
                "SELECT id, uuid FROM users WHERE uuid = ? AND role IN ('teacher','admin') AND status = 'active' LIMIT 1",
                [$teacherUuid]
            );
            if ($teacher === null) {
                throw HttpException::validation('Choose an active teacher.', [
                    'teacher_id' => 'That teacher was not found.',
                ]);
            }
            $teacherId = (int) $teacher['id'];
        }

        $day = Validator::int($data['day_of_week'] ?? null, 'day_of_week', 1, 5);
        $period = Validator::int($data['period_no'] ?? null, 'period_no', 1, 6);
        $room = Validator::string($data['room'] ?? null, 'room', 1, 60);

        $clash = Database::fetchValue(
            'SELECT 1 FROM timetable_slots
             WHERE term_id = ? AND class_id = ? AND day_of_week = ? AND period_no = ?
             LIMIT 1',
            [(int) $term['id'], (int) $class['id'], (int) $day, (int) $period]
        );
        if ($clash !== null) {
            throw HttpException::conflict('That class already has a lesson in this period.');
        }

        if ($teacherId !== null) {
            $teacherClash = Database::fetchValue(
                'SELECT 1 FROM timetable_slots
                 WHERE term_id = ? AND teacher_id = ? AND day_of_week = ? AND period_no = ?
                 LIMIT 1',
                [(int) $term['id'], $teacherId, (int) $day, (int) $period]
            );
            if ($teacherClash !== null) {
                throw HttpException::conflict('That teacher already has a lesson in this period.');
            }
        }

        $slot = Timetable::createSlot(
            (int) $class['id'],
            (int) $subject['id'],
            $teacherId,
            (int) $term['id'],
            (int) $day,
            (int) $period,
            $room
        );

        Audit::record('timetable.slot_created', 'timetable_slot', $slot['id'], [
            'class' => $class['name'],
            'subject' => $subject['name'],
        ], (int) $user['id']);

        Response::ok($slot, 201);
    }

    public static function deleteTimetableSlot(array $params): never
    {
        $user = Auth::requireRole('admin');
        $uuid = Validator::uuid($params['id'] ?? null, 'id');
        Timetable::deleteSlot($uuid);

        Audit::record('timetable.slot_deleted', 'timetable_slot', $uuid, [], (int) $user['id']);

        Response::noContent();
    }

    private static function sheet(string $uuid): array
    {
        $sheet = Results::findSheetByUuid($uuid);
        if ($sheet === null) {
            throw HttpException::notFound('That result sheet was not found.');
        }

        return $sheet;
    }

    private static function notifyTeacher(array $sheet, string $title, string $body, array $admin): void
    {
        if ($sheet['teacher_id'] === null) {
            return;
        }

        Notifications::createForUser(
            (int) $sheet['teacher_id'],
            $title,
            $body,
            'result',
            'result_sheet',
            $sheet['uuid']
        );
    }

    private static function termFromValue(mixed $value): array
    {
        $uuid = Validator::uuid($value, 'term_id', false);

        return $uuid !== null ? Academics::termByUuid($uuid) : Academics::currentTerm();
    }

    private static function scalarQuery(string $key): ?string
    {
        $value = Request::query($key);

        return is_scalar($value) ? (string) $value : null;
    }

    private static function generateAdmissionNo(): string
    {
        $year = date('Y');
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $next = (int) Database::fetchValue(
                "SELECT COUNT(*) FROM students WHERE admission_no LIKE ?",
                ["AF/{$year}/%"]
            ) + $attempt;
            $admissionNo = sprintf('AF/%s/%04d', $year, $next);
            if (!Database::fetchValue('SELECT 1 FROM students WHERE admission_no = ? LIMIT 1', [$admissionNo])) {
                return $admissionNo;
            }
        }

        throw HttpException::server('An admission number could not be generated.');
    }

    private static function presentAdmission(array $row): array
    {
        return [
            'id' => $row['uuid'],
            'applicant' => $row['applicant_name'],
            'guardian' => $row['guardian_name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'level' => $row['level'],
            'level_label' => Academics::levelLabel($row['level']),
            'status' => $row['status'],
            'status_label' => ucfirst($row['status']),
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}

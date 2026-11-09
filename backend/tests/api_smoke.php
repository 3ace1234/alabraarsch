<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for the Al-Falaah JSON API.
 *
 * Usage: php backend/tests/api_smoke.php
 * Requires the API to be served by Apache/XAMPP and demo data to be installed.
 * It creates only temporary records and removes them before finishing.
 */

use AlFalaah\Core\Database;
use AlFalaah\Core\Env;
use AlFalaah\Core\Uuid;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only be run from the command line.\n");
}

if (!extension_loaded('curl')) {
    fwrite(STDERR, "The PHP curl extension is required.\n");
    exit(1);
}

final class ApiClient
{
    private string $cookieFile;
    public ?string $csrf = null;
    public ?array $user = null;

    public function __construct(private readonly string $base)
    {
        $path = tempnam(sys_get_temp_dir(), 'alfalaah_smoke_');
        if ($path === false) {
            throw new RuntimeException('Could not create a cookie jar.');
        }
        $this->cookieFile = $path;
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    public function request(string $method, string $path, array|object|null $body = null, bool $csrf = true): array
    {
        $handle = curl_init($this->base . $path);
        if ($handle === false) {
            throw new RuntimeException('Could not create a curl handle.');
        }

        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($csrf && $this->csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $decoded = $raw === '' ? null : json_decode($raw, true);
        if ($status === 204 && $raw === '') {
            $decoded = [];
        }
        if (!is_array($decoded)) {
            throw new RuntimeException("{$method} {$path} returned invalid JSON (HTTP {$status}).");
        }

        if (isset($decoded['data']['csrf_token']) && is_string($decoded['data']['csrf_token'])) {
            $this->csrf = $decoded['data']['csrf_token'];
        }
        if (isset($decoded['data']['user']) && is_array($decoded['data']['user'])) {
            $this->user = $decoded['data']['user'];
        }

        return ['status' => $status, 'json' => $decoded];
    }

    public function get(string $path, bool $csrf = true): array
    {
        return $this->request('GET', $path, null, $csrf);
    }

    public function post(string $path, array|object|null $body = null, bool $csrf = true): array
    {
        return $this->request('POST', $path, $body, $csrf);
    }

    public function patch(string $path, array|object|null $body = null, bool $csrf = true): array
    {
        return $this->request('PATCH', $path, $body, $csrf);
    }
}

function baseUrl(): string
{
    $configured = Env::get('API_SMOKE_BASE_URL', '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    return rtrim(Env::get('APP_URL', 'http://localhost/alabraarsch/backend/public'), '/') . '/api';
}

function check(bool $condition, string $label, ?string $detail = null): void
{
    if ($condition) {
        echo "  ok   {$label}\n";
        return;
    }

    echo "  FAIL {$label}" . ($detail !== null ? " — {$detail}" : '') . "\n";
    throw new RuntimeException('Smoke test failed: ' . $label);
}

function expects(array $response, int $status, string $label): array
{
    check($response['status'] === $status, $label, 'HTTP ' . $response['status']);
    return $response['json'];
}

function signIn(string $base, string $email, string $password, string $role): ApiClient
{
    $client = new ApiClient($base);
    expects($client->get('/session'), 200, "{$role}: session bootstrap");
    check($client->csrf !== null, "{$role}: CSRF token issued");

    $body = expects($client->post('/auth/login', [
        'email' => $email,
        'password' => $password,
    ]), 200, "{$role}: login");
    check(($body['data']['user']['role'] ?? null) === $role, "{$role}: role returned");
    check(is_string($body['data']['csrf_token'] ?? null), "{$role}: CSRF token rotated");

    return $client;
}

$base = baseUrl();
$password = Env::get('DEMO_PASSWORD', '');

if ($password === '') {
    fwrite(STDERR, "DEMO_PASSWORD is not configured; cannot sign in to demo accounts.\n");
    exit(1);
}

echo "Al-Falaah API smoke test\n";
echo "Base: {$base}\n\n";

try {
    $anonymous = new ApiClient($base);
    expects($anonymous->get('/health', false), 200, 'public: health');
    expects($anonymous->get('/dashboard', false), 401, 'anonymous: dashboard is protected');
    expects($anonymous->get('/does-not-exist', false), 404, 'anonymous: unknown route');
    expects($anonymous->get('/session'), 200, 'anonymous: session bootstrap');
    expects($anonymous->post('/auth/login', (object) []), 422, 'anonymous: empty JSON object is accepted');
    expects($anonymous->post('/auth/login', []), 400, 'anonymous: JSON list is rejected');
    expects($anonymous->post('/admissions', (object) [], false), 403, 'anonymous: admission requires CSRF');
    expects($anonymous->post('/admissions', (object) []), 422, 'anonymous: incomplete admission rejected');
    expects($anonymous->post('/contact', (object) []), 422, 'anonymous: incomplete contact message rejected');
    expects($anonymous->post('/newsletter', (object) [], false), 403, 'anonymous: newsletter requires CSRF');
    expects($anonymous->post('/newsletter', (object) []), 422, 'anonymous: newsletter requires an email');
    expects($anonymous->post('/newsletter', ['email' => 'not-an-email']), 422, 'anonymous: newsletter rejects an invalid email');
    $newsletterEmail = 'smoke-newsletter@example.com';
    $subscription = expects(
        $anonymous->post('/newsletter', ['email' => $newsletterEmail]),
        201,
        'anonymous: newsletter subscription accepted'
    );
    check(($subscription['data']['subscribed'] ?? false) === true, 'anonymous: newsletter subscription flag');

    $student = signIn($base, 'aisha.abdullahi@student.alfalaah.edu.ng', $password, 'student');
    $dashboard = expects($student->get('/dashboard'), 200, 'student: dashboard');
    check(($dashboard['data']['role'] ?? null) === 'student', 'student: dashboard role');
    check(($dashboard['data']['stats']['class_size'] ?? 0) > 0, 'student: class size present');

    $studentId = $dashboard['data']['student']['id'] ?? null;
    check(is_string($studentId), 'student: linked student record');

    foreach ([
        '/students/' . $studentId . '/results',
        '/students/' . $studentId . '/attendance',
        '/students/' . $studentId . '/fees',
        '/students/' . $studentId . '/timetable',
        '/students/' . $studentId . '/assignments',
        '/students/' . $studentId . '/report',
    ] as $path) {
        expects($student->get($path), 200, 'student: ' . $path);
    }

    expects($student->get('/students'), 403, 'student: cannot list all students');
    expects($student->get('/admin/overview'), 403, 'student: cannot open admin overview');
    expects($student->post('/notifications/read-all', null, false), 403, 'student: CSRF is required');

    $parent = signIn($base, 'ibrahim.abdullahi@example.com', $password, 'parent');
    $parentDashboard = expects($parent->get('/dashboard'), 200, 'parent: dashboard');
    check(count($parentDashboard['data']['children'] ?? []) >= 2, 'parent: children loaded');
    $children = expects($parent->get('/parent/children'), 200, 'parent: children endpoint');
    check(count($children['data']) >= 2, 'parent: child list populated');

    $otherChild = null;
    foreach ($children['data'] as $child) {
        if (($child['id'] ?? null) !== $studentId) {
            $otherChild = $child['id'];
            break;
        }
    }
    check(is_string($otherChild), 'parent: second child available');
    expects($student->get('/students/' . $otherChild), 404, 'student: cannot read a sibling record');
    expects($parent->get('/students/' . $otherChild . '/report'), 200, 'parent: can read second child report');
    expects($parent->get('/admin/overview'), 403, 'parent: cannot open admin overview');

    $teacher = signIn($base, 's.bello@alfalaah.edu.ng', $password, 'teacher');
    $teacherDashboard = expects($teacher->get('/dashboard'), 200, 'teacher: dashboard');
    check(($teacherDashboard['data']['stats']['classes'] ?? 0) > 0, 'teacher: assigned classes');
    $teacherClasses = expects($teacher->get('/teacher/classes'), 200, 'teacher: classes endpoint');
    check(($teacherClasses['data'][0]['id'] ?? null) !== null, 'teacher: class identifier present');
    $classId = $teacherClasses['data'][0]['id'];
    expects($teacher->get('/teacher/schedule'), 200, 'teacher: schedule');
    expects($teacher->get('/teacher/result-sheets'), 200, 'teacher: result sheets');

    $teacherTerms = expects($teacher->get('/terms'), 200, 'teacher: term list');
    $attendanceTerm = null;
    foreach ($teacherTerms['data'] ?? [] as $term) {
        if (!empty($term['is_current'])) {
            $attendanceTerm = $term;
            break;
        }
    }

    // Attendance is weekday-only and must fall inside the current term, so a
    // plain "today" request fails when the suite runs at a weekend. Walk back
    // to the most recent school day within the term instead.
    $attendanceDate = date('Y-m-d');
    for ($i = 0; $i < 14; $i++) {
        $isWeekday = (int) date('N', strtotime($attendanceDate)) <= 5;
        $inTerm = $attendanceTerm === null
            || ($attendanceDate >= $attendanceTerm['starts_on'] && $attendanceDate <= $attendanceTerm['ends_on']);
        if ($isWeekday && $inTerm) {
            break;
        }
        $attendanceDate = date('Y-m-d', strtotime($attendanceDate . ' -1 day'));
    }
    expects(
        $teacher->get('/teacher/attendance?class_id=' . $classId . '&date=' . $attendanceDate),
        200,
        'teacher: attendance register'
    );
    expects($teacher->get('/teacher/assignments?class_id=' . $classId), 200, 'teacher: assignments');
    expects($teacher->get('/teacher/students?class_id=' . $classId), 200, 'teacher: class students');
    expects($teacher->get('/admin/finance'), 403, 'teacher: cannot view finance');
    expects($teacher->get('/admin/overview'), 403, 'teacher: cannot open admin overview');

    $admin = signIn($base, 'h.sanni@alfalaah.edu.ng', $password, 'admin');
    $adminDashboard = expects($admin->get('/admin/overview'), 200, 'admin: overview');
    check(($adminDashboard['data']['role'] ?? null) === 'admin', 'admin: overview role');
    expects($admin->get('/admin/students?per_page=3'), 200, 'admin: student list');
    expects($admin->get('/admin/announcements'), 200, 'admin: announcement list');
    expects($admin->get('/admin/result-sheets'), 200, 'admin: result sheets');
    expects($admin->get('/admin/admissions?per_page=3'), 200, 'admin: admissions');
    expects($admin->get('/admin/finance'), 200, 'admin: finance');
    expects($admin->get('/admin/messages?per_page=3'), 200, 'admin: message inbox');
    $subscribers = expects($admin->get('/admin/subscribers?per_page=100'), 200, 'admin: newsletter subscriber list');
    expects($student->get('/admin/subscribers'), 403, 'student: cannot view newsletter subscribers');

    $subscriberId = null;
    foreach ($subscribers['data'] ?? [] as $subscriber) {
        if (($subscriber['email'] ?? null) === $newsletterEmail) {
            $subscriberId = $subscriber['id'] ?? null;
            break;
        }
    }
    check(is_string($subscriberId), 'admin: newsletter subscriber stored');

    if (is_string($subscriberId)) {
        $updated = expects(
            $admin->patch('/admin/subscribers/' . rawurlencode($subscriberId), ['status' => 'unsubscribed']),
            200,
            'admin: newsletter subscriber update'
        );
        check(($updated['data']['status'] ?? null) === 'unsubscribed', 'admin: newsletter subscriber status changed');
        expects(
            $admin->patch('/admin/subscribers/' . rawurlencode($subscriberId), ['status' => 'bogus']),
            422,
            'admin: invalid subscriber status rejected'
        );
        Database::execute('DELETE FROM newsletter_subscribers WHERE email = ?', [$newsletterEmail]);
    }
    expects($admin->get('/admin/teachers'), 200, 'admin: teacher list');
    $terms = expects($admin->get('/terms'), 200, 'admin: term list');
    $classes = expects($admin->get('/classes'), 200, 'admin: class list');
    expects($admin->get('/subjects'), 200, 'admin: subject list');

    // Admission-to-student workflow: use throwaway applications so the seeded
    // pipeline is untouched, then remove the created student afterwards.
    $enrollClass = $classes['data'][0] ?? null;
    foreach ($classes['data'] ?? [] as $classroom) {
        if (($classroom['level'] ?? null) === 'primary') {
            $enrollClass = $classroom;
            break;
        }
    }
    check($enrollClass !== null && isset($enrollClass['id']), 'admin: class available for enrollment');

    $tempAdmissionUuid = Uuid::v4();
    $tempAdmissionId = Database::insert(
        "INSERT INTO admission_applications
            (uuid, applicant_name, guardian_name, email, phone, level, status, notes)
         VALUES (?, ?, ?, ?, ?, 'primary', 'offer', ?)",
        [
            $tempAdmissionUuid,
            'Smoke Test Applicant',
            'Smoke Test Guardian',
            'smoke-admission@example.com',
            '+234 800 000 0000',
            'Temporary record created by the API smoke test.',
        ]
    );

    expects(
        $student->post(
            '/admin/admissions/' . rawurlencode($tempAdmissionUuid) . '/enroll',
            ['class_id' => $enrollClass['id']]
        ),
        403,
        'student: cannot enroll an admission'
    );

    $enrolled = expects(
        $admin->post(
            '/admin/admissions/' . rawurlencode($tempAdmissionUuid) . '/enroll',
            ['class_id' => $enrollClass['id']]
        ),
        201,
        'admin: admission enrolled as student'
    );
    check(($enrolled['data']['status'] ?? null) === 'enrolled', 'admin: enrollment updates admission status');
    $enrolledStudentId = $enrolled['data']['student']['id'] ?? null;
    check(is_string($enrolledStudentId), 'admin: enrollment links the student record');
    $portalAccount = $enrolled['data']['portal_account'] ?? null;
    check(
        is_array($portalAccount) && !empty($portalAccount['email']),
        'admin: enrollment creates a portal account'
    );
    check(
        is_array($portalAccount) && !empty($portalAccount['temporary_password']),
        'admin: temporary password returned once'
    );

    expects(
        $admin->post(
            '/admin/admissions/' . rawurlencode($tempAdmissionUuid) . '/enroll',
            ['class_id' => $enrollClass['id']]
        ),
        409,
        'admin: duplicate enrollment rejected'
    );
    expects(
        $admin->patch('/admin/admissions/' . rawurlencode($tempAdmissionUuid), ['status' => 'enrolled']),
        422,
        'admin: direct enrolled status rejected'
    );

    $register = expects($admin->get('/admin/students?per_page=100'), 200, 'admin: refreshed student register');
    $listed = false;
    foreach ($register['data'] ?? [] as $studentRow) {
        if (($studentRow['id'] ?? null) === $enrolledStudentId) {
            $listed = true;
            break;
        }
    }
    check($listed, 'admin: enrolled student appears in the register');

    if (is_array($portalAccount) && !empty($portalAccount['email'])) {
        $newStudent = signIn(
            $base,
            (string) $portalAccount['email'],
            (string) $portalAccount['temporary_password'],
            'student'
        );
        expects($newStudent->get('/dashboard'), 200, 'new student: can open the dashboard');
        $changed = expects($newStudent->post('/auth/password', [
            'current_password' => (string) $portalAccount['temporary_password'],
            'password' => 'SmokeStudent@2026',
            'password_confirmation' => 'SmokeStudent@2026',
        ]), 200, 'new student: changes the temporary password');
        check(
            is_string($changed['data']['csrf_token'] ?? null),
            'new student: CSRF token rotated after password change'
        );
        expects($newStudent->post('/auth/logout'), 204, 'new student: logout');
        $returning = signIn($base, (string) $portalAccount['email'], 'SmokeStudent@2026', 'student');
        expects($returning->post('/auth/logout'), 204, 'new student: signs in with the new password');
    }

    $validationUuid = Uuid::v4();
    $validationId = Database::insert(
        "INSERT INTO admission_applications
            (uuid, applicant_name, guardian_name, email, phone, level, status, notes)
         VALUES (?, ?, ?, ?, ?, 'primary', 'offer', ?)",
        [
            $validationUuid,
            'Smoke Validation Applicant',
            'Smoke Validation Guardian',
            'smoke-admission-validation@example.com',
            '+234 800 000 0001',
            'Temporary record created by the API smoke test.',
        ]
    );
    expects(
        $admin->post('/admin/admissions/' . rawurlencode($validationUuid) . '/enroll', (object) []),
        422,
        'admin: enrollment requires a class'
    );

    Database::execute('DELETE FROM admission_applications WHERE id = ?', [$validationId]);
    Database::execute('DELETE FROM admission_applications WHERE id = ?', [$tempAdmissionId]);
    if (is_string($enrolledStudentId)) {
        Database::execute('DELETE FROM students WHERE uuid = ?', [$enrolledStudentId]);
    }
    if (is_array($portalAccount) && !empty($portalAccount['email'])) {
        Database::execute('DELETE FROM login_attempts WHERE email = ?', [$portalAccount['email']]);
        Database::execute('DELETE FROM users WHERE email = ?', [$portalAccount['email']]);
    }

    $currentTerm = null;
    foreach ($terms['data'] ?? [] as $term) {
        if (!empty($term['is_current'])) {
            $currentTerm = $term;
            break;
        }
    }
    $firstClass = $classes['data'][0] ?? null;
    if ($currentTerm !== null && $firstClass !== null) {
        expects(
            $admin->get(
                '/admin/timetable?class_id=' . rawurlencode($firstClass['id']) .
                '&term_id=' . rawurlencode($currentTerm['id'])
            ),
            200,
            'admin: timetable workspace'
        );
    }

    expects($student->post('/auth/logout'), 204, 'student: logout');
    expects($student->get('/auth/me'), 401, 'student: session ended');
    expects($parent->post('/auth/logout'), 204, 'parent: logout');
    expects($teacher->post('/auth/logout'), 204, 'teacher: logout');
    expects($admin->post('/auth/logout'), 204, 'admin: logout');

    echo "\nAll smoke checks passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\n" . $exception->getMessage() . "\n");
    exit(1);
}

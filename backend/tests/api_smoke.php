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
use AlFalaah\Services\AccountSecurity;

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

/**
 * Removes records left behind by an interrupted run so the suite can be
 * retried without reusing a half-created guardian or student account.
 */
function cleanSmokeData(): void
{
    $emails = [
        'smoke-newsletter@example.com',
        'smoke-admission@example.com',
        'smoke-admission-validation@example.com',
        'smoke-auth-teacher@example.com',
    ];
    $placeholders = implode(', ', array_fill(0, count($emails), '?'));

    $users = Database::fetchAll(
        "SELECT id FROM users
         WHERE email IN ({$placeholders})
            OR email LIKE 'smoke.%@student.alfalaah.edu.ng'",
        $emails
    );

    foreach ($users as $user) {
        Database::execute('DELETE FROM students WHERE user_id = ?', [(int) $user['id']]);
    }

    if ($users !== []) {
        $ids = array_map(static fn (array $user): int => (int) $user['id'], $users);
        $idList = implode(', ', array_fill(0, count($ids), '?'));
        Database::execute("DELETE FROM audit_logs WHERE user_id IN ({$idList})", $ids);
        Database::execute("DELETE FROM users WHERE id IN ({$idList})", $ids);
    }

    foreach ($emails as $email) {
        Database::execute('DELETE FROM login_attempts WHERE email = ?', [$email]);
    }
    Database::execute("DELETE FROM login_attempts WHERE email LIKE 'smoke.%@student.alfalaah.edu.ng'");
    Database::execute("DELETE FROM admission_applications WHERE email IN ({$placeholders})", $emails);
    Database::execute('DELETE FROM newsletter_subscribers WHERE email = ?', ['smoke-newsletter@example.com']);
}

function check(bool $condition, string $label, ?string $detail = null): void
{
    if ($condition) {
        echo "  ok   {$label}\n";
        return;
    }

    echo "  FAIL {$label}" . ($detail !== null ? ", {$detail}" : '') . "\n";
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

function signInWithTwoFactor(
    string $base,
    string $email,
    string $password,
    string $secret,
    string $role
): ApiClient {
    $client = new ApiClient($base);
    expects($client->get('/session'), 200, "{$role}: two-factor session bootstrap");

    $challenge = expects($client->post('/auth/login', [
        'email' => $email,
        'password' => $password,
    ]), 200, "{$role}: two-factor password step");
    check(
        ($challenge['data']['two_factor_required'] ?? false) === true,
        "{$role}: two-factor challenge issued"
    );

    $body = expects($client->post('/auth/2fa/verify', [
        'code' => AccountSecurity::twoFactorCode($secret),
    ]), 200, "{$role}: two-factor code accepted");
    check(($body['data']['user']['role'] ?? null) === $role, "{$role}: role returned after two-factor");
    check(is_string($body['data']['csrf_token'] ?? null), "{$role}: CSRF token rotated after two-factor");

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
    cleanSmokeData();

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
    $studentUuid = (string) ($student->user['id'] ?? '');
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
    $adminUuid = (string) ($admin->user['id'] ?? '');
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
    $guardianAccount = $enrolled['data']['guardian_account'] ?? null;
    check(
        is_array($guardianAccount) && ($guardianAccount['email'] ?? null) === 'smoke-admission@example.com',
        'admin: enrollment creates a guardian account from the application email'
    );
    check(
        is_array($guardianAccount) && !empty($guardianAccount['temporary_password']),
        'admin: guardian temporary password returned once'
    );
    check(
        is_array($guardianAccount) && ($guardianAccount['created'] ?? null) === true,
        'admin: guardian account is newly created'
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

    if (is_array($guardianAccount) && !empty($guardianAccount['email'])) {
        $newGuardian = signIn(
            $base,
            (string) $guardianAccount['email'],
            (string) $guardianAccount['temporary_password'],
            'parent'
        );
        $guardianDashboard = expects($newGuardian->get('/dashboard'), 200, 'new guardian: can open the dashboard');
        $seesChild = false;
        foreach ($guardianDashboard['data']['children'] ?? [] as $child) {
            if (($child['student']['id'] ?? null) === $enrolledStudentId) {
                $seesChild = true;
                break;
            }
        }
        check($seesChild, 'new guardian: sees the newly enrolled child');
        expects($newGuardian->post('/auth/logout'), 204, 'new guardian: logout');
    }

    // ---------------------------------------------------------------------
    // Account security: password resets, email-code 2FA and office resets.
    // Throwaway users use @example.com so the mailer skips delivery.
    // ---------------------------------------------------------------------
    $authEmail = 'smoke-auth-teacher@example.com';
    $authPassword = 'SmokeAuth@2026';
    $resetPassword = 'SmokeAuth@2027';
    $authSecret = AccountSecurity::newTwoFactorSecret();

    Database::execute('DELETE FROM login_attempts WHERE email = ?', [$authEmail]);
    Database::execute('DELETE FROM users WHERE email = ?', [$authEmail]);
    $authUserId = Database::insert(
        "INSERT INTO users
            (uuid, email, password_hash, role, first_name, last_name, status, two_factor_enabled, two_factor_secret)
         VALUES (?, ?, ?, 'teacher', 'Smoke', 'Secured', 'active', 1, ?)",
        [Uuid::v4(), $authEmail, password_hash($authPassword, PASSWORD_DEFAULT), $authSecret]
    );
    $authUserUuid = (string) Database::fetchValue('SELECT uuid FROM users WHERE id = ?', [$authUserId]);

    // Reset requests answer identically whether or not the address exists, and
    // school-issued student inboxes fall back to an office reset.
    $genericReset = expects(
        $anonymous->post('/auth/forgot-password', ['email' => 'smoke-missing@example.com']),
        200,
        'reset: unknown email receives the generic response'
    );
    check(
        str_contains((string) ($genericReset['data']['message'] ?? ''), 'reset link'),
        'reset: the generic response explains the next step'
    );
    expects(
        $anonymous->post('/auth/forgot-password', ['email' => 'aisha.abdullahi@student.alfalaah.edu.ng']),
        200,
        'reset: student addresses are handled by the office'
    );

    $issued = expects(
        $anonymous->post('/auth/forgot-password', ['email' => $authEmail]),
        200,
        'reset: staff request is accepted'
    );
    check(
        ($issued['data']['message'] ?? null) === ($genericReset['data']['message'] ?? null),
        'reset: responses do not reveal whether an account exists'
    );

    $resetRow = Database::fetch(
        'SELECT id, token_hash, used_at FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$authUserId]
    );
    check(is_array($resetRow), 'reset: the request stores a reset record');
    check(
        is_string($resetRow['token_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $resetRow['token_hash']) === 1,
        'reset: only the token hash is stored'
    );
    check(($resetRow['used_at'] ?? null) === null, 'reset: the issued token is unused');

    // The raw token only travels by email, so inject a known token into the
    // same record to exercise the consumption path without an inbox.
    $resetToken = AccountSecurity::resetToken();
    Database::execute(
        'UPDATE password_resets SET token_hash = ? WHERE id = ?',
        [AccountSecurity::hashToken($resetToken), (int) $resetRow['id']]
    );

    expects(
        $anonymous->post('/auth/reset-password', [
            'token' => str_repeat('a', 64),
            'password' => $resetPassword,
            'password_confirmation' => $resetPassword,
        ]),
        400,
        'reset: an unknown token is rejected'
    );
    expects(
        $anonymous->post('/auth/reset-password', [
            'token' => $resetToken,
            'password' => $resetPassword,
            'password_confirmation' => $resetPassword . 'x',
        ]),
        422,
        'reset: a mismatched confirmation is rejected'
    );
    expects(
        $anonymous->post('/auth/reset-password', [
            'token' => $resetToken,
            'password' => $authPassword,
            'password_confirmation' => $authPassword,
        ]),
        422,
        'reset: reusing the current password is rejected'
    );
    expects(
        $anonymous->post('/auth/reset-password', [
            'token' => $resetToken,
            'password' => $resetPassword,
            'password_confirmation' => $resetPassword,
        ]),
        200,
        'reset: a valid token sets a new password'
    );
    expects(
        $anonymous->post('/auth/reset-password', [
            'token' => $resetToken,
            'password' => 'SmokeAuth@2028',
            'password_confirmation' => 'SmokeAuth@2028',
        ]),
        400,
        'reset: a token cannot be used twice'
    );

    $storedHash = (string) Database::fetchValue('SELECT password_hash FROM users WHERE id = ?', [$authUserId]);
    check(
        $storedHash !== $resetPassword && password_verify($resetPassword, $storedHash),
        'reset: the new password is stored as a hash'
    );

    // Two-factor sign-in for the same staff account.
    $twoFactorClient = new ApiClient($base);
    expects($twoFactorClient->get('/session'), 200, '2fa: session bootstrap');
    $challenge = expects(
        $twoFactorClient->post('/auth/login', ['email' => $authEmail, 'password' => $resetPassword]),
        200,
        '2fa: the password step succeeds'
    );
    check(($challenge['data']['two_factor_required'] ?? false) === true, '2fa: a code challenge is issued');
    check(
        !isset($challenge['data']['user']) && !isset($challenge['data']['two_factor_secret']),
        '2fa: the challenge does not expose the account or secret'
    );
    check(
        is_string($challenge['data']['email'] ?? null)
            && ($challenge['data']['email'] ?? '') !== $authEmail,
        '2fa: the delivery address is masked'
    );
    check((int) ($challenge['data']['expires_in'] ?? 0) > 0, '2fa: the challenge expiry is advertised');

    $wrongCode = AccountSecurity::twoFactorCode($authSecret) === '000000' ? '111111' : '000000';
    $rejected = expects(
        $twoFactorClient->post('/auth/2fa/verify', ['code' => $wrongCode]),
        401,
        '2fa: an incorrect code is rejected'
    );
    check(
        ($rejected['error']['code'] ?? null) === 'two_factor_invalid',
        '2fa: the rejection reports an invalid code'
    );
    check(
        (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0',
            [$authEmail]
        ) >= 1,
        '2fa: an incorrect code counts as a failed attempt'
    );
    expects($twoFactorClient->post('/auth/2fa/resend'), 429, '2fa: resend is throttled');

    $verified = expects(
        $twoFactorClient->post('/auth/2fa/verify', ['code' => AccountSecurity::twoFactorCode($authSecret)]),
        200,
        '2fa: the emailed code completes sign-in'
    );
    check(($verified['data']['user']['role'] ?? null) === 'teacher', '2fa: the signed-in account is returned');
    check(
        ($verified['data']['user']['two_factor']['enabled'] ?? false) === true,
        '2fa: the account reports verification enabled'
    );
    check(
        !isset($verified['data']['user']['two_factor_secret']),
        '2fa: the secret is never exposed'
    );
    check(
        (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0',
            [$authEmail]
        ) === 0,
        '2fa: a successful sign-in clears failed attempts'
    );
    expects($twoFactorClient->get('/dashboard'), 200, '2fa: the session is authenticated');
    expects(
        $twoFactorClient->post('/auth/2fa/verify', ['code' => AccountSecurity::twoFactorCode($authSecret)]),
        401,
        '2fa: the challenge cannot be replayed'
    );
    expects($twoFactorClient->post('/auth/logout'), 204, '2fa: logout');

    // Two-factor settings: disabling needs the password, enabling does not.
    $secured = signInWithTwoFactor($base, $authEmail, $resetPassword, $authSecret, 'teacher');
    expects(
        $secured->post('/auth/two-factor', ['enabled' => false]),
        422,
        '2fa settings: disabling requires the current password'
    );
    expects(
        $secured->post('/auth/two-factor', [
            'enabled' => false,
            'current_password' => 'WrongPassword@1',
        ]),
        422,
        '2fa settings: the wrong password cannot disable verification'
    );
    $disabled = expects(
        $secured->post('/auth/two-factor', [
            'enabled' => false,
            'current_password' => $resetPassword,
        ]),
        200,
        '2fa settings: verification can be disabled with the password'
    );
    check(
        ($disabled['data']['user']['two_factor']['enabled'] ?? true) === false,
        '2fa settings: the disabled state is returned'
    );
    expects($secured->post('/auth/logout'), 204, '2fa settings: logout');

    $plain = signIn($base, $authEmail, $resetPassword, 'teacher');
    check(
        ($plain->user['two_factor']['enabled'] ?? true) === false,
        '2fa settings: password-only sign-in works after disabling'
    );
    $enabled = expects(
        $plain->post('/auth/two-factor', ['enabled' => true]),
        200,
        '2fa settings: verification can be enabled without a password'
    );
    check(
        ($enabled['data']['user']['two_factor']['enabled'] ?? false) === true,
        '2fa settings: the enabled state is returned'
    );
    $newSecret = (string) Database::fetchValue(
        'SELECT two_factor_secret FROM users WHERE id = ?',
        [$authUserId]
    );
    check(strlen($newSecret) === 64, '2fa settings: a per-user secret is generated');
    check(
        !isset($enabled['data']['user']['two_factor_secret']),
        '2fa settings: the secret stays private'
    );
    expects($plain->post('/auth/logout'), 204, '2fa settings: final logout');

    // Office reset: one-time password, two-factor cleared, never logged.
    $adminReset = expects(
        $admin->post('/admin/users/' . rawurlencode($authUserUuid) . '/reset-password'),
        200,
        'admin: resets a portal password'
    );
    $temporaryPassword = $adminReset['data']['temporary_password'] ?? null;
    check(
        is_string($temporaryPassword) && strlen($temporaryPassword) >= 12,
        'admin: a temporary password is returned once'
    );
    check(
        ($adminReset['data']['two_factor_disabled'] ?? false) === true,
        'admin: the reset clears two-factor'
    );
    $afterReset = Database::fetch(
        'SELECT password_hash, two_factor_enabled, two_factor_secret FROM users WHERE id = ?',
        [$authUserId]
    );
    check(
        (int) ($afterReset['two_factor_enabled'] ?? 1) === 0
            && array_key_exists('two_factor_secret', (array) $afterReset)
            && $afterReset['two_factor_secret'] === null,
        'admin: two-factor is disabled in storage'
    );
    check(
        password_verify((string) $temporaryPassword, (string) ($afterReset['password_hash'] ?? '')),
        'admin: the temporary password is stored as a hash'
    );
    check(
        !str_contains(json_encode($adminReset, JSON_THROW_ON_ERROR), 'password_hash'),
        'admin: the response omits the password hash'
    );
    $auditRow = Database::fetch(
        'SELECT meta FROM audit_logs WHERE action = ? AND entity_uuid = ? ORDER BY id DESC LIMIT 1',
        ['user.password_reset', $authUserUuid]
    );
    check(
        !is_array($auditRow)
            || !str_contains((string) ($auditRow['meta'] ?? ''), (string) $temporaryPassword),
        'admin: the audit log never stores the temporary password'
    );

    $officeClient = signIn($base, $authEmail, (string) $temporaryPassword, 'teacher');
    expects($officeClient->post('/auth/logout'), 204, 'admin reset: the temporary password signs in');

    expects(
        $admin->post('/admin/users/' . rawurlencode($adminUuid) . '/reset-password'),
        422,
        'admin: cannot reset their own password'
    );
    expects(
        $admin->post('/admin/users/' . rawurlencode(Uuid::v4()) . '/reset-password'),
        404,
        'admin: resetting an unknown account is rejected'
    );
    expects(
        $student->post('/admin/users/' . rawurlencode($authUserUuid) . '/reset-password'),
        403,
        'student: cannot reset another password'
    );

    $reuseUuid = Uuid::v4();
    $reuseId = Database::insert(
        "INSERT INTO admission_applications
            (uuid, applicant_name, guardian_name, email, phone, level, status, notes)
         VALUES (?, ?, ?, ?, ?, 'primary', 'offer', ?)",
        [
            $reuseUuid,
            'Smoke Sibling Applicant',
            'Smoke Test Guardian',
            'smoke-admission@example.com',
            '+234 800 000 0000',
            'Temporary record created by the API smoke test.',
        ]
    );
    $reused = expects(
        $admin->post(
            '/admin/admissions/' . rawurlencode($reuseUuid) . '/enroll',
            ['class_id' => $enrollClass['id'], 'create_account' => false]
        ),
        201,
        'admin: second enrollment reuses the guardian account'
    );
    $reusedGuardian = $reused['data']['guardian_account'] ?? null;
    check(
        is_array($reusedGuardian)
            && ($reusedGuardian['created'] ?? null) === false
            && ($reusedGuardian['temporary_password'] ?? null) === null,
        'admin: existing guardian is linked without a new password'
    );
    $reuseStudentId = $reused['data']['student']['id'] ?? null;
    check(is_string($reuseStudentId), 'admin: reused-guardian enrollment links a student');

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
    Database::execute('DELETE FROM admission_applications WHERE id = ?', [$reuseId]);
    Database::execute('DELETE FROM admission_applications WHERE id = ?', [$tempAdmissionId]);
    if (is_string($enrolledStudentId)) {
        Database::execute('DELETE FROM students WHERE uuid = ?', [$enrolledStudentId]);
    }
    if (is_string($reuseStudentId)) {
        Database::execute('DELETE FROM students WHERE uuid = ?', [$reuseStudentId]);
    }
    if (is_array($portalAccount) && !empty($portalAccount['email'])) {
        Database::execute('DELETE FROM login_attempts WHERE email = ?', [$portalAccount['email']]);
        Database::execute('DELETE FROM users WHERE email = ?', [$portalAccount['email']]);
    }
    if (is_array($guardianAccount) && !empty($guardianAccount['email'])) {
        Database::execute('DELETE FROM login_attempts WHERE email = ?', [$guardianAccount['email']]);
        Database::execute('DELETE FROM users WHERE email = ?', [$guardianAccount['email']]);
    }
    Database::execute('DELETE FROM login_attempts WHERE email = ?', [$authEmail]);
    Database::execute('DELETE FROM audit_logs WHERE entity_uuid = ?', [$authUserUuid]);
    // password_resets rows disappear with the user through the cascade.
    Database::execute('DELETE FROM users WHERE id = ?', [$authUserId]);

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

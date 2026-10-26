<?php

declare(strict_types=1);

use AlFalaah\Controllers\AdminController;
use AlFalaah\Controllers\AnnouncementController;
use AlFalaah\Controllers\AuthController;
use AlFalaah\Controllers\DashboardController;
use AlFalaah\Controllers\HealthController;
use AlFalaah\Controllers\NotificationController;
use AlFalaah\Controllers\ReferenceController;
use AlFalaah\Controllers\StudentController;
use AlFalaah\Controllers\TeacherController;
use AlFalaah\Core\Env;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Logger;
use AlFalaah\Core\Request;
use AlFalaah\Core\Response;
use AlFalaah\Core\Router;
use AlFalaah\Core\Session;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require dirname(__DIR__) . '/src/bootstrap.php';

Logger::setRequestId(bin2hex(random_bytes(8)));

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $exception): void {
    if ($exception instanceof HttpException) {
        if ($exception->status >= 500) {
            Logger::error('API request failed', [
                'status' => $exception->status,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }

        Response::error(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->status,
            $exception->fields,
            $exception->headers
        );
    }

    if ($exception instanceof PDOException) {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        if ($driverCode === 1062) {
            Response::error('conflict', 'A record with those details already exists.', 409);
        }
        if (in_array($driverCode, [1451, 1452], true)) {
            Response::error('conflict', 'That record is still in use and cannot be changed.', 409);
        }
    }

    Logger::error('Unhandled API exception', [
        'type' => $exception::class,
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ]);

    Response::error(
        'server_error',
        Env::bool('APP_DEBUG', false) ? $exception->getMessage() : 'An unexpected error occurred. Please try again.',
        500
    );
});

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestUri = is_string($requestUri) && $requestUri !== '' ? $requestUri : '/';
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
$path = $requestUri;

if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
}

if (!str_starts_with($path, '/api')) {
    $apiPosition = strpos($requestUri, '/api');
    if ($apiPosition !== false) {
        $path = substr($requestUri, $apiPosition);
    }
}

$path = '/' . ltrim($path, '/');

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', Env::get('CORS_ALLOWED_ORIGINS', ''))
)));

$origin = Request::origin();
$originAllowed = $origin !== null && in_array(rtrim($origin, '/'), $allowedOrigins, true);

if ($originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, X-CSRF-Token, X-XSRF-TOKEN, X-Requested-With');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

if (Request::method() === 'OPTIONS') {
    if ($origin !== null && !$originAllowed) {
        Response::error('origin_not_allowed', 'That origin is not allowed to call this API.', 403);
    }

    http_response_code(204);
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    header('X-Content-Type-Options: nosniff');
    header('X-Request-Id: ' . Logger::$requestId);
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 1_048_576) {
    Response::error('payload_too_large', 'The request body is too large.', 413);
}

if ($path !== '/api/health') {
    Session::start();
}

$router = new Router();

$router->get('/api/health', [HealthController::class, 'show'], ['auth' => false]);
$router->get('/api/session', [AuthController::class, 'session'], ['auth' => false]);
$router->post('/api/auth/login', [AuthController::class, 'login'], ['auth' => false]);

$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/auth/password', [AuthController::class, 'changePassword']);

$router->get('/api/dashboard', [DashboardController::class, 'show']);
$router->get('/api/terms', [ReferenceController::class, 'terms']);
$router->get('/api/classes', [ReferenceController::class, 'classes']);
$router->get('/api/subjects', [ReferenceController::class, 'subjects']);
$router->get('/api/announcements', [AnnouncementController::class, 'index']);
$router->get('/api/notifications', [NotificationController::class, 'index']);
$router->patch('/api/notifications/{id}/read', [NotificationController::class, 'read']);
$router->post('/api/notifications/read-all', [NotificationController::class, 'readAll']);

$router->get('/api/parent/children', [StudentController::class, 'children'], ['roles' => ['parent']]);

$router->get('/api/students', [StudentController::class, 'index'], ['roles' => ['teacher', 'admin']]);
$router->get('/api/students/{id}', [StudentController::class, 'show']);
$router->get('/api/students/{id}/results', [StudentController::class, 'results']);
$router->get('/api/students/{id}/attendance', [StudentController::class, 'attendance']);
$router->get('/api/students/{id}/fees', [StudentController::class, 'fees']);
$router->get('/api/students/{id}/timetable', [StudentController::class, 'timetable']);
$router->get('/api/students/{id}/assignments', [StudentController::class, 'assignments']);
$router->get('/api/students/{id}/report', [StudentController::class, 'report']);

$router->get('/api/teacher/classes', [TeacherController::class, 'classes'], ['roles' => ['teacher']]);
$router->get('/api/teacher/schedule', [TeacherController::class, 'schedule'], ['roles' => ['teacher']]);
$router->get('/api/teacher/attendance', [TeacherController::class, 'attendance'], ['roles' => ['teacher']]);
$router->post('/api/teacher/attendance', [TeacherController::class, 'markAttendance'], ['roles' => ['teacher']]);
$router->get('/api/teacher/result-sheets', [TeacherController::class, 'resultSheets'], ['roles' => ['teacher']]);
$router->post('/api/teacher/result-sheets', [TeacherController::class, 'createResultSheet'], ['roles' => ['teacher']]);
$router->get('/api/teacher/result-sheets/{id}', [TeacherController::class, 'showResultSheet'], ['roles' => ['teacher', 'admin']]);
$router->patch('/api/teacher/result-sheets/{id}', [TeacherController::class, 'saveResultSheet'], ['roles' => ['teacher']]);
$router->post('/api/teacher/result-sheets/{id}/submit', [TeacherController::class, 'submitResultSheet'], ['roles' => ['teacher']]);
$router->get('/api/teacher/assignments', [TeacherController::class, 'assignments'], ['roles' => ['teacher']]);
$router->post('/api/teacher/assignments', [TeacherController::class, 'createAssignment'], ['roles' => ['teacher']]);
$router->get('/api/teacher/students', [TeacherController::class, 'classStudents'], ['roles' => ['teacher']]);

$router->get('/api/admin/overview', [AdminController::class, 'overview'], ['roles' => ['admin']]);
$router->get('/api/admin/students', [StudentController::class, 'index'], ['roles' => ['admin']]);
$router->post('/api/admin/students', [AdminController::class, 'createStudent'], ['roles' => ['admin']]);
$router->patch('/api/admin/students/{id}', [AdminController::class, 'updateStudent'], ['roles' => ['admin']]);
$router->get('/api/admin/announcements', [AdminController::class, 'announcements'], ['roles' => ['admin']]);
$router->post('/api/admin/announcements', [AdminController::class, 'createAnnouncement'], ['roles' => ['admin']]);
$router->patch('/api/admin/announcements/{id}', [AdminController::class, 'updateAnnouncement'], ['roles' => ['admin']]);
$router->delete('/api/admin/announcements/{id}', [AdminController::class, 'archiveAnnouncement'], ['roles' => ['admin']]);
$router->get('/api/admin/result-sheets', [AdminController::class, 'resultSheets'], ['roles' => ['admin']]);
$router->post('/api/admin/result-sheets/{id}/approve', [AdminController::class, 'approveResultSheet'], ['roles' => ['admin']]);
$router->post('/api/admin/result-sheets/{id}/return', [AdminController::class, 'returnResultSheet'], ['roles' => ['admin']]);
$router->post('/api/admin/terms/{id}/publish', [AdminController::class, 'publishTerm'], ['roles' => ['admin']]);
$router->get('/api/admin/admissions', [AdminController::class, 'admissions'], ['roles' => ['admin']]);
$router->patch('/api/admin/admissions/{id}', [AdminController::class, 'updateAdmission'], ['roles' => ['admin']]);
$router->get('/api/admin/finance', [AdminController::class, 'finance'], ['roles' => ['admin']]);
$router->post('/api/admin/payments', [AdminController::class, 'recordPayment'], ['roles' => ['admin']]);
$router->post('/api/admin/invoices', [AdminController::class, 'createInvoice'], ['roles' => ['admin']]);
$router->get('/api/admin/timetable', [AdminController::class, 'timetable'], ['roles' => ['admin']]);
$router->post('/api/admin/timetable', [AdminController::class, 'createTimetableSlot'], ['roles' => ['admin']]);
$router->delete('/api/admin/timetable/{id}', [AdminController::class, 'deleteTimetableSlot'], ['roles' => ['admin']]);
$router->get('/api/admin/teachers', [ReferenceController::class, 'teachers'], ['roles' => ['admin']]);

$router->dispatch(Request::method(), $path);

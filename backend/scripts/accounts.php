<?php

declare(strict_types=1);

/**
 * Lists portal login accounts for local testing.
 *
 * Usage:
 *   php backend/scripts/accounts.php
 *   php backend/scripts/accounts.php --write
 *
 * --write saves the same report to ./accounts.text at the repository root.
 * That file is gitignored and intended for local testing only.
 *
 * Passwords in the database are stored as one-way hashes and cannot be
 * recovered. Seeded demo accounts all share DEMO_PASSWORD from backend/.env,
 * which is why it can be shown here.
 */

use AlFalaah\Core\Database;
use AlFalaah\Core\Env;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This account report can only be run from the command line.\n");
}

$write = in_array('--write', $argv, true);

$outputPath = dirname(ALFALAAH_ROOT) . '/accounts.text';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $outputPath = substr($argument, strlen('--output='));
    }
}

$users = Database::fetchAll(
    "SELECT first_name, last_name, email, role, status
     FROM users
     ORDER BY FIELD(role, 'admin', 'teacher', 'parent', 'student'), email"
);

$password = Env::get('DEMO_PASSWORD', '') ?? '';
$portal = (Env::get('PORTAL_URL', '') ?? '') . '/portal.html';
if (trim($portal) === '/portal.html') {
    $portal = 'frontend/portal.html';
}

$lines = [];
$lines[] = 'AL-FALAAH SCHOOL PORTAL — TEST ACCOUNT DETAILS';
$lines[] = 'Generated: ' . date('Y-m-d H:i:s');
$lines[] = 'Portal:    ' . $portal;
$lines[] = '';
$lines[] = 'Shared demo password: ' . ($password !== '' ? $password : '(DEMO_PASSWORD is not set)');
$lines[] = 'All seeded accounts below use the shared demo password.';
$lines[] = 'Accounts created later may have a different password, which is';
$lines[] = 'stored as a one-way hash and cannot be recovered from the database.';
$lines[] = '';
$lines[] = 'LOCAL TESTING ONLY — DO NOT COMMIT OR SHARE THIS FILE.';
$lines[] = '';

$header = sprintf('%-8s  %-48s  %-18s  %-8s', 'ROLE', 'EMAIL', 'NAME', 'STATUS');
$lines[] = $header;
$lines[] = str_repeat('-', strlen($header));

foreach ($users as $user) {
    $lines[] = sprintf(
        '%-8s  %-48s  %-18s  %-8s',
        $user['role'],
        $user['email'],
        trim($user['first_name'] . ' ' . $user['last_name']),
        $user['status']
    );
}

$lines[] = '';
$lines[] = 'Total: ' . count($users) . ' account' . (count($users) === 1 ? '' : 's') . '.';
$lines[] = '';

$report = implode("\n", $lines) . "\n";

echo $report;

if ($write) {
    if (@file_put_contents($outputPath, $report, LOCK_EX) === false) {
        fwrite(STDERR, "Could not write {$outputPath}\n");
        exit(1);
    }

    echo "Saved to: {$outputPath}\n";
}

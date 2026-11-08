<?php

declare(strict_types=1);

/**
 * Checks the dependency-free mailer using the `log` driver.
 *
 * Usage: php backend/tests/mailer_test.php
 * Transport-level SMTP delivery is not exercised; the log driver verifies
 * message rendering, header handling and validation without sending anything.
 */

// Process environment wins over .env in Env::load(), so force the log driver
// before bootstrap reads the environment file.
putenv('MAIL_DRIVER=log');
putenv('MAIL_FROM_ADDRESS=no-reply@test.local');
putenv('MAIL_FROM_NAME=Al-Falaah Test');

use AlFalaah\Core\Mailer;

require dirname(__DIR__) . '/src/bootstrap.php';

function check(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "  FAIL {$label}\n");
        exit(1);
    }

    echo "  ok   {$label}\n";
}

$path = ALFALAAH_STORAGE . '/logs/mail.log';
$original = is_file($path) ? (string) file_get_contents($path) : null;
$before = $original === null ? 0 : strlen($original);

echo "Al-Falaah mailer test\n";
echo "Driver: log\n\n";

check(Mailer::send([
    'to' => ['office@test.local'],
    'subject' => 'Test subject',
    'text' => "Line one\nLine two",
    'reply_to' => 'parent@test.local',
]), 'message accepted');

$contents = is_file($path) ? (string) file_get_contents($path) : '';
$entry = substr($contents, $before);
check($entry !== '', 'log entry written');
check(str_contains($entry, 'To: office@test.local'), 'recipient rendered');
check(str_contains($entry, 'From: Al-Falaah Test <no-reply@test.local>'), 'sender rendered');
check(str_contains($entry, 'Reply-To: parent@test.local'), 'reply-to rendered');
check(str_contains($entry, "Line one\nLine two"), 'body rendered');

$before = (int) filesize($path);
Mailer::send([
    'to' => 'office@test.local',
    'subject' => "Hello\r\nBcc: attacker@example.com",
    'text' => 'Injection attempt',
]);
$entry = substr((string) file_get_contents($path), $before);
check(!str_contains($entry, "\r\nBcc:") && !str_contains($entry, "\nBcc:"), 'header injection stripped');

$threw = false;
try {
    Mailer::send([
        'to' => 'not-an-email',
        'subject' => 'Invalid',
        'text' => 'Invalid recipient',
    ]);
} catch (InvalidArgumentException) {
    $threw = true;
}
check($threw, 'invalid recipient rejected');

$threw = false;
try {
    Mailer::send([
        'to' => ['office@test.local'],
        'subject' => 'Missing body sender',
        'text' => '',
        'from' => 'invalid',
    ]);
} catch (InvalidArgumentException) {
    $threw = true;
}
check($threw, 'invalid sender rejected');

// Restore the storage log to its original state.
if ($original === null) {
    @unlink($path);
} else {
    @file_put_contents($path, $original);
}

echo "\nAll mailer checks passed.\n";

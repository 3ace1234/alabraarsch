<?php

declare(strict_types=1);

/**
 * Sends a test email using the configured MAIL_* settings.
 *
 * Usage: php backend/scripts/test_mail.php [recipient@example.com]
 *
 * Prints the active transport (never the password) and reports delivery
 * errors. Useful for verifying Gmail app-password or SMTP relay settings.
 */

use AlFalaah\Core\Env;
use AlFalaah\Core\Logger;
use AlFalaah\Core\Mailer;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This mail test can only be run from the command line.\n");
}

$driver = strtolower(trim(Env::get('MAIL_DRIVER', 'log') ?? 'log'));
$username = Env::get('MAIL_USERNAME', '') ?? '';
$password = Env::get('MAIL_PASSWORD', '') ?? '';
$from = trim(Env::get('MAIL_FROM_ADDRESS', '') ?? '');
$recipient = $argv[1] ?? (Env::get('MAIL_INTAKE_TO', '') ?? '');

echo "Al-Falaah mail test\n";
echo "-------------------\n";
echo "Driver:     {$driver}\n";

if ($driver === 'smtp') {
    echo 'Server:     ' . Env::get('MAIL_HOST', '') . ':' . Env::get('MAIL_PORT', '587')
        . ' (' . Env::get('MAIL_ENCRYPTION', 'tls') . ")\n";
    echo 'Username:   ' . ($username !== '' ? $username : '(empty)') . "\n";
    echo 'Password:   ' . ($password !== '' ? 'set' : 'NOT SET') . "\n";
}

echo 'From:       ' . ($from !== '' ? $from : '(empty)') . "\n";
echo 'Recipient:  ' . ($recipient !== '' ? $recipient : '(empty)') . "\n\n";

if ($driver === 'smtp' && $password === '') {
    fwrite(STDERR, "MAIL_PASSWORD is empty. Add your Gmail App Password to backend/.env first.\n");
    fwrite(STDERR, "Create one at https://myaccount.google.com/apppasswords (spaces can be removed).\n");
    exit(1);
}

if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "No valid recipient. Pass one: php backend/scripts/test_mail.php you@example.com\n");
    exit(1);
}

if ($driver === 'none') {
    fwrite(STDERR, "MAIL_DRIVER is none, so no message will be sent.\n");
    exit(1);
}

try {
    $sent = Mailer::send([
        'to' => [$recipient],
        'subject' => 'Al-Falaah mail test — ' . date('Y-m-d H:i'),
        'text' => implode("\n", [
            'This is a test message from the Al-Falaah school portal.',
            '',
            'If you can read this, the SMTP settings in backend/.env are working.',
            '',
            'Sent: ' . date('c'),
        ]),
    ]);

    if ($sent) {
        echo "SUCCESS: message handed to the {$driver} transport.\n";
        echo "Check the inbox for {$recipient} (and the spam folder).\n";
        exit(0);
    }

    fwrite(STDERR, "No message was sent (transport disabled).\n");
    exit(1);
} catch (Throwable $exception) {
    Logger::error('Mail test failed', ['exception' => $exception->getMessage()]);
    fwrite(STDERR, "FAILED: {$exception->getMessage()}\n");
    fwrite(STDERR, "Details were written to backend/storage/logs/app.log\n");
    exit(1);
}

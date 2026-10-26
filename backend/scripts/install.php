<?php

declare(strict_types=1);

use AlFalaah\Core\Env;
use AlFalaah\Core\Logger;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This installer can only be run from the command line.\n");
}

$options = getopt('', ['fresh', 'no-seed', 'seed-only', 'force']);
$fresh = isset($options['fresh']);
$noSeed = isset($options['no-seed']);
$seedOnly = isset($options['seed-only']);

$database = Env::get('DB_DATABASE', 'alfalaah_portal');
$username = Env::get('DB_USERNAME', 'alfalaah_app');
$password = Env::get('DB_PASSWORD', '');
$host = Env::get('DB_HOST', '127.0.0.1');
$port = Env::get('DB_PORT', '3306');
$charset = Env::get('DB_CHARSET', 'utf8mb4');

if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $database)) {
    fwrite(STDERR, "Invalid DB_DATABASE name. Use letters, numbers and underscores only.\n");
    exit(1);
}

echo "Al-Falaah portal installer\n";
echo "--------------------------\n";
echo "Database: {$database} on {$host}:{$port}\n";
echo "App user: {$username}\n";

if ($fresh && Env::get('APP_ENV') === 'production' && !isset($options['force'])) {
    fwrite(STDERR, "Refusing to --fresh a production database without --force.\n");
    exit(1);
}

$adminUser = Env::get('DB_ADMIN_USERNAME', '');
$adminPass = Env::get('DB_ADMIN_PASSWORD', '');

$serverDsn = sprintf('mysql:host=%s;port=%s;charset=%s', $host, $port, $charset);
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$server = null;
$adminError = null;

if ($adminUser !== '') {
    try {
        $server = new PDO($serverDsn, $adminUser, $adminPass, $pdoOptions);
    } catch (Throwable $exception) {
        $adminError = $exception->getMessage();
        echo "Admin connection failed; trying the application account instead.\n";
    }
}

if ($server !== null) {
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Create/update a least-privilege application account for local installs.
    // Managed hosting normally provisions this account outside the installer.
    if ($username !== '' && $username !== $adminUser) {
        $safeUser = preg_replace('/[^A-Za-z0-9_]/', '', (string) $username);
        $quotedPassword = $server->quote((string) $password);

        foreach (['localhost', '127.0.0.1'] as $accountHost) {
            try {
                $server->exec("CREATE USER IF NOT EXISTS '{$safeUser}'@'{$accountHost}' IDENTIFIED BY {$quotedPassword}");
                $server->exec("ALTER USER '{$safeUser}'@'{$accountHost}' IDENTIFIED BY {$quotedPassword}");
                $server->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$database}`.* TO '{$safeUser}'@'{$accountHost}'");
                $server->exec("FLUSH PRIVILEGES");
            } catch (Throwable $exception) {
                echo "Could not provision '{$safeUser}'@'{$accountHost}': {$exception->getMessage()}\n";
            }
        }
    }

    $server->exec("USE `{$database}`");
} else {
    try {
        $server = new PDO($serverDsn . ";dbname={$database}", $username, $password, $pdoOptions);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Could not connect to MySQL.\n");
        if ($adminError !== null) {
            fwrite(STDERR, "Admin error: {$adminError}\n");
        }
        fwrite(STDERR, "App error: {$exception->getMessage()}\n");
        exit(1);
    }
}

echo "Connected to MySQL successfully.\n";

try {
    if ($fresh) {
        echo "Dropping existing tables...\n";
        $server->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $server->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = " . $server->quote((string) $database)
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $server->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
        }
        $server->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    if ($seedOnly) {
        $hasTables = (bool) $server->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $server->quote((string) $database)
        )->fetchColumn();
        if (!$hasTables) {
            fwrite(STDERR, "No tables found. Run the installer without --seed-only first.\n");
            exit(1);
        }
    } else {
        echo "Applying schema...\n";
        $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Could not read database/schema.sql');
        }

        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $server->exec($statement);
            }
        }
        echo "Schema applied.\n";
    }

    $existingUsers = 0;
    try {
        $existingUsers = (int) $server->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable) {
        $existingUsers = 0;
    }

    if (!$noSeed && ($existingUsers === 0 || $fresh || $seedOnly)) {
        $demoPassword = Env::get('DEMO_PASSWORD', '');
        if ($demoPassword === '') {
            echo "DEMO_PASSWORD is empty; skipping sample data.\n";
        } else {
            echo "Seeding sample data...\n";
            $seeder = require dirname(__DIR__) . '/database/seed.php';
            $summary = $seeder($server, $demoPassword);
            echo "Sample data seeded.\n";
            foreach ($summary as $label => $value) {
                echo "  - {$label}: {$value}\n";
            }
            echo "\nDemo sign-in password: {$demoPassword}\n";
            echo "Demo accounts:\n";
            echo "  Student  aisha.abdullahi@student.alfalaah.edu.ng\n";
            echo "  Parent   ibrahim.abdullahi@example.com\n";
            echo "  Teacher  s.bello@alfalaah.edu.ng\n";
            echo "  Admin    h.sanni@alfalaah.edu.ng\n";
        }
    } elseif ($existingUsers > 0) {
        echo "Existing data found; sample data not re-seeded. Use --fresh to reset.\n";
    }

    // Verify the application account can read the installed schema.
    try {
        $app = new PDO($serverDsn . ";dbname={$database}", $username, $password, $pdoOptions);
        $app->query('SELECT COUNT(*) FROM users')->fetchColumn();
        echo "\nApplication database connection verified.\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, "\nWarning: the application account could not read the database: {$exception->getMessage()}\n");
        fwrite(STDERR, "Check DB_USERNAME / DB_PASSWORD and the GRANT for this account.\n");
    }

    echo "\nAPI base URL: " . Env::get('APP_URL', '') . "/api\n";
    echo "Health check: " . Env::get('APP_URL', '') . "/api/health\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\nInstallation failed: {$exception->getMessage()}\n");
    Logger::error('Installer failed', ['exception' => $exception->getMessage()]);
    exit(1);
}

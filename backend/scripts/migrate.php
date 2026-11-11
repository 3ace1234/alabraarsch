<?php

declare(strict_types=1);

use AlFalaah\Core\Env;
use AlFalaah\Core\Logger;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration runner can only be run from the command line.\n");
}

$database = Env::get('DB_DATABASE', 'alfalaah_portal');
$host = Env::get('DB_HOST', '127.0.0.1');
$port = Env::get('DB_PORT', '3306');
$charset = Env::get('DB_CHARSET', 'utf8mb4');
$adminUser = Env::get('DB_ADMIN_USERNAME', '');
$adminPass = Env::get('DB_ADMIN_PASSWORD', '');

if ($adminUser === '') {
    fwrite(STDERR, "DB_ADMIN_USERNAME is empty. An administrative MySQL account is required to alter the schema.\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);

try {
    $pdo = new PDO($dsn, $adminUser, $adminPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $exception) {
    fwrite(STDERR, "Could not connect to MySQL: {$exception->getMessage()}\n");
    exit(1);
}

echo "Al-Falaah portal migrations\n";
echo "---------------------------\n";
echo "Database: {$database} on {$host}:{$port}\n";

$columnExists = static function (PDO $pdo, string $table, string $column) use ($database): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ? AND column_name = ?'
    );
    $statement->execute([$database, $table, $column]);

    return (int) $statement->fetchColumn() > 0;
};

$tableExists = static function (PDO $pdo, string $table) use ($database): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = ? AND table_name = ?'
    );
    $statement->execute([$database, $table]);

    return (int) $statement->fetchColumn() > 0;
};

$constraintExists = static function (PDO $pdo, string $table, string $constraint) use ($database): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.table_constraints
         WHERE table_schema = ? AND table_name = ? AND constraint_name = ?'
    );
    $statement->execute([$database, $table, $constraint]);

    return (int) $statement->fetchColumn() > 0;
};

try {
    $columns = [
        'class_label' => 'ALTER TABLE admission_applications ADD COLUMN class_label VARCHAR(60) NULL AFTER level',
        'date_of_birth' => 'ALTER TABLE admission_applications ADD COLUMN date_of_birth DATE NULL AFTER class_label',
        'gender' => "ALTER TABLE admission_applications ADD COLUMN gender ENUM('male','female') NULL AFTER date_of_birth",
        'previous_school' => 'ALTER TABLE admission_applications ADD COLUMN previous_school VARCHAR(160) NULL AFTER gender',
    ];

    foreach ($columns as $column => $sql) {
        if ($columnExists($pdo, 'admission_applications', $column)) {
            echo "  - admission_applications.{$column}: already present\n";
            continue;
        }

        $pdo->exec($sql);
        echo "  - admission_applications.{$column}: added\n";
    }

    if ($columnExists($pdo, 'admission_applications', 'student_id')) {
        echo "  - admission_applications.student_id: already present\n";
    } else {
        $pdo->exec(
            'ALTER TABLE admission_applications
             ADD COLUMN student_id BIGINT UNSIGNED NULL AFTER guardian_name'
        );
        echo "  - admission_applications.student_id: added\n";
    }

    if ($constraintExists($pdo, 'admission_applications', 'fk_admissions_student')) {
        echo "  - admission_applications.fk_admissions_student: already present\n";
    } else {
        $pdo->exec(
            'ALTER TABLE admission_applications
             ADD CONSTRAINT fk_admissions_student FOREIGN KEY (student_id)
             REFERENCES students (id) ON DELETE SET NULL ON UPDATE CASCADE'
        );
        echo "  - admission_applications.fk_admissions_student: added\n";
    }

    $userColumns = [
        'two_factor_enabled' => 'ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER password_changed_at',
        'two_factor_secret' => 'ALTER TABLE users ADD COLUMN two_factor_secret CHAR(64) NULL AFTER two_factor_enabled',
    ];

    foreach ($userColumns as $column => $sql) {
        if ($columnExists($pdo, 'users', $column)) {
            echo "  - users.{$column}: already present\n";
            continue;
        }

        $pdo->exec($sql);
        echo "  - users.{$column}: added\n";
    }

    if ($tableExists($pdo, 'password_resets')) {
        echo "  - password_resets: already present\n";
    } else {
        $pdo->exec(
            'CREATE TABLE password_resets (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64)        NOT NULL,
                ip         VARCHAR(45)     NULL,
                expires_at DATETIME        NOT NULL,
                used_at    DATETIME        NULL,
                created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_password_resets_token (token_hash),
                KEY idx_password_resets_user (user_id, expires_at),
                CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        echo "  - password_resets: created\n";
    }

    if ($tableExists($pdo, 'contact_messages')) {
        echo "  - contact_messages: already present\n";
    } else {
        $pdo->exec(
            "CREATE TABLE contact_messages (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                uuid       CHAR(36)        NOT NULL,
                name       VARCHAR(120)    NOT NULL,
                email      VARCHAR(190)    NULL,
                phone      VARCHAR(30)     NULL,
                subject    VARCHAR(120)    NOT NULL,
                message    TEXT            NOT NULL,
                status     ENUM('new','in_progress','resolved','archived') NOT NULL DEFAULT 'new',
                created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_contact_messages_uuid (uuid),
                KEY idx_contact_messages_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "  - contact_messages: created\n";
    }

    if ($tableExists($pdo, 'newsletter_subscribers')) {
        echo "  - newsletter_subscribers: already present\n";
    } else {
        $pdo->exec(
            "CREATE TABLE newsletter_subscribers (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                uuid       CHAR(36)        NOT NULL,
                email      VARCHAR(190)    NOT NULL,
                status     ENUM('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
                source     VARCHAR(40)     NOT NULL DEFAULT 'website',
                created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_newsletter_uuid (uuid),
                UNIQUE KEY uq_newsletter_email (email),
                KEY idx_newsletter_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "  - newsletter_subscribers: created\n";
    }

    echo "\nMigrations complete.\n";
} catch (Throwable $exception) {
    Logger::error('Migration failed', ['exception' => $exception->getMessage()]);
    fwrite(STDERR, "\nMigration failed: {$exception->getMessage()}\n");
    exit(1);
}

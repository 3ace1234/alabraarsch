<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Uuid;
use AlFalaah\Core\Validator;
use PDO;

final class Announcements
{
    public const AUDIENCES = ['student', 'parent', 'teacher', 'admin'];

    public static function listForRole(string $role, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $rows = Database::fetchAll(
            "SELECT a.*, u.first_name AS author_first_name, u.last_name AS author_last_name,
                    (SELECT GROUP_CONCAT(aa.audience ORDER BY aa.audience)
                     FROM announcement_audiences aa WHERE aa.announcement_id = a.id) AS audiences
             FROM announcements a
             INNER JOIN announcement_audiences aa2
                ON aa2.announcement_id = a.id AND aa2.audience = ?
             LEFT JOIN users u ON u.id = a.created_by
             WHERE a.status = 'published' AND a.published_at <= NOW()
             ORDER BY a.is_pinned DESC, a.published_at DESC
             LIMIT {$limit}",
            [$role]
        );

        return array_map(static fn (array $row): array => self::present($row), $rows);
    }

    public static function listForAdmin(?string $status = null, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT a.*, u.first_name AS author_first_name, u.last_name AS author_last_name,
                       (SELECT GROUP_CONCAT(aa.audience ORDER BY aa.audience)
                        FROM announcement_audiences aa WHERE aa.announcement_id = a.id) AS audiences
                FROM announcements a
                LEFT JOIN users u ON u.id = a.created_by
                WHERE 1 = 1";
        $params = [];

        if ($status !== null) {
            $sql .= ' AND a.status = ?';
            $params[] = $status;
        }

        $sql .= " ORDER BY a.is_pinned DESC, COALESCE(a.published_at, a.created_at) DESC LIMIT {$limit}";

        $rows = Database::fetchAll($sql, $params);

        return array_map(static fn (array $row): array => self::present($row, true), $rows);
    }

    public static function findByUuid(string $uuid): ?array
    {
        return Database::fetch(
            "SELECT a.*, u.first_name AS author_first_name, u.last_name AS author_last_name,
                    (SELECT GROUP_CONCAT(aa.audience ORDER BY aa.audience)
                     FROM announcement_audiences aa WHERE aa.announcement_id = a.id) AS audiences
             FROM announcements a
             LEFT JOIN users u ON u.id = a.created_by
             WHERE a.uuid = ?
             LIMIT 1",
            [$uuid]
        );
    }

    public static function create(array $data, int $authorId): array
    {
        $title = Validator::string($data['title'] ?? null, 'title', 3, 160);
        $body = Validator::string($data['body'] ?? null, 'body', 10, 5000);
        $category = Validator::string($data['category'] ?? 'School', 'category', 1, 30);
        $audiences = self::validateAudiences($data['audiences'] ?? null);
        $status = Validator::enum($data['status'] ?? 'draft', 'status', ['draft', 'published']);
        $isPinned = Validator::boolean($data['is_pinned'] ?? false, 'is_pinned');
        $uuid = Uuid::v4();

        $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;

        Database::transaction(static function (PDO $pdo) use (
            $uuid,
            $title,
            $body,
            $category,
            $status,
            $isPinned,
            $publishedAt,
            $authorId,
            $audiences
        ): void {
            $insert = $pdo->prepare(
                'INSERT INTO announcements
                    (uuid, title, body, category, is_pinned, status, published_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $uuid,
                $title,
                $body,
                $category,
                $isPinned ? 1 : 0,
                $status,
                $publishedAt,
                $authorId,
            ]);

            $announcementId = (int) $pdo->lastInsertId();
            self::replaceAudiences($pdo, $announcementId, $audiences);
        });

        if ($status === 'published') {
            Notifications::createForAudiences(
                $audiences,
                $title,
                $body,
                'announcement',
                'announcement',
                $uuid,
                $authorId
            );
        }

        $announcement = self::findByUuid($uuid);
        if ($announcement === null) {
            throw HttpException::server('The announcement could not be loaded.');
        }

        return self::present($announcement, true);
    }

    public static function update(string $uuid, array $data, int $actorId): array
    {
        $existing = self::findByUuid($uuid);
        if ($existing === null) {
            throw HttpException::notFound('That announcement was not found.');
        }

        $fields = [];
        $params = [];

        if (array_key_exists('title', $data)) {
            $fields[] = 'title = ?';
            $params[] = Validator::string($data['title'], 'title', 3, 160);
        }
        if (array_key_exists('body', $data)) {
            $fields[] = 'body = ?';
            $params[] = Validator::string($data['body'], 'body', 10, 5000);
        }
        if (array_key_exists('category', $data)) {
            $fields[] = 'category = ?';
            $params[] = Validator::string($data['category'], 'category', 1, 30);
        }
        if (array_key_exists('is_pinned', $data)) {
            $fields[] = 'is_pinned = ?';
            $params[] = Validator::boolean($data['is_pinned'], 'is_pinned') ? 1 : 0;
        }

        $audiences = null;
        if (array_key_exists('audiences', $data)) {
            $audiences = self::validateAudiences($data['audiences']);
        }

        $newlyPublished = false;
        if (array_key_exists('status', $data)) {
            $status = Validator::enum($data['status'], 'status', ['draft', 'published', 'archived']);
            $fields[] = 'status = ?';
            $params[] = $status;

            if ($status === 'published' && $existing['published_at'] === null) {
                $fields[] = 'published_at = NOW()';
                $newlyPublished = true;
            }
        }

        if ($fields === [] && $audiences === null) {
            throw HttpException::validation('There is nothing to update.', ['announcement' => 'No supported fields supplied.']);
        }

        Database::transaction(static function (PDO $pdo) use ($fields, $params, $uuid, $audiences): void {
            if ($fields !== []) {
                $params[] = $uuid;
                $pdo->prepare('UPDATE announcements SET ' . implode(', ', $fields) . ' WHERE uuid = ?')
                    ->execute($params);
            }

            if ($audiences !== null) {
                $idStatement = $pdo->prepare('SELECT id FROM announcements WHERE uuid = ?');
                $idStatement->execute([$uuid]);
                $announcementId = (int) $idStatement->fetchColumn();
                self::replaceAudiences($pdo, $announcementId, $audiences);
            }
        });

        $fresh = self::findByUuid($uuid);
        if ($fresh === null) {
            throw HttpException::server('The announcement could not be loaded.');
        }

        if ($newlyPublished) {
            $audienceList = $audiences ?? self::audienceList($fresh);
            Notifications::createForAudiences(
                $audienceList,
                $fresh['title'],
                $fresh['body'],
                'announcement',
                'announcement',
                $uuid,
                $actorId
            );
        }

        return self::present($fresh, true);
    }

    public static function archive(string $uuid): void
    {
        $updated = Database::execute(
            "UPDATE announcements SET status = 'archived' WHERE uuid = ? AND status <> 'archived'",
            [$uuid]
        );

        if ($updated === 0) {
            throw HttpException::notFound('That announcement was not found or is already archived.');
        }
    }

    public static function present(array $row, bool $includeAdminFields = false): array
    {
        $published = $row['published_at'] ?? $row['created_at'];
        $timestamp = strtotime((string) $published);
        $audiences = self::audienceList($row);

        $payload = [
            'id' => $row['uuid'],
            'title' => $row['title'],
            'body' => $row['body'],
            'tag' => $row['category'],
            'pinned' => (bool) $row['is_pinned'],
            'audiences' => $audiences,
            'published_at' => $row['published_at'],
            'date' => $timestamp !== false ? date('j M Y', $timestamp) : null,
            'day' => $timestamp !== false ? date('j', $timestamp) : null,
            'month' => $timestamp !== false ? date('M', $timestamp) : null,
        ];

        if ($includeAdminFields) {
            $payload['status'] = $row['status'];
            $payload['status_label'] = ucfirst($row['status']);
            $payload['author'] = !empty($row['author_first_name'])
                ? trim($row['author_first_name'] . ' ' . $row['author_last_name'])
                : null;
            $payload['created_at'] = $row['created_at'];
            $payload['updated_at'] = $row['updated_at'];
        }

        return $payload;
    }

    /** @return list<string> */
    private static function validateAudiences(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw HttpException::validation('Choose at least one audience.', [
                'audiences' => 'Select at least one audience.',
            ]);
        }

        $audiences = [];
        foreach ($value as $index => $audience) {
            $audiences[] = Validator::enum(
                $audience,
                "audiences.{$index}",
                self::AUDIENCES
            );
        }

        $audiences = array_values(array_unique($audiences));
        if ($audiences === []) {
            throw HttpException::validation('Choose at least one audience.', [
                'audiences' => 'Select at least one audience.',
            ]);
        }

        return $audiences;
    }

    /** @return list<string> */
    private static function audienceList(array $row): array
    {
        if (empty($row['audiences'])) {
            return [];
        }

        $values = explode(',', (string) $row['audiences']);

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    /** @param list<string> $audiences */
    private static function replaceAudiences(PDO $pdo, int $announcementId, array $audiences): void
    {
        $pdo->prepare('DELETE FROM announcement_audiences WHERE announcement_id = ?')
            ->execute([$announcementId]);

        $insert = $pdo->prepare(
            'INSERT INTO announcement_audiences (announcement_id, audience) VALUES (?, ?)'
        );
        foreach ($audiences as $audience) {
            $insert->execute([$announcementId, $audience]);
        }
    }
}

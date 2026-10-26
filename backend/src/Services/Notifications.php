<?php

declare(strict_types=1);

namespace AlFalaah\Services;

use AlFalaah\Core\Database;
use AlFalaah\Core\HttpException;
use AlFalaah\Core\Uuid;
use PDO;

final class Notifications
{
    public static function listForUser(int $userId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $rows = Database::fetchAll(
            "SELECT uuid, title, body, type, related_type, related_uuid, is_read, read_at, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY is_read ASC, created_at DESC
             LIMIT {$limit}",
            [$userId]
        );

        return array_map(static fn (array $row): array => [
            'id' => $row['uuid'],
            'title' => $row['title'],
            'body' => $row['body'],
            'type' => $row['type'],
            'related_type' => $row['related_type'],
            'related_id' => $row['related_uuid'],
            'is_read' => (bool) $row['is_read'],
            'read_at' => $row['read_at'],
            'created_at' => $row['created_at'],
            'age' => self::relativeTime($row['created_at']),
        ], $rows);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    public static function markRead(int $userId, string $uuid): array
    {
        $notification = Database::fetch(
            'SELECT id, uuid, is_read FROM notifications WHERE user_id = ? AND uuid = ? LIMIT 1',
            [$userId, $uuid]
        );

        if ($notification === null) {
            throw HttpException::notFound('That notification was not found.');
        }

        if (!(bool) $notification['is_read']) {
            Database::execute(
                'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?',
                [(int) $notification['id']]
            );
        }

        return ['id' => $uuid, 'is_read' => true];
    }

    public static function markAllRead(int $userId): int
    {
        return Database::execute(
            'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    /**
     * Fan a published announcement out to every active user in the selected
     * audiences. The author is deliberately excluded.
     *
     * @param list<string> $audiences
     */
    public static function createForAudiences(
        array $audiences,
        string $title,
        string $body,
        string $type,
        ?string $relatedType = null,
        ?string $relatedUuid = null,
        ?int $excludeUserId = null
    ): int {
        $audiences = array_values(array_unique(array_intersect(
            $audiences,
            ['student', 'parent', 'teacher', 'admin']
        )));

        if ($audiences === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($audiences), '?'));
        $sql = "SELECT id FROM users WHERE status = 'active' AND role IN ({$placeholders})";
        $params = $audiences;
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeUserId;
        }

        $users = Database::fetchAll($sql, $params);
        if ($users === []) {
            return 0;
        }

        return (int) Database::transaction(static function (PDO $pdo) use ($users, $title, $body, $type, $relatedType, $relatedUuid): int {
            $statement = $pdo->prepare(
                'INSERT INTO notifications
                    (uuid, user_id, title, body, type, related_type, related_uuid)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($users as $user) {
                $statement->execute([
                    Uuid::v4(),
                    (int) $user['id'],
                    mb_substr($title, 0, 160),
                    mb_substr($body, 0, 500),
                    mb_substr($type, 0, 40),
                    $relatedType,
                    $relatedUuid,
                ]);
            }

            return count($users);
        });
    }

    public static function createForUser(
        int $userId,
        string $title,
        string $body,
        string $type = 'general',
        ?string $relatedType = null,
        ?string $relatedUuid = null
    ): string {
        $uuid = Uuid::v4();
        Database::insert(
            'INSERT INTO notifications (uuid, user_id, title, body, type, related_type, related_uuid)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$uuid, $userId, mb_substr($title, 0, 160), mb_substr($body, 0, 500), $type, $relatedType, $relatedUuid]
        );

        return $uuid;
    }

    private static function relativeTime(string $timestamp): string
    {
        $time = strtotime($timestamp);
        if ($time === false) {
            return '';
        }

        $diff = time() - $time;
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . 'h ago';
        }
        if ($diff < 604800) {
            return (int) floor($diff / 86400) . 'd ago';
        }

        return date('j M', $time);
    }
}

<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class Audit
{
    public static function record(
        string $action,
        ?string $entityType = null,
        ?string $entityUuid = null,
        array $meta = [],
        ?int $userId = null
    ): void {
        try {
            if ($userId === null && Auth::check()) {
                $userId = Auth::id();
            }

            $json = null;
            if ($meta !== []) {
                $encoded = json_encode(
                    $meta,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
                $json = $encoded !== false ? $encoded : null;
            }

            Database::insert(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_uuid, ip, user_agent, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    mb_substr($action, 0, 80),
                    $entityType !== null ? mb_substr($entityType, 0, 60) : null,
                    $entityUuid,
                    Request::ip(),
                    Request::userAgent(),
                    $json,
                ]
            );
        } catch (\Throwable $exception) {
            Logger::warning('Audit log write failed', [
                'action' => $action,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}

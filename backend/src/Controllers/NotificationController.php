<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Auth;
use AlFalaah\Core\Request;
use AlFalaah\Core\Response;
use AlFalaah\Core\Validator;
use AlFalaah\Services\Notifications;

final class NotificationController
{
    public static function index(): never
    {
        $user = Auth::requireUser();
        $limit = (int) (Request::queryScalar('limit') ?? 30);

        Response::ok([
            'unread_count' => Notifications::unreadCount((int) $user['id']),
            'items' => Notifications::listForUser((int) $user['id'], $limit),
        ]);
    }

    public static function read(array $params): never
    {
        $user = Auth::requireUser();
        $uuid = Validator::uuid($params['id'] ?? null, 'id');
        Notifications::markRead((int) $user['id'], $uuid);

        Response::ok([
            'id' => $uuid,
            'unread_count' => Notifications::unreadCount((int) $user['id']),
        ]);
    }

    public static function readAll(): never
    {
        $user = Auth::requireUser();
        $updated = Notifications::markAllRead((int) $user['id']);

        Response::ok([
            'marked' => $updated,
            'unread_count' => 0,
        ]);
    }
}

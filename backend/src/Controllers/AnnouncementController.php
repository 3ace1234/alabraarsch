<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Auth;
use AlFalaah\Core\Request;
use AlFalaah\Core\Response;
use AlFalaah\Services\Announcements;

final class AnnouncementController
{
    public static function index(): never
    {
        $user = Auth::requireUser();
        $limit = (int) (Request::queryScalar('limit') ?? 20);

        Response::ok(Announcements::listForRole($user['role'], $limit));
    }
}

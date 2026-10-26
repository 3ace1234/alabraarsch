<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Auth;
use AlFalaah\Core\Response;
use AlFalaah\Services\Dashboard;

final class DashboardController
{
    public static function show(): never
    {
        $user = Auth::requireUser();

        Response::ok(Dashboard::forUser($user));
    }
}

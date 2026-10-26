<?php

declare(strict_types=1);

namespace AlFalaah\Controllers;

use AlFalaah\Core\Database;
use AlFalaah\Core\Env;
use AlFalaah\Core\Response;
use AlFalaah\Services\Academics;

final class HealthController
{
    public static function show(): never
    {
        $database = 'ok';
        $term = null;

        try {
            Database::fetchValue('SELECT 1');
            $current = Academics::currentTerm();
            $term = $current['name'] . ', ' . $current['session_name'];
        } catch (\Throwable) {
            $database = 'unavailable';
        }

        $healthy = $database === 'ok';
        $payload = [
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $database,
            'term' => $term,
            'time' => date('c'),
            'version' => Env::get('API_VERSION', '1'),
        ];

        Response::json(['data' => $payload], $healthy ? 200 : 503);
    }
}

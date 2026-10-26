<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class Csrf
{
    public static function token(): string
    {
        return Session::csrfToken();
    }

    public static function validate(): void
    {
        $token = Request::header('X-CSRF-Token')
            ?? Request::header('X-XSRF-TOKEN');

        if ($token === null) {
            $body = Request::body();
            $token = isset($body['_csrf']) && is_string($body['_csrf']) ? $body['_csrf'] : null;
        }

        if (!Session::validateCsrf($token)) {
            throw new HttpException(
                403,
                'csrf_failed',
                'Your security token expired. Refresh the page and try again.'
            );
        }
    }
}

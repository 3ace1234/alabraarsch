<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $fields = null,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $fields */
    public static function validation(string $message, array $fields): self
    {
        return new self(422, 'validation_failed', $message, $fields);
    }

    public static function unauthorized(string $message = 'Please sign in to continue.'): self
    {
        return new self(401, 'unauthenticated', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'The requested record was not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function conflict(string $message = 'A record with these details already exists.'): self
    {
        return new self(409, 'conflict', $message);
    }

    public static function badRequest(string $message = 'The request could not be processed.'): self
    {
        return new self(400, 'bad_request', $message);
    }

    public static function tooManyRequests(string $message = 'Too many attempts. Please try again later.', int $retryAfter = 60): self
    {
        return new self(429, 'too_many_requests', $message, null, ['Retry-After' => (string) $retryAfter]);
    }

    public static function server(string $message = 'An unexpected error occurred.'): self
    {
        return new self(500, 'server_error', $message);
    }
}

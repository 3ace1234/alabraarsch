<?php

declare(strict_types=1);

namespace AlFalaah\Core;

/**
 * Dependency-free mail transport.
 *
 * Drivers:
 *   smtp — send through an SMTP server (STARTTLS, implicit TLS or plain)
 *   mail — hand the message to PHP's mail() transport
 *   log  — write a rendered .eml-style entry to storage/logs/mail.log
 *   none — disable outbound mail
 *
 * Transport failures throw RuntimeException; callers decide whether the
 * surrounding workflow may continue (intake submissions always do).
 */
final class Mailer
{
    /**
     * @param array{
     *   to: string|array<int, string>,
     *   subject: string,
     *   text: string,
     *   from?: string,
     *   from_name?: string,
     *   reply_to?: string|null
     * } $message
     */
    public static function send(array $message): bool
    {
        $driver = strtolower(trim(Env::get('MAIL_DRIVER', 'log') ?? 'log'));

        if ($driver === '' || $driver === 'none') {
            return false;
        }

        $message = self::normalise($message);

        match ($driver) {
            'smtp' => self::smtp($message),
            'mail' => self::mail($message),
            'log' => self::log($message),
            default => throw new \RuntimeException("Unsupported MAIL_DRIVER [{$driver}]."),
        };

        return true;
    }

    /**
     * @param array{to: array<int, string>, subject: string, text: string, from: string, from_name: string, reply_to: string|null} $message
     * @return array<string, string>
     */
    private static function headers(array $message, bool $encodedBody): array
    {
        $domain = substr(strrchr($message['from'], '@') ?: '@localhost', 1) ?: 'localhost';
        $headers = [
            'Date' => date('r'),
            'From' => self::address($message['from'], $message['from_name']),
            'To' => implode(', ', array_map(
                static fn (string $address): string => self::address($address),
                $message['to']
            )),
            'Subject' => self::encodeHeader($message['subject']),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ];

        if ($encodedBody) {
            $headers['Content-Transfer-Encoding'] = 'base64';
        }

        if ($message['reply_to'] !== null) {
            $headers['Reply-To'] = self::address($message['reply_to']);
        }

        return $headers;
    }

    /**
     * @param array{to: array<int, string>, subject: string, text: string, from: string, from_name: string, reply_to: string|null} $message
     */
    private static function smtp(array $message): void
    {
        $host = Env::required('MAIL_HOST');
        $port = Env::int('MAIL_PORT', 587);
        $encryption = strtolower(trim(Env::get('MAIL_ENCRYPTION', 'tls') ?? 'tls'));
        $timeout = max(3, Env::int('MAIL_TIMEOUT', 10));

        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new \RuntimeException('MAIL_ENCRYPTION must be tls, ssl or none.');
        }

        $target = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($target, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new \RuntimeException("Could not connect to SMTP server {$target}: {$errstr} ({$errno}).");
        }

        stream_set_timeout($socket, $timeout);

        try {
            self::expect($socket, [220], 'server greeting');

            self::command($socket, 'EHLO ' . self::ehloName(), [250]);

            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                    ? STREAM_CRYPTO_METHOD_TLS_CLIENT
                    : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
                if (@stream_socket_enable_crypto($socket, true, $method) !== true) {
                    throw new \RuntimeException('SMTP STARTTLS negotiation failed.');
                }
                self::command($socket, 'EHLO ' . self::ehloName(), [250]);
            }

            $username = Env::get('MAIL_USERNAME', '') ?? '';
            if ($username !== '') {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($username), [334]);
                self::command($socket, base64_encode(Env::get('MAIL_PASSWORD', '') ?? ''), [235]);
            }

            self::command($socket, 'MAIL FROM:<' . $message['from'] . '>', [250]);
            foreach ($message['to'] as $recipient) {
                self::command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            self::command($socket, 'DATA', [354]);

            $headers = self::headers($message, true);
            $body = chunk_split(base64_encode(self::normaliseBody($message['text'])), 76, "\r\n");
            $data = '';
            foreach ($headers as $name => $value) {
                $data .= $name . ': ' . $value . "\r\n";
            }
            $data .= "\r\n" . $body;

            fwrite($socket, self::dotStuff($data) . "\r\n.\r\n");
            self::expect($socket, [250], 'message acceptance');

            // The message is already accepted; a closed connection here must
            // not turn a successful delivery into a reported failure.
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param array{to: array<int, string>, subject: string, text: string, from: string, from_name: string, reply_to: string|null} $message
     */
    private static function mail(array $message): void
    {
        $headers = self::headers($message, false);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $sent = @mail(
            implode(', ', $message['to']),
            $message['subject'],
            self::normaliseBody($message['text']),
            implode("\r\n", $headerLines)
        );

        if (!$sent) {
            throw new \RuntimeException('PHP mail() reported a failure.');
        }
    }

    /**
     * @param array{to: array<int, string>, subject: string, text: string, from: string, from_name: string, reply_to: string|null} $message
     */
    private static function log(array $message): void
    {
        $headers = self::headers($message, false);

        $entry = '';
        foreach ($headers as $name => $value) {
            $entry .= $name . ': ' . $value . "\r\n";
        }
        $entry .= "\r\n" . self::normaliseBody($message['text']) . "\r\n";

        $path = ALFALAAH_STORAGE . '/logs/mail.log';
        $record = '===== ' . date('c') . " =====\r\n" . $entry . "\r\n";
        if (@file_put_contents($path, $record, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the mail log.');
        }
    }

    /**
     * @param array<string, mixed> $message
     * @return array{to: array<int, string>, subject: string, text: string, from: string, from_name: string, reply_to: string|null}
     */
    private static function normalise(array $message): array
    {
        $to = $message['to'] ?? [];
        $to = is_array($to) ? $to : [$to];
        $to = array_values(array_unique(array_filter(array_map(
            static fn ($address): string => trim((string) $address),
            $to
        ))));

        if ($to === []) {
            throw new \InvalidArgumentException('A mail message needs at least one recipient.');
        }

        foreach ($to as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid mail recipient address.');
            }
        }

        $from = trim((string) ($message['from'] ?? Env::get('MAIL_FROM_ADDRESS', '')));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('MAIL_FROM_ADDRESS is missing or invalid.');
        }

        $replyTo = isset($message['reply_to']) ? trim((string) $message['reply_to']) : '';
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = '';
        }

        return [
            'to' => $to,
            'subject' => self::safeHeader((string) ($message['subject'] ?? '')),
            'text' => (string) ($message['text'] ?? ''),
            'from' => $from,
            'from_name' => self::safeHeader((string) ($message['from_name'] ?? Env::get('MAIL_FROM_NAME', 'Al-Falaah School'))),
            'reply_to' => $replyTo !== '' ? $replyTo : null,
        ];
    }

    private static function safeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private static function address(string $address, string $name = ''): string
    {
        if ($name === '') {
            return $address;
        }

        return self::encodeHeader($name) . ' <' . $address . '>';
    }

    private static function encodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\x80-\xFF]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }

    private static function normaliseBody(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private static function dotStuff(string $data): string
    {
        return preg_replace('/^\./m', '..', $data) ?? $data;
    }

    private static function ehloName(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * @param resource $socket
     * @param array<int, int> $expected
     */
    private static function command($socket, string $command, array $expected): void
    {
        fwrite($socket, $command . "\r\n");
        self::expect($socket, $expected, $command);
    }

    /**
     * @param resource $socket
     * @param array<int, int> $expected
     */
    private static function expect($socket, array $expected, string $context): void
    {
        $code = 0;
        $lines = [];

        while (($line = fgets($socket)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            $code = (int) substr($line, 0, 3);
            if (!isset($line[3]) || $line[3] === ' ') {
                break;
            }
        }

        if ($line === false && $lines === []) {
            throw new \RuntimeException("SMTP {$context}: connection closed before a response.");
        }

        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException(
                "SMTP {$context} failed: " . implode(' | ', $lines)
            );
        }
    }
}

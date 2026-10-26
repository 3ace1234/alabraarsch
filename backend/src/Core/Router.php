<?php

declare(strict_types=1);

namespace AlFalaah\Core;

final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: callable, options: array}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('GET', $pattern, $handler, $options);
    }

    public function post(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('POST', $pattern, $handler, $options);
    }

    public function put(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('PUT', $pattern, $handler, $options);
    }

    public function patch(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('PATCH', $pattern, $handler, $options);
    }

    public function delete(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('DELETE', $pattern, $handler, $options);
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $path = $this->normalisePath($path);
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $allowed[$route['method']] = true;
            if ($route['method'] !== $method) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            $options = $route['options'];
            if (($options['auth'] ?? true) === true) {
                $user = Auth::requireUser();
                $roles = $options['roles'] ?? null;
                if ($roles !== null) {
                    $roles = is_array($roles) ? $roles : [$roles];
                    if (!in_array($user['role'], $roles, true)) {
                        throw HttpException::forbidden();
                    }
                }
            }

            if (
                in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && ($options['csrf'] ?? true) === true
            ) {
                Csrf::validate();
            }

            ($route['handler'])($params);
            return;
        }

        if ($allowed !== []) {
            header('Allow: ' . implode(', ', array_keys($allowed)));
            throw new HttpException(405, 'method_not_allowed', 'That method is not supported for this endpoint.');
        }

        throw HttpException::notFound('The requested API endpoint does not exist.');
    }

    private function add(string $method, string $pattern, callable $handler, array $options): void
    {
        $pattern = $this->normalisePath($pattern);
        $parts = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';

        foreach ($parts ?: [] as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $match)) {
                $regex .= '(?P<' . $match[1] . '>[^/]+)';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'options' => $options,
        ];
    }

    private function normalisePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}

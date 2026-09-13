<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var (callable(): string)|null  test seam for php://input */
    public static $rawBodyProvider = null;

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     * @param array<string,mixed>  $files
     * @param array<string,string> $cookies
     * @param array<string,string> $attributes
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $files = [],
        public readonly array $cookies = [],
        public readonly array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($k, 5))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $body = $_POST;
        $contentType = $headers['CONTENT-TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = self::$rawBodyProvider ? (self::$rawBodyProvider)() : (string) file_get_contents('php://input');
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        return new self($method, $path, $_GET, $body, $headers, $_FILES, $_COOKIE);
    }

    public function header(string $name): ?string
    {
        $name = strtoupper($name);
        foreach ($this->headers as $k => $v) {
            if (strtoupper($k) === $name) {
                return $v;
            }
        }
        return null;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function attribute(string $key): string
    {
        if (!isset($this->attributes[$key])) {
            throw new \OutOfBoundsException("Missing route attribute '$key'");
        }
        return $this->attributes[$key];
    }

    /** @param array<string,string> $attributes */
    public function withAttributes(array $attributes): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $this->headers, $this->files, $this->cookies, $attributes + $this->attributes);
    }

    public function isXhr(): bool
    {
        return strcasecmp((string) $this->header('X-Requested-With'), 'XMLHttpRequest') === 0;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }
}

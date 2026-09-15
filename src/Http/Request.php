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
        public readonly string $ip = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = self::stripBasePath($path, $_SERVER['SCRIPT_NAME'] ?? '');
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
        return new self($method, $path, $_GET, $body, $headers, $_FILES, $_COOKIE, ip: self::resolveIp());
    }

    /**
     * Fly.io terminates TLS at its edge and forwards the real client IP in `Fly-Client-IP`; that
     * header cannot be spoofed by the client since Fly overwrites it. Falls back to the first
     * X-Forwarded-For hop, then REMOTE_ADDR, for local/XAMPP runs with no such proxy in front.
     */
    private static function resolveIp(): string
    {
        $flyIp = trim((string) ($_SERVER['HTTP_FLY_CLIENT_IP'] ?? ''));
        if ($flyIp !== '') {
            return $flyIp;
        }
        $forwardedFor = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwardedFor !== '') {
            return trim(explode(',', $forwardedFor)[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * Strips the deployment base path (the directory the front controller lives in) from the
     * request path, so routing works whether the app is served from a vhost root (SCRIPT_NAME
     * "/index.php") or a sub-folder install, e.g. XAMPP htdocs (SCRIPT_NAME "/sub/public/index.php").
     *
     * The repo-root .htaccess rewrites a sub-folder request internally into public/ without
     * changing REQUEST_URI (Apache leaves REQUEST_URI as the original client request line), so the
     * request path (e.g. "/tm/api/x") may be missing the "/public" segment that SCRIPT_NAME has
     * (e.g. "/tm/public/index.php") — while a request that already targets public/ directly (or the
     * PHP built-in server, which has no .htaccess) keeps it. Try both candidate bases, longest first,
     * and only strip on a segment boundary so "/sub" cannot swallow the start of "/subway/...".
     *
     * A base path can only be read off a SCRIPT_NAME that names the PHP front controller. The PHP
     * built-in server sets SCRIPT_NAME to the request path whenever its last segment looks like a
     * file, so without this guard "/media/<uuid>.png" would lose its "/media" prefix.
     */
    private static function stripBasePath(string $path, string $scriptName): string
    {
        if (!str_ends_with(strtolower($scriptName), '.php')) {
            return $path;
        }
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $candidates = [];
        if ($scriptDir !== '' && $scriptDir !== '/') {
            $candidates[] = $scriptDir;
            if (str_ends_with($scriptDir, '/public')) {
                $candidates[] = substr($scriptDir, 0, -strlen('/public'));
            }
        }
        foreach ($candidates as $base) {
            if ($base === '' || $base === '/') {
                continue;
            }
            if ($path === $base) {
                return '/';
            }
            if (str_starts_with($path, $base . '/')) {
                return substr($path, strlen($base));
            }
        }
        return $path;
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
        return new self($this->method, $this->path, $this->query, $this->body, $this->headers, $this->files, $this->cookies, $attributes + $this->attributes, $this->ip);
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

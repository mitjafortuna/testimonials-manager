<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Support\Env;
use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    private string $cookieJar;

    protected function setUp(): void
    {
        $this->cookieJar = sys_get_temp_dir() . '/tm-cookies-' . str_replace('\\', '_', static::class) . '.txt';
    }

    protected function baseUrl(): string
    {
        return rtrim(Env::get('API_BASE_URL', 'http://localhost') ?? 'http://localhost', '/');
    }

    /**
     * @param array<string,mixed>|null $json
     * @param array<string,string>     $headers
     * @return array{status:int,json:mixed,headers:array<string,string>}
     */
    protected function request(string $method, string $path, ?array $json = null, array $headers = [], bool $xhr = true): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        $hdrs = ['Accept: application/json'];
        if ($xhr) {
            $hdrs[] = 'X-Requested-With: XMLHttpRequest';
        }
        foreach ($headers as $k => $v) {
            $hdrs[] = "$k: $v";
        }
        if ($json !== null) {
            $hdrs[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_THROW_ON_ERROR));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            self::fail('curl: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);
        $parsed = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'json' => $body === '' ? null : json_decode($body, true), 'headers' => $parsed];
    }

    /**
     * Uploads files via multipart; $files = ['images[]' => '/abs/path.jpg', ...].
     *
     * @param array<string,string> $files
     * @param array<string,string> $fields
     * @return array{status:int,json:mixed,headers:array<string,string>}
     */
    protected function upload(string $path, array $files, array $fields = []): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        $post = $fields;
        foreach ($files as $field => $file) {
            $post[$field] = new \CURLFile($file);
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => $body === '' || $body === false ? null : json_decode($body, true), 'headers' => []];
    }
}

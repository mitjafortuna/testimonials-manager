<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

use App\Domain\Exception\UpstreamException;

final class CurlTransport implements HttpTransportInterface
{
    public function __construct(private readonly int $timeoutSeconds = 20)
    {
    }

    public function get(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new UpstreamException("Upstream request failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }
}

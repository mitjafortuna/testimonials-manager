<?php

declare(strict_types=1);

namespace App\Infrastructure\RateLimit;

use App\Support\Clock;

/**
 * Fixed-window per-key counter backed by one small file per key, guarded with flock so concurrent
 * requests on the same key still count correctly. State lives on local (ephemeral) disk, not the
 * uploads volume — a machine restart resetting counters is fine for rate limiting.
 */
class FileRateLimiter
{
    public function __construct(private readonly string $dir, private readonly Clock $clock)
    {
    }

    public function tooManyAttempts(string $key, int $limit, int $windowSeconds): bool
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Rate limit directory '{$this->dir}' is not writable");
        }

        $path = $this->dir . '/' . hash('sha256', $key) . '.json';
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open rate limit file '$path'");
        }

        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $data = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
            $windowStart = is_array($data) ? (int) ($data['windowStart'] ?? 0) : 0;
            $count = is_array($data) ? (int) ($data['count'] ?? 0) : 0;

            $now = $this->clock->now()->getTimestamp();
            if ($now - $windowStart >= $windowSeconds) {
                $windowStart = $now;
                $count = 0;
            }
            $count++;

            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode(['windowStart' => $windowStart, 'count' => $count], JSON_THROW_ON_ERROR));
            fflush($fh);

            return $count > $limit;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}

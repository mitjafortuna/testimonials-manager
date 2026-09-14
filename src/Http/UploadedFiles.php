<?php

declare(strict_types=1);

namespace App\Http;

final class UploadedFiles
{
    /**
     * Flattens PHP's $_FILES shape (single or `field[]`) into a list of upload entries, skipping "no file" slots.
     *
     * @param array<string,mixed> $files
     * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function normalize(array $files, string $field): array
    {
        if (!isset($files[$field]) || !is_array($files[$field])) {
            return [];
        }
        $f = $files[$field];
        $names = is_array($f['name'] ?? null) ? $f['name'] : [$f['name'] ?? ''];
        $out = [];
        foreach (array_keys($names) as $i) {
            $pick = static fn (string $k): mixed => is_array($f[$k] ?? null) ? ($f[$k][$i] ?? null) : ($f[$k] ?? null);
            $error = (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => (string) $pick('name'),
                'type' => (string) $pick('type'),
                'tmp_name' => (string) $pick('tmp_name'),
                'error' => $error,
                'size' => (int) $pick('size'),
            ];
        }
        return $out;
    }
}

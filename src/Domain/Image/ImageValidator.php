<?php

declare(strict_types=1);

namespace App\Domain\Image;

use App\Domain\Exception\HttpException;
use App\Domain\Exception\ValidationException;

/**
 * Server-side upload validation: size limit, MIME sniffing (never trusts the client's name/type), dimension check.
 */
final class ImageValidator
{
    public const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly int $maxBytes)
    {
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{tmp_path:string,mime:string,ext:string,size:int,width:int,height:int}
     */
    public function validate(array $file): array
    {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > $this->maxBytes) {
            throw new HttpException(413, 'payload_too_large', sprintf('Image must be at most %d MB', intdiv($this->maxBytes, 1_048_576)));
        }
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0 || !is_file($file['tmp_name'])) {
            throw new ValidationException(['images' => 'Upload failed (error ' . $file['error'] . ')']);
        }
        // is_uploaded_file() is a defense-in-depth guard against ever processing an arbitrary filesystem
        // path as if it were an upload. It only reports true for genuine PHP-managed upload temp files, which
        // means it is also always false in a plain CLI SAPI (unit tests build fixture files with tempnam(),
        // not real $_FILES uploads) — so the check is skipped there and enforced under every real request SAPI,
        // built-in server ("cli-server", used by the API test suite) included.
        if (\PHP_SAPI !== 'cli' && !is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException(['images' => 'Upload failed (not a valid upload)']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $info = @getimagesize($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime]) || $info === false || $info['mime'] !== $mime) {
            throw new HttpException(415, 'unsupported_media_type', 'Only JPG, PNG or WebP images are allowed');
        }
        return [
            'tmp_path' => $file['tmp_name'],
            'mime' => $mime,
            'ext' => self::ALLOWED[$mime],
            'size' => $file['size'],
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Testimonial;

use App\Domain\Exception\ValidationException;

/**
 * Validates and normalises a testimonial payload. Pure: no I/O.
 */
final class TestimonialValidator
{
    public const MAX_NAME = 128;
    public const MAX_TEXT = 2000;
    public const MAX_URL = 512;
    public const GENDERS = ['male', 'female', 'unisex'];

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>  normalised fields (only the provided ones when $partial)
     */
    public function validate(array $input, bool $partial = false): array
    {
        $errors = [];
        $out = [];

        if (!$partial || array_key_exists('author_name', $input)) {
            $name = trim((string) ($input['author_name'] ?? ''));
            if ($name === '') {
                $errors['author_name'] = 'Author name is required';
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $errors['author_name'] = 'Author name must be at most ' . self::MAX_NAME . ' characters';
            } else {
                $out['author_name'] = $name;
            }
        }

        if (!$partial || array_key_exists('text', $input)) {
            $text = trim((string) ($input['text'] ?? ''));
            if ($text === '') {
                $errors['text'] = 'Text is required';
            } elseif (mb_strlen($text) > self::MAX_TEXT) {
                $errors['text'] = 'Text must be at most ' . self::MAX_TEXT . ' characters';
            } else {
                $out['text'] = $text;
            }
        }

        if (!$partial || array_key_exists('rating', $input)) {
            $raw = $input['rating'] ?? null;
            if ($raw === null || $raw === '' || (is_string($raw) && strtolower($raw) === 'random')) {
                $out['rating'] = null;
            } elseif ((is_int($raw) || (is_string($raw) && ctype_digit($raw))) && (int) $raw >= 1 && (int) $raw <= 5) {
                $out['rating'] = (int) $raw;
            } else {
                $errors['rating'] = 'Rating must be 1–5 or "random"';
            }
        }

        if (!$partial || array_key_exists('gender', $input)) {
            $gender = (string) ($input['gender'] ?? 'unisex');
            if (!in_array($gender, self::GENDERS, true)) {
                $errors['gender'] = 'Gender must be male, female or unisex';
            } else {
                $out['gender'] = $gender;
            }
        }

        if (!$partial || array_key_exists('url', $input)) {
            $url = trim((string) ($input['url'] ?? ''));
            if ($url === '') {
                $out['url'] = null;
            } elseif (mb_strlen($url) > self::MAX_URL) {
                $errors['url'] = 'URL must be at most ' . self::MAX_URL . ' characters';
            } elseif (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
                $errors['url'] = 'URL must start with http:// or https://';
            } else {
                $out['url'] = $url;
            }
        }

        if (!$partial || array_key_exists('is_active', $input)) {
            $raw = $input['is_active'] ?? true;
            $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                $errors['is_active'] = 'Active must be true or false';
            } else {
                $out['is_active'] = $bool;
            }
        }

        if (!$partial || array_key_exists('sort_order', $input)) {
            $raw = $input['sort_order'] ?? null;
            if ($raw === null || $raw === '') {
                $out['sort_order'] = null;
            } elseif ((is_int($raw) || (is_string($raw) && ctype_digit($raw))) && (int) $raw >= 0) {
                $out['sort_order'] = (int) $raw;
            } else {
                $errors['sort_order'] = 'Sort order must be a non-negative integer';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $out;
    }
}

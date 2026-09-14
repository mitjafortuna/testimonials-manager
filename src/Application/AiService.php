<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Ai\ProviderRegistry;
use App\Domain\Exception\ValidationException;
use App\Domain\Testimonial\TestimonialValidator;

final class AiService
{
    public function __construct(private readonly ProviderRegistry $providers)
    {
    }

    /** @return list<array{id:string,name:string}> */
    public function listProviders(): array
    {
        return $this->providers->all();
    }

    /** @return array{text:string} */
    public function translate(string $providerId, string $text, string $targetCountry): array
    {
        $errors = $this->validateProvider($providerId);
        $text = trim($text);
        if ($text === '') {
            $errors['text'] = 'Text is required';
        }
        $country = strtoupper(trim($targetCountry));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $errors['target_country'] = 'Target country must be a 2-letter code';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['text' => $this->providers->get($providerId)->translate($text, $country)];
    }

    /** @return array{name:string} */
    public function authorName(string $providerId, string $country, string $gender): array
    {
        $errors = $this->validateProvider($providerId);
        $countryU = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $countryU)) {
            $errors['country'] = 'Country must be a 2-letter code';
        }
        if (!in_array($gender, TestimonialValidator::GENDERS, true)) {
            $errors['gender'] = 'Gender must be male, female or unisex';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['name' => $this->providers->get($providerId)->authorName($countryU, $gender)];
    }

    /** @return array<string,string> */
    private function validateProvider(string $providerId): array
    {
        return $this->providers->has($providerId) ? [] : ['provider' => 'Unknown provider'];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class AnonymousUser implements CurrentUser
{
    public function id(): ?int
    {
        return null;
    }

    public function displayName(): ?string
    {
        return null;
    }
}

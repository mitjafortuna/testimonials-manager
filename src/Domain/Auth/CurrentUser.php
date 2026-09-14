<?php

declare(strict_types=1);

namespace App\Domain\Auth;

interface CurrentUser
{
    public function id(): ?int;

    public function displayName(): ?string;
}

<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;

final class HomeController
{
    public function __construct(private readonly string $publicDir)
    {
    }

    public function index(Request $request): Response
    {
        return Response::html((string) file_get_contents($this->publicDir . '/index.html'));
    }
}

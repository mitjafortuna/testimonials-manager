<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\AuthService;
use App\Domain\Exception\UnauthorizedException;
use App\Http\Request;
use App\Http\Response;

final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function login(Request $request): Response
    {
        $user = $this->auth->login((string) $request->input('username', ''), (string) $request->input('password', ''));
        return Response::json(['user' => $user]);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::noContent();
    }

    public function me(Request $request): Response
    {
        $user = $this->auth->current();
        if ($user === null) {
            throw new UnauthorizedException();
        }
        return Response::json(['user' => $user]);
    }
}

<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Exception\UnauthorizedException;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Auth\SessionAuth;
use App\Infrastructure\Repository\UserRepository;

final class AuthService
{
    public function __construct(private readonly UserRepository $users, private readonly SessionAuth $session)
    {
    }

    /** @return array{id:int,username:string,display_name:string} */
    public function login(string $username, string $password): array
    {
        $errors = [];
        if (trim($username) === '') {
            $errors['username'] = 'Username is required';
        }
        if ($password === '') {
            $errors['password'] = 'Password is required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $user = $this->users->findByUsername(trim($username));
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw new UnauthorizedException('Invalid username or password', 'invalid_credentials');
        }
        $this->session->login($user['id'], $user['display_name']);
        return ['id' => $user['id'], 'username' => $user['username'], 'display_name' => $user['display_name']];
    }

    public function logout(): void
    {
        $this->session->logout();
    }

    /** @return array{id:int,username:string,display_name:string}|null */
    public function current(): ?array
    {
        $id = $this->session->id();
        return $id === null ? null : $this->users->find($id);
    }
}

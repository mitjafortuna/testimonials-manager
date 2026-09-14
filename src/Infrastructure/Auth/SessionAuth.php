<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Auth\CurrentUser;

/**
 * PHP-session-backed identity. Cookie: HttpOnly, SameSite=Lax, Secure on HTTPS.
 */
class SessionAuth implements CurrentUser
{
    private const KEY_ID = 'user_id';
    private const KEY_NAME = 'user_name';

    public function __construct(private readonly string $sessionName)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        session_name($this->sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        ]);
        session_start();
    }

    public function login(int $id, string $displayName): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION[self::KEY_ID] = $id;
        $_SESSION[self::KEY_NAME] = $displayName;
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
            session_destroy();
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->id() !== null;
    }

    public function id(): ?int
    {
        $this->start();
        return isset($_SESSION[self::KEY_ID]) ? (int) $_SESSION[self::KEY_ID] : null;
    }

    public function displayName(): ?string
    {
        $this->start();
        return isset($_SESSION[self::KEY_NAME]) ? (string) $_SESSION[self::KEY_NAME] : null;
    }
}

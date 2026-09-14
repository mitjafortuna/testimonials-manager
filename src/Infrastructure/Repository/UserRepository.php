<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class UserRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return array{id:int,username:string,password_hash:string,display_name:string}|null */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash, display_name FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        // MySQL's default collation (utf8mb4_unicode_ci) is case-insensitive and pads/trims
        // trailing whitespace for equality, so re-check the exact value in PHP: usernames must
        // match byte-for-byte, both to avoid confusable-login surprises and to keep behaviour
        // stable regardless of the column's collation.
        if ($row === false || (string) $row['username'] !== $username) {
            return null;
        }
        return ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'password_hash' => (string) $row['password_hash'], 'display_name' => (string) $row['display_name']];
    }

    /** @return array{id:int,username:string,display_name:string}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, display_name FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'display_name' => (string) $row['display_name']];
    }
}

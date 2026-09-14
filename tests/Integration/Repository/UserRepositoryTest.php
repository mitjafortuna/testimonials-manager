<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\UserRepository;
use Tests\Integration\DatabaseTestCase;

final class UserRepositoryTest extends DatabaseTestCase
{
    public function testFindByUsernameAndFind(): void
    {
        $id = self::insert('users', ['username' => 'admin', 'password_hash' => password_hash('pw', PASSWORD_DEFAULT), 'display_name' => 'Demo Admin']);
        $repo = new UserRepository(self::$pdo);
        $u = $repo->findByUsername('admin');
        self::assertSame($id, $u['id']);
        self::assertTrue(password_verify('pw', $u['password_hash']));
        self::assertNull($repo->findByUsername('ADMIN '));
        self::assertSame(['id' => $id, 'username' => 'admin', 'display_name' => 'Demo Admin'], $repo->find($id));
        self::assertNull($repo->find(999));
    }
}

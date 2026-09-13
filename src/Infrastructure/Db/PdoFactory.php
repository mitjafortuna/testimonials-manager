<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

final class PdoFactory
{
    /** @param array{host:string,port:int,name:string,user:string,pass:string} $db */
    public static function create(array $db): \PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
        return new \PDO($dsn, $db['user'], $db['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
        ]);
    }
}

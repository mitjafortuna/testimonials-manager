<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class SyncRunRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function start(string $startedAt): int
    {
        $this->pdo->prepare('INSERT INTO sync_runs (started_at, status) VALUES (?, ?)')->execute([$startedAt, 'running']);
        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $id, string $status, string $finishedAt, int $added, int $updated, int $removed, ?string $error): void
    {
        $this->pdo->prepare('UPDATE sync_runs SET status = ?, finished_at = ?, added = ?, updated = ?, removed = ?, error_message = ? WHERE id = ?')
            ->execute([$status, $finishedAt, $added, $updated, $removed, $error, $id]);
    }

    /** @return array<string,mixed>|null */
    public function last(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1')->fetch();
        if ($row === false) {
            return null;
        }
        foreach (['id', 'added', 'updated', 'removed'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        return $row;
    }
}

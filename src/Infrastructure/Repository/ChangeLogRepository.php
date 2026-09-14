<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class ChangeLogRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @param array<string,mixed>|null $changes */
    public function record(string $entityType, int $entityId, string $action, ?array $changes, ?int $userId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO change_log (entity_type, entity_id, action, changes, user_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$entityType, $entityId, $action, $changes === null ? null : json_encode($changes, JSON_THROW_ON_ERROR), $userId]);
    }

    /** @return list<array{id:int,action:string,changes:?array<string,mixed>,user_id:?int,user_name:?string,created_at:string}> */
    public function listForEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, u.display_name FROM change_log c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.entity_type = ? AND c.entity_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT ?',
        );
        $stmt->bindValue(1, $entityType);
        $stmt->bindValue(2, $entityId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'], 'action' => (string) $r['action'],
                'changes' => $r['changes'] === null ? null : json_decode((string) $r['changes'], true),
                'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
                'user_name' => $r['display_name'] === null ? null : (string) $r['display_name'],
                'created_at' => (string) $r['created_at'],
            ];
        }, $stmt->fetchAll());
    }
}

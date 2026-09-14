<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;

final class HealthController
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function show(Request $request): Response
    {
        try {
            $db = $this->pdo->query('SELECT 1')->fetchColumn() === 1;
        } catch (\Throwable) {
            $db = false;
        }
        return Response::json(['status' => $db ? 'ok' : 'degraded', 'db' => $db], $db ? 200 : 503);
    }
}

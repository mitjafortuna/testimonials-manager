<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\LandingSyncService;
use App\Domain\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Repository\SyncRunRepository;

final class SyncController
{
    public function __construct(private readonly LandingSyncService $sync, private readonly SyncRunRepository $runs)
    {
    }

    public function run(Request $request): Response
    {
        return Response::json(['run' => $this->sync->run()]);
    }

    public function last(Request $request): Response
    {
        $run = $this->runs->last();
        if ($run === null) {
            throw new NotFoundException('No sync has run yet');
        }
        return Response::json(['run' => $run]);
    }
}

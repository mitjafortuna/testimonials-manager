<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\AiService;
use App\Http\Request;
use App\Http\Response;

final class AiController
{
    public function __construct(private readonly AiService $service)
    {
    }

    public function providers(Request $request): Response
    {
        return Response::json(['data' => $this->service->listProviders()]);
    }

    public function translate(Request $request): Response
    {
        return Response::json($this->service->translate(
            (string) $request->input('provider', ''),
            (string) $request->input('text', ''),
            (string) $request->input('target_country', ''),
        ));
    }

    public function authorName(Request $request): Response
    {
        return Response::json($this->service->authorName(
            (string) $request->input('provider', ''),
            (string) $request->input('country', ''),
            (string) $request->input('gender', 'unisex'),
        ));
    }
}

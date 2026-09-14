<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\ProductSearchService;
use App\Http\Request;
use App\Http\Response;

final class ProductController
{
    public function __construct(private readonly ProductSearchService $search)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json($this->search->search($request->query));
    }
}

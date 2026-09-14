<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\LandingOverviewService;
use App\Application\ProductSearchService;
use App\Http\Request;
use App\Http\Response;

final class ProductController
{
    public function __construct(
        private readonly ProductSearchService $search,
        private readonly LandingOverviewService $overview,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::json($this->search->search($request->query));
    }

    public function landings(Request $request): Response
    {
        return Response::json($this->overview->forProduct($request->attribute('sku')));
    }
}

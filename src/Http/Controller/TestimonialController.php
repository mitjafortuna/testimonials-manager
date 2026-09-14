<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\TestimonialService;
use App\Domain\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;

final class TestimonialController
{
    public function __construct(private readonly TestimonialService $service)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json($this->service->listForLanding(self::id($request, 'id')));
    }

    public function store(Request $request): Response
    {
        return Response::json(['testimonial' => $this->service->create(self::id($request, 'id'), $request->body)], 201);
    }

    public function show(Request $request): Response
    {
        return Response::json(['testimonial' => $this->service->get(self::id($request, 'id'))]);
    }

    public function update(Request $request): Response
    {
        return Response::json(['testimonial' => $this->service->update(self::id($request, 'id'), $request->body)]);
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete(self::id($request, 'id'));
        return Response::noContent();
    }

    /** Route ids must be positive integers; anything else is a 404, not a 500. */
    public static function id(Request $request, string $name): int
    {
        $raw = $request->attribute($name);
        if (!ctype_digit($raw) || $raw === '0') {
            throw new NotFoundException('Not found');
        }
        return (int) $raw;
    }
}

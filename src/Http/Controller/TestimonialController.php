<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\TestimonialService;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
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

    public function reorder(Request $request): Response
    {
        $raw = $request->input('ids');
        if (!is_array($raw)) {
            throw new ValidationException(['ids' => 'Must be an array of testimonial ids']);
        }
        return Response::json($this->service->reorder(self::id($request, 'id'), array_map('intval', $raw)));
    }

    public function bulk(Request $request): Response
    {
        $raw = $request->input('ids');
        if (!is_array($raw)) {
            throw new ValidationException(['ids' => 'Must be an array of testimonial ids']);
        }
        return Response::json($this->service->bulkUpdate(self::id($request, 'id'), array_map('intval', $raw), (string) $request->input('action', '')));
    }

    public function copyPreview(Request $request): Response
    {
        return Response::json($this->service->copyPreview(
            self::id($request, 'id'),
            (int) $request->query('source_landing_id', 0),
            (string) $request->query('mode', 'append'),
        ));
    }

    public function copy(Request $request): Response
    {
        return Response::json($this->service->copy(
            self::id($request, 'id'),
            (int) $request->input('source_landing_id', 0),
            (string) $request->input('mode', 'append'),
        ));
    }

    public function history(Request $request): Response
    {
        return Response::json(['data' => $this->service->history(self::id($request, 'id'))]);
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

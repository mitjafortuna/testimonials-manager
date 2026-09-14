<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\ImageService;
use App\Domain\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\UploadedFiles;

final class ImageController
{
    public function __construct(private readonly ImageService $service)
    {
    }

    public function store(Request $request): Response
    {
        $files = UploadedFiles::normalize($request->files, 'images');
        $crop = $request->input('crop');
        $convertWebp = filter_var($request->input('convert_webp', false), FILTER_VALIDATE_BOOLEAN);
        return Response::json(['images' => $this->service->upload(
            TestimonialController::id($request, 'id'),
            $files,
            $crop === null || $crop === '' ? null : (string) $crop,
            $convertWebp,
        )], 201);
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete(TestimonialController::id($request, 'id'));
        return Response::noContent();
    }

    public function reorder(Request $request): Response
    {
        $raw = $request->input('ids');
        if (!is_array($raw)) {
            throw new ValidationException(['ids' => 'Must be an array of image ids']);
        }
        return Response::json(['images' => $this->service->reorder(TestimonialController::id($request, 'id'), array_map('intval', $raw))]);
    }
}

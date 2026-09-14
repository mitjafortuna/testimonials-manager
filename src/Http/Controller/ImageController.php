<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\ImageService;
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
        return Response::json(['images' => $this->service->upload(TestimonialController::id($request, 'id'), $files)], 201);
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete(TestimonialController::id($request, 'id'));
        return Response::noContent();
    }
}

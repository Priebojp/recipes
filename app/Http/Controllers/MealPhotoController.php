<?php

namespace App\Http\Controllers;

use App\Models\MealAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the working photo of a meal analysis to its owner only. The file lives on a private disk and has no
 * public URL; anyone else gets 403, a removed photo 404.
 */
class MealPhotoController extends Controller
{
    public function __invoke(Request $request, MealAnalysis $analysis): BinaryFileResponse
    {
        Gate::authorize('view', $analysis);

        $media = $analysis->hasPhoto() ? $analysis->photo() : null;
        abort_if($media === null, 404);

        $path = $media->getPath();
        abort_unless(is_file($path), 404);

        // BinaryFileResponse marks files public by default; a personal photo must never land in a shared cache.
        return response()->file($path, ['Content-Type' => $media->mime_type, 'Cache-Control' => 'no-store'])->setPrivate();
    }
}

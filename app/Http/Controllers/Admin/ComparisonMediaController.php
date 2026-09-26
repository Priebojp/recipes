<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiJob;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the images of one comparison run to the administrator rating them (v2.1 stage 8). Only media produced by a
 * job of that run is reachable here – no other recipe image of any household. Sits behind the admin middleware
 * (role, MFA, password confirmation).
 */
class ComparisonMediaController extends Controller
{
    public function __invoke(Request $request, string $run, Media $media, string $conversion = ''): BinaryFileResponse
    {
        $belongs = AiJob::query()
            ->where('input->comparison_run', $run)
            ->where('result_media_id', $media->id)
            ->exists();
        abort_unless($belongs, 404);

        if ($conversion !== '' && ! $media->hasGeneratedConversion($conversion)) {
            $conversion = '';
        }

        $path = $media->getPath($conversion);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=3600',
            'Content-Type' => $conversion === '' ? $media->mime_type : 'image/jpeg',
        ]);
    }
}

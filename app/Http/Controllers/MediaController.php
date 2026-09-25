<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Support\CurrentHousehold;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves private household images after checking the viewer belongs to the same household.
 */
class MediaController extends Controller
{
    public function __invoke(Request $request, CurrentHousehold $current, Media $media, string $conversion = ''): BinaryFileResponse
    {
        $householdId = match ($media->model_type) {
            (new Recipe)->getMorphClass() => Recipe::query()->whereKey($media->model_id)->value('household_id'),
            (new RecipeStep)->getMorphClass() => Recipe::query()->whereKey(RecipeStep::query()->whereKey($media->model_id)->value('recipe_id'))->value('household_id'),
            default => null,
        };

        abort_unless($householdId !== null && $current->has() && (int) $householdId === $current->id(), 404);

        if ($conversion !== '' && ! $media->hasGeneratedConversion($conversion)) {
            $conversion = '';
        }

        $path = $media->getPath($conversion);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=86400',
            'Content-Type' => $conversion === '' ? $media->mime_type : 'image/jpeg',
        ]);
    }
}

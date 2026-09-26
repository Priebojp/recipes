<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Support\CurrentHousehold;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves recipe images. Images of published recipes are public; everything else requires
 * a viewer from the same household.
 */
class MediaController extends Controller
{
    public function __invoke(Request $request, CurrentHousehold $current, Media $media, string $conversion = ''): BinaryFileResponse
    {
        $recipe = $this->recipeFor($media);

        abort_if($recipe === null, 404);

        $public = $recipe->isPublic();

        abort_unless($public || ($current->has() && $recipe->household_id === $current->id()), 404);

        if ($conversion !== '' && ! $media->hasGeneratedConversion($conversion)) {
            $conversion = '';
        }

        $path = $media->getPath($conversion);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => $public ? 'public, max-age=86400' : 'private, max-age=86400',
            'Content-Type' => $conversion === '' ? $media->mime_type : 'image/jpeg',
        ]);
    }

    private function recipeFor(Media $media): ?Recipe
    {
        $recipeId = match ($media->model_type) {
            (new Recipe)->getMorphClass() => $media->model_id,
            (new RecipeStep)->getMorphClass() => RecipeStep::query()->whereKey($media->model_id)->value('recipe_id'),
            default => null,
        };

        return $recipeId === null ? null : Recipe::query()->find($recipeId);
    }
}

<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Http\UploadedFile;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Handles photo uploads: validates real content, fixes orientation and strips EXIF by re-encoding.
 */
class ImageUploadService
{
    /** @return list<string> */
    public static function rules(): array
    {
        $max = config('recipes.uploads.max_dimension');

        return [
            'image',
            'mimes:'.implode(',', config('recipes.uploads.mimes')),
            'max:'.config('recipes.uploads.max_kilobytes'),
            "dimensions:max_width={$max},max_height={$max}",
        ];
    }

    public function addCover(Recipe $recipe, UploadedFile|string $file, string $origin = 'uploaded', ?int $aiJobId = null, bool $activate = true): Media
    {
        $path = $this->normalise($file);

        $media = $recipe->addMedia($path)
            ->usingFileName('cover-'.$recipe->id.'-'.time().'.jpg')
            ->withCustomProperties(array_filter(['origin' => $origin, 'ai_job_id' => $aiJobId]))
            ->toMediaCollection(Recipe::COVER_COLLECTION);

        if ($activate) {
            $recipe->forceFill(['cover_media_id' => $media->id])->saveQuietly();
        }

        return $media;
    }

    public function addStepImage(RecipeStep $step, UploadedFile $file): Media
    {
        $path = $this->normalise($file);

        return $step->addMedia($path)
            ->usingFileName('step-'.$step->id.'-'.time().'-'.random_int(100, 999).'.jpg')
            ->withCustomProperties(['origin' => 'uploaded'])
            ->toMediaCollection(RecipeStep::IMAGES_COLLECTION);
    }

    /**
     * Activate a previously uploaded cover (restore the previous photo).
     */
    public function activateCover(Recipe $recipe, Media $media): void
    {
        abort_unless($media->model_type === $recipe->getMorphClass() && (int) $media->model_id === $recipe->id, 404);

        $recipe->forceFill(['cover_media_id' => $media->id])->saveQuietly();
    }

    public function removeCover(Recipe $recipe): void
    {
        $recipe->forceFill(['cover_media_id' => null])->saveQuietly();
    }

    /**
     * Re-encode the image as JPEG using GD: applies EXIF orientation and drops all metadata (incl. GPS).
     */
    private function normalise(UploadedFile|string $file): string
    {
        $source = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $target = tempnam(sys_get_temp_dir(), 'recipe-img').'.jpg';

        Image::load($source)
            ->orientation()
            ->quality(88)
            ->save($target);

        return $target;
    }
}

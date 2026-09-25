<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property int $recipe_id
 * @property int $position
 * @property string $text
 */
#[Fillable(['recipe_id', 'position', 'text'])]
class RecipeStep extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const IMAGES_COLLECTION = 'step_images';

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGES_COLLECTION);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->nonQueued()->fit(Fit::Crop, 400, 300)->format('jpg');
        $this->addMediaConversion('card')->nonQueued()->fit(Fit::Contain, 1200, 1200)->format('jpg');
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}

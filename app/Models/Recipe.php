<?php

namespace App\Models;

use App\Enums\MealType;
use App\Enums\ServingMode;
use App\Enums\SideRequirement;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property int $household_id
 * @property int|null $created_by
 * @property string $title
 * @property string|null $description
 * @property int|null $base_servings
 * @property int|null $prep_minutes
 * @property int|null $cook_minutes
 * @property SideRequirement $side_requirement
 * @property string|null $included_side
 * @property ServingMode $serving_mode
 * @property string|null $raw_text
 * @property string|null $source
 * @property string|null $notes
 * @property int|null $cover_media_id
 * @property int|null $active_revision_id
 * @property int $version
 * @property Carbon|null $archived_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'household_id', 'created_by', 'title', 'description', 'base_servings', 'prep_minutes', 'cook_minutes',
    'side_requirement', 'included_side', 'serving_mode', 'raw_text', 'source', 'notes', 'cover_media_id',
    'active_revision_id', 'version', 'archived_at', 'published_at',
])]
class Recipe extends Model implements HasMedia
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory, InteractsWithMedia;

    public const COVER_COLLECTION = 'covers';

    protected $attributes = [
        'side_requirement' => 'unknown',
        'serving_mode' => 'auto',
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'side_requirement' => SideRequirement::class,
            'serving_mode' => ServingMode::class,
            'archived_at' => 'datetime',
            'published_at' => 'datetime',
            'base_servings' => 'integer',
            'prep_minutes' => 'integer',
            'cook_minutes' => 'integer',
            'version' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COVER_COLLECTION);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->nonQueued()->fit(Fit::Crop, 400, 300)->format('jpg');
        $this->addMediaConversion('card')->nonQueued()->fit(Fit::Crop, 960, 720)->format('jpg');
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<RecipeMealType, $this> */
    public function mealTypes(): HasMany
    {
        return $this->hasMany(RecipeMealType::class);
    }

    /** @return HasMany<IngredientLine, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(IngredientLine::class)->orderBy('position');
    }

    /** @return HasMany<RecipeStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('position');
    }

    /** @return HasMany<RecipeRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(RecipeRevision::class)->orderByDesc('id');
    }

    /** @return HasMany<PersonRecipePreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(PersonRecipePreference::class);
    }

    /** @return HasMany<PersonRecipeExclusion, $this> */
    public function exclusions(): HasMany
    {
        return $this->hasMany(PersonRecipeExclusion::class);
    }

    /** @return HasMany<MealPlan, $this> */
    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    /** @return HasMany<CookingEvent, $this> */
    public function cookingEvents(): HasMany
    {
        return $this->hasMany(CookingEvent::class);
    }

    /** @return HasMany<AiJob, $this> */
    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiJob::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /** @param  Builder<Recipe>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Recipes shared on the public home page: published and not archived.
     *
     * @param  Builder<Recipe>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->whereNull('archived_at');
    }

    public function isPublic(): bool
    {
        return $this->published_at !== null && ! $this->isArchived();
    }

    /** @return list<MealType> */
    public function mealTypeEnums(): array
    {
        return array_values($this->mealTypes->map(fn (RecipeMealType $t) => $t->meal_type)->all());
    }

    public function totalMinutes(): ?int
    {
        if ($this->prep_minutes === null && $this->cook_minutes === null) {
            return null;
        }

        return (int) $this->prep_minutes + (int) $this->cook_minutes;
    }

    /**
     * Whether the cover image was generated by AI.
     */
    public function coverIsAi(): bool
    {
        return $this->cover?->getCustomProperty('origin') === 'ai';
    }

    /**
     * Placeholder colour when there is no cover image.
     */
    public function placeholderColor(): string
    {
        return Person::defaultColorFor($this->title);
    }
}

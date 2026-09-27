<?php

namespace App\Models;

use App\Enums\MealAnalysisAiStatus;
use App\Enums\MealAnalysisStatus;
use Database\Factories\MealAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One photo of a meal and what came of it (v2.1 stage 11): the AI's proposal of visible components, the person's
 * corrections and – after confirmation – the database calculation. It belongs to the person who uploaded the
 * photo, not to the household: other members never see it, the administrator sees only the job's metadata.
 *
 * @property int $id
 * @property int $household_id
 * @property int $user_id
 * @property int|null $ai_job_id
 * @property MealAnalysisStatus $status
 * @property string|null $note
 * @property array<string, mixed>|null $ai_result
 * @property MealAnalysisAiStatus|null $ai_status
 * @property string|null $dish_name
 * @property list<string>|null $questions
 * @property list<string>|null $limitations
 * @property int $clarification_count
 * @property array<string, mixed>|null $nutrition
 * @property Carbon|null $photo_retain_until
 * @property Carbon|null $photo_removed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'household_id', 'user_id', 'ai_job_id', 'status', 'note', 'ai_result', 'ai_status', 'dish_name', 'questions',
    'limitations', 'clarification_count', 'nutrition', 'photo_retain_until', 'photo_removed_at', 'confirmed_at', 'expires_at',
])]
class MealAnalysis extends Model implements HasMedia
{
    /** @use HasFactory<MealAnalysisFactory> */
    use HasFactory, InteractsWithMedia;

    public const PHOTO_COLLECTION = 'photo';

    protected function casts(): array
    {
        return [
            'status' => MealAnalysisStatus::class,
            'ai_result' => 'array',
            'ai_status' => MealAnalysisAiStatus::class,
            'questions' => 'array',
            'limitations' => 'array',
            'clarification_count' => 'integer',
            'nutrition' => 'array',
            'photo_retain_until' => 'datetime',
            'photo_removed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** The photo lives on a private disk; it is served only through the owner-checked route, never by URL. */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTO_COLLECTION)->useDisk((string) config('recipes.meal_analysis.disk', 'local'))->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // No conversions: the stored file is already the normalised (EXIF-free, ≤ max_dimension) JPEG.
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The charged root job. */
    /** @return BelongsTo<AiJob, $this> */
    public function aiJob(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }

    /** @return HasMany<MealAnalysisItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MealAnalysisItem::class)->orderBy('position')->orderBy('id');
    }

    public function photo(): ?Media
    {
        return $this->getFirstMedia(self::PHOTO_COLLECTION);
    }

    public function hasPhoto(): bool
    {
        return $this->photo_removed_at === null && $this->photo() !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->status === MealAnalysisStatus::Confirmed;
    }

    /** "Nedokážem určiť": no components and never a number. */
    public function isUnusable(): bool
    {
        return $this->status === MealAnalysisStatus::Unusable;
    }

    public function isPartial(): bool
    {
        return ($this->nutrition['completeness'] ?? null) === 'partial';
    }
}

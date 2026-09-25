<?php

namespace App\Models;

use App\Enums\PersonKind;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $household_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $color
 * @property PersonKind $kind
 * @property Carbon|null $archived_at
 */
#[Fillable(['household_id', 'user_id', 'name', 'color', 'kind', 'archived_at'])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => PersonKind::class,
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** @param  Builder<Person>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isGuest(): bool
    {
        return $this->kind === PersonKind::Guest;
    }

    public function initials(): string
    {
        return Str::upper(Str::substr(trim($this->name), 0, 1));
    }

    public function colorOrDefault(): string
    {
        return $this->color ?: self::defaultColorFor($this->name);
    }

    /**
     * Stable pastel-like colour derived from a name.
     */
    public static function defaultColorFor(string $seed): string
    {
        $palette = ['#f97316', '#0ea5e9', '#22c55e', '#a855f7', '#ec4899', '#eab308', '#14b8a6', '#ef4444'];

        return $palette[crc32($seed) % count($palette)];
    }
}

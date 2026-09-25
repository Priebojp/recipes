<?php

namespace App\Models;

use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $timezone
 * @property int $owner_user_id
 * @property array<int>|null $default_person_ids
 */
#[Fillable(['name', 'timezone', 'owner_user_id', 'default_person_ids'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['default_person_ids' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<HouseholdMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(HouseholdMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'household_memberships')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Person, $this> */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    /** @return HasMany<Recipe, $this> */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
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

    /** @return HasMany<HouseholdInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(HouseholdInvitation::class);
    }
}

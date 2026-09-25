<?php

namespace App\Services;

use App\Enums\MealType;
use App\Models\Household;
use App\Models\IngredientLine;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecipeService
{
    public function __construct(private IngredientAmountParser $amounts) {}

    /**
     * Quick add: only the title is required.
     *
     * @param  array{title: string, description?: string|null, meal_types?: list<string>}  $data
     */
    public function quickCreate(Household $household, ?User $author, array $data): Recipe
    {
        return DB::transaction(function () use ($household, $author, $data) {
            $recipe = Recipe::create([
                'household_id' => $household->id,
                'created_by' => $author?->id,
                'title' => trim($data['title']),
                'description' => $this->nullable($data['description'] ?? null),
            ]);

            $this->syncMealTypes($recipe, $data['meal_types'] ?? []);
            $this->snapshotRevision($recipe->fresh(['ingredients', 'steps', 'mealTypes']), $author, 'manual');

            return $recipe->fresh();
        });
    }

    /**
     * Full update including ingredients and steps. Uses optimistic version check.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws StaleRecipeException
     */
    public function update(Recipe $recipe, ?User $author, array $data, ?int $expectedVersion = null, string $source = 'manual'): Recipe
    {
        return DB::transaction(function () use ($recipe, $author, $data, $expectedVersion, $source) {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipe->id);

            if ($expectedVersion !== null && $recipe->version !== $expectedVersion) {
                throw new StaleRecipeException('Recept bol medzitým upravený niekým iným.');
            }

            $recipe->fill([
                'title' => trim((string) ($data['title'] ?? $recipe->title)),
                'description' => $this->nullable($data['description'] ?? $recipe->description),
                'base_servings' => $this->positiveInt($data['base_servings'] ?? $recipe->base_servings),
                'prep_minutes' => $this->positiveInt($data['prep_minutes'] ?? $recipe->prep_minutes),
                'cook_minutes' => $this->positiveInt($data['cook_minutes'] ?? $recipe->cook_minutes),
                'side_requirement' => $data['side_requirement'] ?? $recipe->side_requirement,
                'included_side' => $this->nullable($data['included_side'] ?? $recipe->included_side),
                'serving_mode' => $data['serving_mode'] ?? $recipe->serving_mode,
                'raw_text' => $this->nullable($data['raw_text'] ?? $recipe->raw_text),
                'source' => $this->nullable($data['source'] ?? $recipe->source),
                'notes' => $this->nullable($data['notes'] ?? $recipe->notes),
                'version' => $recipe->version + 1,
            ]);
            $recipe->save();

            if (array_key_exists('meal_types', $data)) {
                $this->syncMealTypes($recipe, $data['meal_types'] ?? []);
            }

            if (array_key_exists('ingredients', $data)) {
                $this->syncIngredients($recipe, $data['ingredients'] ?? []);
            }

            if (array_key_exists('steps', $data)) {
                $this->syncSteps($recipe, $data['steps'] ?? []);
            }

            $this->snapshotRevision($recipe->fresh(['ingredients', 'steps', 'mealTypes']), $author, $source);

            return $recipe->fresh();
        });
    }

    public function archive(Recipe $recipe): void
    {
        $recipe->update(['archived_at' => now()]);
    }

    public function restore(Recipe $recipe): void
    {
        $recipe->update(['archived_at' => null]);
    }

    /**
     * Permanent delete. History keeps the stored title snapshot (recipe_id becomes NULL).
     */
    public function destroy(Recipe $recipe): void
    {
        DB::transaction(function () use ($recipe) {
            $recipe->cookingEvents()->update(['recipe_id' => null]);
            $recipe->clearMediaCollection(Recipe::COVER_COLLECTION);
            foreach ($recipe->steps as $step) {
                $step->clearMediaCollection(RecipeStep::IMAGES_COLLECTION);
            }
            $recipe->delete();
        });
    }

    /** @param  list<string>  $types */
    public function syncMealTypes(Recipe $recipe, array $types): void
    {
        $types = array_values(array_unique(array_filter($types, fn ($t) => MealType::tryFrom((string) $t) !== null)));
        $recipe->mealTypes()->delete();
        foreach ($types as $type) {
            $recipe->mealTypes()->create(['meal_type' => $type]);
        }
    }

    /**
     * @param  list<array{id?: int|null, name?: string|null, amount?: string|null, unit?: string|null, note?: string|null, source_text?: string|null}>  $lines
     */
    public function syncIngredients(Recipe $recipe, array $lines): void
    {
        $keep = [];
        $position = 0;

        foreach ($lines as $line) {
            $name = trim((string) ($line['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $parsed = $this->amounts->parse($line['amount'] ?? null);
            $attributes = [
                'position' => $position++,
                'name' => mb_substr($name, 0, 200),
                'numeric_amount' => $parsed['numeric'],
                'text_amount' => $parsed['text'],
                'unit' => $this->nullable($line['unit'] ?? null),
                'note' => $this->nullable($line['note'] ?? null),
                'source_text' => $this->nullable($line['source_text'] ?? null),
            ];

            $existing = isset($line['id']) ? $recipe->ingredients()->whereKey($line['id'])->first() : null;

            if ($existing instanceof IngredientLine) {
                $existing->update($attributes);
                $keep[] = $existing->id;
            } else {
                $keep[] = $recipe->ingredients()->create($attributes)->id;
            }
        }

        $recipe->ingredients()->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  list<array{id?: int|null, text?: string|null}>  $steps
     */
    public function syncSteps(Recipe $recipe, array $steps): void
    {
        $keep = [];
        $position = 0;

        foreach ($steps as $step) {
            $text = trim((string) ($step['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $attributes = ['position' => $position++, 'text' => $text];
            $existing = isset($step['id']) ? $recipe->steps()->whereKey($step['id'])->first() : null;

            if ($existing instanceof RecipeStep) {
                $existing->update($attributes);
                $keep[] = $existing->id;
            } else {
                $keep[] = $recipe->steps()->create($attributes)->id;
            }
        }

        foreach ($recipe->steps()->whereNotIn('id', $keep)->get() as $removed) {
            $removed->clearMediaCollection(RecipeStep::IMAGES_COLLECTION);
            $removed->delete();
        }
    }

    /**
     * Immutable snapshot of the recipe content.
     */
    public function snapshotRevision(Recipe $recipe, ?User $author, string $source): RecipeRevision
    {
        $revision = RecipeRevision::create([
            'recipe_id' => $recipe->id,
            'author_id' => $author?->id,
            'snapshot' => self::snapshot($recipe),
            'source' => $source,
            'previous_revision_id' => $recipe->active_revision_id,
            'created_at' => now(),
        ]);

        $recipe->forceFill(['active_revision_id' => $revision->id])->saveQuietly();

        return $revision;
    }

    /** @return array<string, mixed> */
    public static function snapshot(Recipe $recipe): array
    {
        return [
            'title' => $recipe->title,
            'description' => $recipe->description,
            'base_servings' => $recipe->base_servings,
            'prep_minutes' => $recipe->prep_minutes,
            'cook_minutes' => $recipe->cook_minutes,
            'side_requirement' => $recipe->side_requirement->value,
            'included_side' => $recipe->included_side,
            'serving_mode' => $recipe->serving_mode->value,
            'raw_text' => $recipe->raw_text,
            'source' => $recipe->source,
            'notes' => $recipe->notes,
            'meal_types' => array_map(fn (MealType $t) => $t->value, $recipe->mealTypeEnums()),
            'ingredients' => $recipe->ingredients->map(fn (IngredientLine $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'numeric_amount' => $l->numeric_amount,
                'text_amount' => $l->text_amount,
                'unit' => $l->unit,
                'note' => $l->note,
                'source_text' => $l->source_text,
            ])->values()->all(),
            'steps' => $recipe->steps->map(fn (RecipeStep $s) => ['id' => $s->id, 'text' => $s->text])->values()->all(),
        ];
    }

    /**
     * Titles that already exist in the household (case-insensitive) – used for a soft duplicate warning.
     */
    public function duplicateTitleExists(Household $household, string $title, ?int $ignoreId = null): bool
    {
        return Recipe::query()
            ->where('household_id', $household->id)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($title))])
            ->exists();
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}

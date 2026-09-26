<?php

namespace App\Services;

use App\Models\CookingEvent;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\SelectionPreset;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use ZipArchive;

/**
 * Builds a ZIP with a versioned JSON export and every image of the household.
 */
class ExportService
{
    /** @var array<string, string> zip entry => absolute path, collected while building the data */
    private array $files = [];

    public function build(Household $household): string
    {
        $path = tempnam(sys_get_temp_dir(), 'recipes-export').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $mediaFiles = [];
        $data = $this->data($household, $mediaFiles);

        $zip->addFromString('export.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($mediaFiles as $entryName => $absolutePath) {
            if (is_file($absolutePath)) {
                $zip->addFile($absolutePath, $entryName);
            }
        }

        $zip->close();

        return $path;
    }

    /**
     * @param  array<string, string>  $mediaFiles  filled with zip entry => absolute path
     * @return array<string, mixed>
     */
    public function data(Household $household, array &$mediaFiles = []): array
    {
        $this->files = [];
        $data = $this->buildData($household);
        $mediaFiles = $this->files;

        return $data;
    }

    /** @return array<string, mixed> */
    private function buildData(Household $household): array
    {
        $recipes = Recipe::query()->where('household_id', $household->id)
            ->with(['mealTypes', 'ingredients', 'steps.media', 'media', 'revisions', 'preferences', 'exclusions'])
            ->get();

        return [
            'schema_version' => config('recipes.export.schema_version'),
            'exported_at' => now()->toIso8601String(),
            'household' => [
                'id' => $household->id,
                'name' => $household->name,
                'timezone' => $household->timezone,
                'default_person_ids' => $household->default_person_ids,
            ],
            'people' => Person::query()->where('household_id', $household->id)->get()->map(fn (Person $p) => [
                'id' => $p->id, 'name' => $p->name, 'kind' => $p->kind->value, 'color' => $p->color, 'archived_at' => $p->archived_at?->toIso8601String(),
            ])->all(),
            'recipes' => $recipes->map(fn (Recipe $r): array => $this->recipeData($r))->all(),
            'meal_plans' => MealPlan::query()->where('household_id', $household->id)->with('people')->get()->map(fn (MealPlan $p) => [
                'id' => $p->id, 'recipe_id' => $p->recipe_id, 'mode' => $p->mode->value, 'scheduled_date' => $p->scheduled_date?->toDateString(),
                'week_start_date' => $p->week_start_date?->toDateString(), 'meal_type' => $p->meal_type?->value, 'servings' => $p->servings,
                'status' => $p->status->value, 'person_ids' => $p->people->pluck('id')->all(),
            ])->all(),
            'cooking_events' => CookingEvent::query()->where('household_id', $household->id)->with('people')->get()->map(fn (CookingEvent $e) => [
                'id' => $e->id, 'recipe_id' => $e->recipe_id, 'meal_plan_id' => $e->meal_plan_id, 'cooked_on' => $e->cooked_on->toDateString(),
                'servings' => $e->servings, 'note' => $e->note, 'recipe_title_snapshot' => $e->recipe_title_snapshot,
                'recipe_revision_id' => $e->recipe_revision_id, 'voided_at' => $e->voided_at?->toIso8601String(), 'person_ids' => $e->people->pluck('id')->all(),
            ])->all(),
            // v2 stage 6 (schema 2): Plus data belongs to the household as well.
            'selection_presets' => SelectionPreset::query()->where('household_id', $household->id)->orderBy('name')->get()->map(fn (SelectionPreset $p) => [
                'id' => $p->id, 'name' => $p->name, 'person_ids' => $p->person_ids, 'meal_type' => $p->meal_type?->value, 'filters' => $p->filters,
            ])->all(),
            'shopping_lists' => ShoppingList::query()->where('household_id', $household->id)->with('items')->orderBy('week_start_date')->get()->map(fn (ShoppingList $l) => [
                'id' => $l->id, 'week_start_date' => $l->week_start_date->toDateString(), 'generated_at' => $l->generated_at?->toIso8601String(),
                'items' => $l->items->map(fn (ShoppingListItem $i) => [
                    'name' => $i->name, 'unit' => $i->unit, 'numeric_amount' => $i->numeric_amount, 'text_amounts' => $i->text_amounts,
                    'sources' => $i->sources, 'manual' => $i->manual, 'checked_at' => $i->checked_at?->toIso8601String(),
                ])->all(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function recipeData(Recipe $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'description' => $r->description,
            'meal_types' => array_map(fn ($t) => $t->value, $r->mealTypeEnums()),
            'base_servings' => $r->base_servings,
            'prep_minutes' => $r->prep_minutes,
            'cook_minutes' => $r->cook_minutes,
            'side_requirement' => $r->side_requirement->value,
            'included_side' => $r->included_side,
            'serving_mode' => $r->serving_mode->value,
            'raw_text' => $r->raw_text,
            'source' => $r->source,
            'notes' => $r->notes,
            'archived_at' => $r->archived_at?->toIso8601String(),
            'cover_media_id' => $r->cover_media_id,
            'ingredients' => $r->ingredients->map(fn ($l) => [
                'id' => $l->id, 'position' => $l->position, 'name' => $l->name, 'numeric_amount' => $l->numeric_amount,
                'text_amount' => $l->text_amount, 'unit' => $l->unit, 'note' => $l->note, 'source_text' => $l->source_text,
            ])->all(),
            'steps' => $r->steps->map(fn (RecipeStep $s) => [
                'id' => $s->id, 'position' => $s->position, 'text' => $s->text,
                'images' => $s->getMedia(RecipeStep::IMAGES_COLLECTION)->map(fn (Media $m) => $this->mediaEntry($m))->all(),
            ])->all(),
            'covers' => $r->getMedia(Recipe::COVER_COLLECTION)->map(fn (Media $m) => $this->mediaEntry($m))->all(),
            'revisions' => $r->revisions->map(fn ($rev) => [
                'id' => $rev->id, 'source' => $rev->source, 'previous_revision_id' => $rev->previous_revision_id,
                'created_at' => $rev->created_at->toIso8601String(), 'snapshot' => $rev->snapshot,
            ])->all(),
            'preferences' => $r->preferences->map(fn ($p) => ['person_id' => $p->person_id, 'preference' => $p->preference->value])->all(),
            'exclusions' => $r->exclusions->map(fn ($e) => ['person_id' => $e->person_id, 'reason' => $e->reason])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function mediaEntry(Media $media): array
    {
        $entry = 'media/'.$media->id.'-'.$media->file_name;
        $this->files[$entry] = $media->getPath();

        return [
            'id' => $media->id,
            'file' => $entry,
            'mime' => $media->mime_type,
            'size' => $media->size,
            'origin' => $media->getCustomProperty('origin', 'uploaded'),
            'ai_job_id' => $media->getCustomProperty('ai_job_id'),
        ];
    }
}

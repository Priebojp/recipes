<?php

namespace App\Services\Plus;

use App\Enums\PlanStatus;
use App\Models\Household;
use App\Models\MealPlan;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\IngredientAmountParser;
use App\Services\ServingScaler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shopping list of one week from the planned meals. Merging is explicit: numeric amounts add up only when the
 * normalised name and unit are identical ("cibuľa" + "Cibuľa" in "ks"); "g" and "kg" or "podľa chuti" are never
 * converted, they stay listed separately with the recipe they come from. Amounts scale with the plan's servings
 * when the recipe knows its base servings, otherwise the recipe's original amounts are used as they are.
 *
 * @phpstan-type Line array{merge_key: string, name: string, unit: string|null, numeric_amount: float|null, text_amounts: list<string>, sources: list<array{recipe_id: int, title: string, plan_id: int, scaled: bool}>}
 */
class ShoppingListBuilder
{
    public function __construct(
        private ServingScaler $scaler = new ServingScaler,
        private IngredientAmountParser $parser = new IngredientAmountParser,
    ) {}

    /**
     * Planned meals of the week: days inside it and the "this week – no day" plans of the same week.
     *
     * @return Collection<int, MealPlan>
     */
    public function plansForWeek(Household $household, CarbonImmutable $weekStart): Collection
    {
        $weekEnd = $weekStart->addDays(6);

        return MealPlan::query()
            ->where('household_id', $household->id)
            ->where('status', PlanStatus::Planned)
            ->where(function ($q) use ($weekStart, $weekEnd) {
                $q->whereBetween('scheduled_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                    ->orWhere('week_start_date', $weekStart->toDateString());
            })
            ->with(['recipe.ingredients'])
            ->orderByRaw('scheduled_date is null, scheduled_date, id') // days first, "no day" plans last – stable source order
            ->get();
    }

    /**
     * Pure merge of the ingredient lines of the given plans.
     *
     * @param  iterable<MealPlan>  $plans
     * @return list<Line>
     */
    public function aggregate(iterable $plans): array
    {
        /** @var array<string, Line> $lines */
        $lines = [];

        foreach ($plans as $plan) {
            $recipe = $plan->recipe;
            if ($recipe === null) {
                continue;
            }

            $ratio = $this->scaler->canScale($recipe->base_servings) && $plan->servings !== null && $plan->servings > 0
                ? $plan->servings / $recipe->base_servings
                : null;

            foreach ($recipe->ingredients as $ingredient) {
                $key = ShoppingListItem::mergeKey($ingredient->name, $ingredient->unit);
                $lines[$key] ??= [
                    'merge_key' => $key,
                    'name' => trim($ingredient->name),
                    'unit' => $ingredient->unit !== null && trim($ingredient->unit) !== '' ? trim($ingredient->unit) : null,
                    'numeric_amount' => null,
                    'text_amounts' => [],
                    'sources' => [],
                ];

                $scaled = false;
                if ($ingredient->numeric_amount !== null) {
                    $value = (float) $ingredient->numeric_amount;
                    if ($ratio !== null) {
                        $value *= $ratio;
                        $scaled = abs($ratio - 1.0) > 1e-9;
                    }
                    $lines[$key]['numeric_amount'] = ($lines[$key]['numeric_amount'] ?? 0.0) + $value;
                } elseif ($ingredient->text_amount !== null && trim($ingredient->text_amount) !== '') {
                    $lines[$key]['text_amounts'][] = trim($ingredient->text_amount).' ('.$recipe->title.')';
                }

                $lines[$key]['sources'][] = ['recipe_id' => $recipe->id, 'title' => $recipe->title, 'plan_id' => $plan->id, 'scaled' => $scaled];
            }
        }

        $lines = array_values($lines);
        usort($lines, fn (array $a, array $b) => $this->compareNames($a['name'], $b['name']));

        return $lines;
    }

    /**
     * (Re)generate the list of the week. Generated lines are replaced, the checked state of a line that is still
     * there is kept, manual lines survive untouched.
     */
    public function generate(Household $household, CarbonImmutable $weekStart, ?User $by): ShoppingList
    {
        $plans = $this->plansForWeek($household, $weekStart);
        $lines = $this->aggregate($plans);

        return DB::transaction(function () use ($household, $weekStart, $by, $plans, $lines) {
            $list = ShoppingList::query()->firstOrCreate(
                ['household_id' => $household->id, 'week_start_date' => $weekStart->toDateString()],
            );
            $list = ShoppingList::query()->lockForUpdate()->findOrFail($list->id);

            $existing = $list->items()->get()->keyBy('merge_key');
            $keep = [];
            $position = 0;

            foreach ($lines as $line) {
                $item = $existing->get($line['merge_key']);
                $attributes = [
                    'position' => $position++,
                    'name' => $line['name'],
                    'unit' => $line['unit'],
                    'numeric_amount' => $line['numeric_amount'] === null ? null : round($line['numeric_amount'], 3),
                    'text_amounts' => $line['text_amounts'],
                    'sources' => $line['sources'],
                    'manual' => false,
                ];

                if ($item === null) {
                    $item = $list->items()->create(array_merge($attributes, ['merge_key' => $line['merge_key']]));
                } else {
                    $item->update($attributes);
                }
                $keep[] = $item->id;
            }

            foreach ($existing as $item) {
                if ($item->manual && ! in_array($item->id, $keep, true)) {
                    $item->update(['position' => $position++]);
                    $keep[] = $item->id;
                }
            }

            $list->items()->whereNotIn('id', $keep)->delete();
            $list->update(['generated_at' => now(), 'plan_count' => $plans->count(), 'generated_by' => $by?->id]);

            return $list->fresh('items');
        });
    }

    /**
     * The shopper's own line ("chlieb"). Same name and unit as an existing line adds to it instead of duplicating.
     */
    public function addManual(ShoppingList $list, string $name, ?string $amount, ?string $unit): ShoppingListItem
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Zadaj názov položky.');
        }
        $unit = $unit !== null && trim($unit) !== '' ? trim($unit) : null;
        $parsed = $this->parser->parse($amount);
        $key = ShoppingListItem::mergeKey($name, $unit);

        return DB::transaction(function () use ($list, $name, $unit, $parsed, $key) {
            $item = $list->items()->where('merge_key', $key)->lockForUpdate()->first();
            if ($item !== null) {
                if ($parsed['numeric'] !== null) {
                    $item->numeric_amount = $this->parser->format((float) ($item->numeric_amount ?? 0) + (float) $parsed['numeric']);
                } elseif ($parsed['text'] !== null) {
                    $item->text_amounts = array_merge($item->text_amounts ?? [], [$parsed['text']]);
                }
                $item->checked_at = null;
                $item->save();

                return $item;
            }

            return $list->items()->create([
                'position' => (int) $list->items()->max('position') + 1,
                'merge_key' => $key,
                'name' => $name,
                'unit' => $unit,
                'numeric_amount' => $parsed['numeric'],
                'text_amounts' => $parsed['text'] !== null ? [$parsed['text']] : [],
                'sources' => [],
                'manual' => true,
            ]);
        });
    }

    public function toggle(ShoppingListItem $item): ShoppingListItem
    {
        $item->update(['checked_at' => $item->checked_at === null ? now() : null]);

        return $item;
    }

    public function remove(ShoppingListItem $item): void
    {
        if (! $item->manual) {
            throw new InvalidArgumentException('Položku z receptu odstrániš odškrtnutím alebo zmenou plánu.');
        }
        $item->delete();
    }

    private function compareNames(string $a, string $b): int
    {
        static $collator = null;
        if ($collator === null && class_exists(\Collator::class)) {
            $collator = new \Collator('sk_SK');
        }

        return $collator instanceof \Collator ? (int) $collator->compare($a, $b) : strcmp(mb_strtolower($a), mb_strtolower($b));
    }
}

<?php

namespace App\Services;

use App\Enums\PlanStatus;
use App\Models\CookingEvent;
use App\Models\Household;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CookingHistoryService
{
    /**
     * Record a real cooking. Re-sent requests with the same idempotency key return the existing event.
     *
     * @param  array{cooked_on?: string|null, servings?: int|string|null, note?: string|null, person_ids?: list<int>|null, meal_plan_id?: int|null}  $data
     */
    public function record(Household $household, Recipe $recipe, array $data, ?User $by, ?string $idempotencyKey = null): CookingEvent
    {
        if ($recipe->household_id !== $household->id) {
            throw new InvalidArgumentException('Recept nepatrí do tejto domácnosti.');
        }

        $calendar = new PlanningCalendar($household->timezone);
        $cookedOn = isset($data['cooked_on']) && $data['cooked_on'] ? $calendar->date($data['cooked_on']) : $calendar->today();

        if ($cookedOn->greaterThan($calendar->today())) {
            throw new InvalidArgumentException('Dátum uvarenia nemôže byť v budúcnosti.');
        }

        if ($idempotencyKey !== null) {
            $existing = CookingEvent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $planId = $data['meal_plan_id'] ?? null;
        if ($planId !== null) {
            $existing = CookingEvent::query()->where('active_plan_key', $planId)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $people = Person::query()->where('household_id', $household->id)->whereIn('id', array_map('intval', $data['person_ids'] ?? []))->get();
        $servings = (int) ($data['servings'] ?? 0);

        try {
            return DB::transaction(function () use ($household, $recipe, $cookedOn, $data, $servings, $people, $by, $idempotencyKey, $planId) {
                $event = CookingEvent::create([
                    'household_id' => $household->id,
                    'recipe_id' => $recipe->id,
                    'meal_plan_id' => $planId,
                    'active_plan_key' => $planId,
                    'idempotency_key' => $idempotencyKey,
                    'cooked_on' => $cookedOn->toDateString(),
                    'servings' => $servings > 0 ? $servings : null,
                    'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
                    'recipe_title_snapshot' => $recipe->title,
                    'recipe_revision_id' => $recipe->active_revision_id,
                    'created_by' => $by?->id,
                ]);
                $event->people()->sync($people->pluck('id'));

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent duplicate won the race; return the existing active event.
            $query = CookingEvent::query();
            if ($idempotencyKey !== null) {
                $query->where('idempotency_key', $idempotencyKey);
            } else {
                $query->where('active_plan_key', $planId);
            }

            return $query->firstOrFail();
        }
    }

    /**
     * Void an event: it disappears from history and from the generator. The linked plan returns to planned.
     */
    public function void(CookingEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $event->update(['voided_at' => now(), 'active_plan_key' => null]);

            if ($event->meal_plan_id !== null) {
                $event->mealPlan()->where('status', PlanStatus::Cooked)->update(['status' => PlanStatus::Planned]);
            }
        });
    }

    /**
     * @param  array{cooked_on?: string|null, servings?: int|string|null, note?: string|null, person_ids?: list<int>|null}  $data
     */
    public function update(CookingEvent $event, array $data): CookingEvent
    {
        $household = Household::findOrFail($event->household_id);
        $calendar = new PlanningCalendar($household->timezone);

        return DB::transaction(function () use ($event, $data, $calendar, $household) {
            if (! empty($data['cooked_on'])) {
                $date = $calendar->date($data['cooked_on']);
                if ($date->greaterThan($calendar->today())) {
                    throw new InvalidArgumentException('Dátum uvarenia nemôže byť v budúcnosti.');
                }
                $event->cooked_on = $date;
            }
            if (array_key_exists('servings', $data)) {
                $event->servings = (int) $data['servings'] > 0 ? (int) $data['servings'] : null;
            }
            if (array_key_exists('note', $data)) {
                $event->note = trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null;
            }
            $event->save();

            if (array_key_exists('person_ids', $data)) {
                $people = Person::query()->where('household_id', $household->id)->whereIn('id', array_map('intval', $data['person_ids'] ?? []))->pluck('id');
                $event->people()->sync($people);
            }

            return $event->fresh(['people']);
        });
    }
}

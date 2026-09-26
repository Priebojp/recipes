<?php

namespace App\Services\Usage;

use App\Enums\UsageGrantSource;
use App\Enums\UsageKind;
use App\Models\Household;
use App\Models\UsageGrant;
use App\Models\User;

/**
 * The one-time trial: 3 text operations and 1 Standard image per verified user and per household.
 * The source key is bound to the owner, so a second household or a rollout re-run never re-grants it.
 */
class TrialGrants
{
    public function __construct(private UsageLedger $ledger) {}

    /**
     * Grant the trial to the household if its owner is verified and neither the owner nor the household has had one.
     * Safe to call on every AI request.
     *
     * @return list<UsageGrant> newly created grants (empty when nothing changed)
     */
    public function ensureFor(Household $household): array
    {
        $owner = $household->owner;
        if (! $owner instanceof User || ! $owner->hasVerifiedEmail()) {
            return [];
        }

        if (UsageGrant::query()->where('household_id', $household->id)->where('source', UsageGrantSource::Trial)->exists()) {
            return [];
        }

        $created = [];
        foreach (UsageKind::cases() as $kind) {
            $quantity = (int) config("recipes.usage.trial.{$kind->value}", 0);
            if ($quantity < 1) {
                continue;
            }

            $key = self::sourceKey($owner, $kind);
            if (UsageGrant::query()->where('source_key', $key)->exists()) {
                continue; // the owner already used the trial in another household
            }

            $created[] = $this->ledger->grant(
                $household,
                $kind,
                UsageGrantSource::Trial,
                $quantity,
                $key,
                note: 'Skúšobné použitia po overení e-mailu',
                meta: ['user_id' => $owner->id],
            );
        }

        return $created;
    }

    /**
     * Rollout for existing accounts: idempotent, never invents past consumption.
     *
     * @return array{households: int, granted: int}
     */
    public function backfill(): array
    {
        $households = 0;
        $granted = 0;

        Household::query()->with('owner')->orderBy('id')->chunkById(200, function ($chunk) use (&$households, &$granted) {
            foreach ($chunk as $household) {
                $households++;
                $granted += count($this->ensureFor($household));
            }
        });

        return ['households' => $households, 'granted' => $granted];
    }

    public static function sourceKey(User $owner, UsageKind $kind): string
    {
        return "trial:{$kind->value}:user:{$owner->id}";
    }
}

<?php

namespace App\Services\Usage;

use App\Enums\UsageGrantSource;
use App\Enums\UsageKind;
use App\Enums\UsageLedgerReason;
use App\Enums\UsageReservationState;
use App\Models\AiJob;
use App\Models\Household;
use App\Models\UsageGrant;
use App\Models\UsageLedgerEntry;
use App\Models\UsageReservation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Transactional accounting of AI uses (specification chapter 4 and 14).
 *
 * Every movement is a row in the append-only ledger with a unique source key, so a retried worker, a duplicate
 * webhook or a double click can never move a use twice. Counters on the grant are a cache of the ledger and can be
 * rebuilt with {@see reconcile()}.
 */
class UsageLedger
{
    /** Whether AI jobs must reserve a use (false keeps the pre-ledger behaviour: daily limits only). */
    public function enforced(): bool
    {
        return (bool) config('recipes.usage.enforce', true);
    }

    /**
     * Idempotently create a grant. The same source key (e.g. trial per user, Stripe invoice, refund case) returns
     * the existing grant instead of adding uses a second time.
     *
     * @param  array<string, mixed>|null  $meta
     * @param  array{order_id?: int|null, paid_entitlement_id?: int|null}  $links  purchase the grant belongs to
     */
    public function grant(
        Household $household,
        UsageKind $kind,
        UsageGrantSource $source,
        int $quantity,
        string $sourceKey,
        ?CarbonInterface $validFrom = null,
        ?CarbonInterface $expiresAt = null,
        ?User $actor = null,
        ?string $note = null,
        ?array $meta = null,
        array $links = [],
    ): UsageGrant {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Grant musí mať aspoň jedno použitie.');
        }

        return DB::transaction(function () use ($household, $kind, $source, $quantity, $sourceKey, $validFrom, $expiresAt, $actor, $note, $meta, $links) {
            $existing = UsageGrant::query()->where('source_key', $sourceKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            try {
                $grant = UsageGrant::create([
                    'household_id' => $household->id,
                    'kind' => $kind,
                    'source' => $source,
                    'source_key' => $sourceKey,
                    'order_id' => $links['order_id'] ?? null,
                    'paid_entitlement_id' => $links['paid_entitlement_id'] ?? null,
                    'quantity' => $quantity,
                    'valid_from' => $validFrom ?? now(),
                    'expires_at' => $expiresAt,
                    'note' => $note,
                    'meta' => $meta,
                    'created_by' => $actor?->id,
                ]);
            } catch (QueryException $e) {
                // Lost a race with a concurrent identical grant: the unique source key guarantees a single winner.
                $winner = UsageGrant::query()->where('source_key', $sourceKey)->first();
                if ($winner !== null) {
                    return $winner;
                }
                throw $e;
            }

            $this->record($grant, null, $quantity, UsageLedgerReason::Granted, 'grant:'.$grant->id, $actor, $note);

            return $grant;
        });
    }

    /**
     * Reserve one use for a job inside the caller's transaction (the job row must already exist).
     * Chooses the grant expiring soonest, then the oldest purchased one, under a row lock.
     *
     * @throws InsufficientUsageException
     */
    public function reserve(AiJob $job, UsageKind $kind, int $quantity = 1): UsageReservation
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Rezervácia musí mať aspoň jedno použitie.');
        }
        if (! DB::transactionLevel()) {
            throw new LogicException('Rezerváciu treba vytvoriť v transakcii spolu s úlohou.');
        }

        $existing = UsageReservation::query()->where('ai_job_id', $job->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $now = now();
        $candidates = UsageGrant::query()
            ->where('household_id', $job->household_id)
            ->where('kind', $kind)
            ->validAt($now)
            ->whereRaw('quantity - reserved_quantity - consumed_quantity - revoked_quantity >= ?', [$quantity])
            ->inConsumptionOrder()
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $grant) {
            // Conditional update: even without lock semantics (SQLite) a use can only be taken once.
            $taken = UsageGrant::query()
                ->whereKey($grant->id)
                ->whereRaw('quantity - reserved_quantity - consumed_quantity - revoked_quantity >= ?', [$quantity])
                ->increment('reserved_quantity', $quantity);

            if ($taken !== 1) {
                continue;
            }

            $reservation = UsageReservation::create([
                'household_id' => $job->household_id,
                'usage_grant_id' => $grant->id,
                'ai_job_id' => $job->id,
                'quantity' => $quantity,
                'state' => UsageReservationState::Reserved,
                'reserved_at' => $now,
            ]);

            $this->record($grant, $reservation, -$quantity, UsageLedgerReason::Reserved, 'reserve:reservation:'.$reservation->id, $job->creator);

            return $reservation;
        }

        throw new InsufficientUsageException($this->exhaustedMessage($kind));
    }

    /**
     * The result was delivered: the reserved use becomes consumed. Idempotent; a second call changes nothing.
     */
    public function consume(AiJob $job): ?UsageReservation
    {
        return $this->settle($job, UsageReservationState::Consumed);
    }

    /**
     * Definitive failure without a delivered result: the use goes back to the grant. Idempotent.
     * If the grant expired meanwhile the returned use is not carried over – that is by design.
     */
    public function release(AiJob $job): ?UsageReservation
    {
        return $this->settle($job, UsageReservationState::Released);
    }

    /**
     * Remove unused uses from one grant (refund, dispute). Never touches consumed uses or other grants.
     */
    public function revoke(UsageGrant $grant, int $quantity, string $sourceKey, ?User $actor = null, ?string $note = null): UsageGrant
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Odobrať treba aspoň jedno použitie.');
        }

        return DB::transaction(function () use ($grant, $quantity, $sourceKey, $actor, $note) {
            if (UsageLedgerEntry::query()->where('source_key', $sourceKey)->exists()) {
                return $grant->fresh();
            }

            $locked = UsageGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
            if ($quantity > $locked->available()) {
                throw new InvalidArgumentException("Grant má iba {$locked->available()} nevyužitých použití; spotrebované ani rezervované sa odobrať nedajú.");
            }

            $locked->revoked_quantity += $quantity;
            if ($locked->available() === 0) {
                $locked->revoked_at = now();
            }
            $locked->save();

            $this->record($locked, null, -$quantity, UsageLedgerReason::Revoked, $sourceKey, $actor, $note);

            return $locked;
        });
    }

    /** Uses the household can reserve right now. */
    public function available(Household $household, UsageKind $kind, ?CarbonInterface $at = null): int
    {
        return $this->balance($household, $kind, $at)->available();
    }

    /**
     * Balance split into included (expiring) and purchased uses for the settings page and AI buttons.
     */
    public function balance(Household $household, UsageKind $kind, ?CarbonInterface $at = null): UsageBalance
    {
        $at ??= now();

        /** @var Collection<int, UsageGrant> $grants */
        $grants = UsageGrant::query()
            ->where('household_id', $household->id)
            ->where('kind', $kind)
            ->validAt($at)
            ->inConsumptionOrder()
            ->get();

        $included = $grants->filter(fn (UsageGrant $g) => $g->source->isIncluded());
        $purchased = $grants->reject(fn (UsageGrant $g) => $g->source->isIncluded());

        $next = $included->first(fn (UsageGrant $g) => $g->available() > 0) ?? $included->first();

        return new UsageBalance(
            kind: $kind,
            includedAvailable: (int) $included->sum(fn (UsageGrant $g) => $g->available()),
            includedTotal: (int) $included->sum(fn (UsageGrant $g) => $g->effectiveQuantity()),
            includedExpiresAt: $next?->expires_at,
            includedSourceLabel: $next?->source->label(),
            purchasedAvailable: (int) $purchased->sum(fn (UsageGrant $g) => $g->available()),
        );
    }

    /**
     * Rebuild the grant's counters from its reservations and check the ledger sum. Returns true when the cache
     * already matched; the counters are corrected either way.
     */
    public function reconcile(UsageGrant $grant): bool
    {
        return DB::transaction(function () use ($grant) {
            $locked = UsageGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            $reserved = (int) $locked->reservations()->where('state', UsageReservationState::Reserved)->sum('quantity');
            $consumed = (int) $locked->reservations()->where('state', UsageReservationState::Consumed)->sum('quantity');
            $ledgerAvailable = (int) $locked->ledgerEntries()->sum('movement');

            $consistent = $locked->reserved_quantity === $reserved
                && $locked->consumed_quantity === $consumed
                && $ledgerAvailable === $locked->quantity - $reserved - $consumed - $locked->revoked_quantity;

            if (! $consistent) {
                $locked->forceFill(['reserved_quantity' => $reserved, 'consumed_quantity' => $consumed])->save();
            }

            return $consistent;
        });
    }

    /**
     * Reservations still open although their job is gone or finished – candidates for manual review.
     *
     * @return Builder<UsageReservation>
     */
    public function openReservationsQuery(): Builder
    {
        return UsageReservation::query()->where('state', UsageReservationState::Reserved)->with(['aiJob', 'grant']);
    }

    public function exhaustedMessage(UsageKind $kind): string
    {
        return "Nemáš už žiadne voľné AI použitia ({$kind->label()}). Ďalšie získaš s predplatným Plus alebo dokúpením balíka v Nastavenia → Predplatné – nič sa neúčtuje automaticky. Recept môžeš ďalej upravovať ručne.";
    }

    private function settle(AiJob $job, UsageReservationState $target): ?UsageReservation
    {
        return DB::transaction(function () use ($job, $target) {
            $reservation = UsageReservation::query()->where('ai_job_id', $job->id)->lockForUpdate()->first();
            if ($reservation === null) {
                return null; // created while the ledger was not enforced
            }
            if ($reservation->state === $target) {
                return $reservation;
            }
            if ($reservation->state !== UsageReservationState::Reserved) {
                throw new LogicException("Rezervácia {$reservation->id} je už {$reservation->state->value}, nedá sa zmeniť na {$target->value}.");
            }

            $grant = UsageGrant::query()->whereKey($reservation->usage_grant_id)->lockForUpdate()->firstOrFail();
            $grant->reserved_quantity = max(0, $grant->reserved_quantity - $reservation->quantity);
            if ($target === UsageReservationState::Consumed) {
                $grant->consumed_quantity += $reservation->quantity;
            }
            $grant->save();

            $reservation->update(['state' => $target, 'settled_at' => now()]);

            $consumed = $target === UsageReservationState::Consumed;
            $this->record(
                $grant,
                $reservation,
                $consumed ? 0 : $reservation->quantity,
                $consumed ? UsageLedgerReason::Consumed : UsageLedgerReason::Released,
                ($consumed ? 'consume' : 'release').':reservation:'.$reservation->id,
            );

            return $reservation;
        });
    }

    private function record(UsageGrant $grant, ?UsageReservation $reservation, int $movement, UsageLedgerReason $reason, string $sourceKey, ?User $actor = null, ?string $note = null): void
    {
        UsageLedgerEntry::create([
            'household_id' => $grant->household_id,
            'usage_grant_id' => $grant->id,
            'usage_reservation_id' => $reservation?->id,
            'movement' => $movement,
            'reason' => $reason,
            'source_key' => $sourceKey,
            'actor_id' => $actor?->id,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'created_at' => now(),
        ]);
    }
}

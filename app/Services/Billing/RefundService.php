<?php

namespace App\Services\Billing;

use App\Enums\OrderStatus;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Enums\UsageKind;
use App\Models\Order;
use App\Models\RefundCase;
use App\Models\UsageGrant;
use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Billing\Gateway\StripeGateway;
use App\Services\Usage\UsageLedger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Refund workflow (specification chapter 7): money in Stripe with an idempotency key, uses and paid periods revoked
 * here – only those of the refunded order, never consumed uses, never another pack. Everything is audited.
 */
class RefundService
{
    public function __construct(private StripeGateway $gateway, private UsageLedger $ledger, private AdminAuditor $audit) {}

    /**
     * @param  array<string, int>  $unitsToRevoke  kind value => unused units to take back (administrator's decision)
     */
    public function request(
        Order $order,
        RefundKind $kind,
        int $amountCents,
        string $reason,
        array $unitsToRevoke = [],
        bool $revokeEntitlement = false,
        ?User $by = null,
        ?string $idempotencyKey = null,
    ): RefundCase {
        $order->refresh();

        // A repeated request with the same key is the same refund – answered before any validation or Stripe call.
        $key = 'refund:'.$order->id.':'.($idempotencyKey ? Str::slug($idempotencyKey) : Str::uuid());
        $existing = RefundCase::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        if (! $order->status->isSettled() || ! $order->stripe_payment_intent_id) {
            throw new InvalidArgumentException('Objednávka nie je zaplatená alebo nemá platbu, ktorú by bolo možné vrátiť.');
        }
        $refundable = $order->amount_cents - $this->refundedCents($order);
        if ($amountCents < 1 || $amountCents > $refundable) {
            throw new InvalidArgumentException("Suma musí byť 1 až {$refundable} centov.");
        }

        // Units are checked before any money moves: a refund must never succeed in Stripe and fail here.
        $this->assertUnitsAvailable($order, $unitsToRevoke);

        $case = RefundCase::create([
            'order_id' => $order->id,
            'household_id' => $order->household_id,
            'kind' => $kind,
            'amount_cents' => $amountCents,
            'currency' => $order->currency,
            'reason' => $reason,
            'units_revoked' => $unitsToRevoke,
            'revoke_entitlement' => $revokeEntitlement,
            'stripe_payment_intent_id' => $order->stripe_payment_intent_id,
            'idempotency_key' => $key,
            'status' => RefundStatus::Requested,
            'requested_by' => $by?->id,
        ]);

        try {
            $refund = $this->gateway->refund($order->stripe_payment_intent_id, $amountCents, $key, [
                'order_id' => (string) $order->id,
                'refund_case_id' => (string) $case->id,
                'kind' => $kind->value,
            ]);
        } catch (Throwable $e) {
            $case->update(['status' => RefundStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            $this->audit->record('billing.refund.failed', $case, [], ['error' => $e->getMessage()], $reason, $by);

            return $case;
        }

        DB::transaction(function () use ($case, $refund, $order, $unitsToRevoke, $revokeEntitlement, $reason, $by) {
            $case->update(['status' => RefundStatus::Processed, 'stripe_refund_id' => $refund['id'], 'processed_at' => now()]);
            $this->revokeUnits($order, $unitsToRevoke, 'refund:'.$case->id, $by, $reason);
            if ($revokeEntitlement) {
                $this->revokeEntitlements($order, $reason);
            }
            $this->syncOrderStatus($order->fresh());
        });

        $this->audit->record('billing.refund.processed', $case, [], [
            'order_id' => $order->id,
            'amount_cents' => $amountCents,
            'kind' => $kind->value,
            'units_revoked' => $unitsToRevoke,
            'revoke_entitlement' => $revokeEntitlement,
            'stripe_refund_id' => $refund['id'],
        ], $reason, $by);

        return $case;
    }

    /**
     * A refund Stripe reported: ours → confirmed, unknown (dashboard) → a case for review. Never revokes on its own.
     */
    public function reconcileStripeRefund(Order $order, string $stripeRefundId, int $amountCents, string $currency): RefundCase
    {
        $case = RefundCase::query()->where('stripe_refund_id', $stripeRefundId)->first();
        if ($case !== null) {
            if ($case->status === RefundStatus::Requested) {
                $case->update(['status' => RefundStatus::Processed, 'processed_at' => now()]);
            }

            return $case;
        }

        return RefundCase::query()->firstOrCreate(['idempotency_key' => 'stripe-refund:'.$stripeRefundId], [
            'order_id' => $order->id,
            'household_id' => $order->household_id,
            'kind' => RefundKind::External,
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'reason' => 'Refundácia vykonaná mimo aplikácie (Stripe). Odobratie nárokov vyžaduje rozhodnutie administrátora.',
            'stripe_refund_id' => $stripeRefundId,
            'stripe_payment_intent_id' => $order->stripe_payment_intent_id,
            'status' => RefundStatus::NeedsReview,
        ]);
    }

    /** Charge payload without a refund list: record the difference between what Stripe refunded and what we know. */
    public function reconcileRefundedTotal(Order $order, int $amountRefunded): void
    {
        $known = $this->refundedCents($order);
        if ($amountRefunded > $known) {
            $this->reconcileStripeRefund($order, 'charge:'.$order->stripe_payment_intent_id.':'.$amountRefunded, $amountRefunded - $known, $order->currency);
        }
    }

    /** A card dispute suspends what the disputed payment bought; the account stays. Manual review follows. */
    public function openDispute(Order $order, string $disputeId, int $amountCents, string $reason): RefundCase
    {
        return DB::transaction(function () use ($order, $disputeId, $amountCents, $reason) {
            $case = RefundCase::query()->firstOrCreate(['idempotency_key' => 'dispute:'.$disputeId], [
                'order_id' => $order->id,
                'household_id' => $order->household_id,
                'kind' => RefundKind::Dispute,
                'amount_cents' => $amountCents,
                'currency' => $order->currency,
                'reason' => 'Spor o platbu (chargeback): '.$reason,
                'stripe_dispute_id' => $disputeId,
                'stripe_payment_intent_id' => $order->stripe_payment_intent_id,
                'status' => RefundStatus::Disputed,
            ]);

            if ($case->wasRecentlyCreated) {
                $units = [];
                foreach ($this->grantsOf($order) as $grant) {
                    $units[$grant->kind->value] = ($units[$grant->kind->value] ?? 0) + $grant->available();
                }
                $this->revokeUnits($order, $units, 'dispute:'.$disputeId, null, 'Pozastavené pre spor o platbu');
                $this->revokeEntitlements($order, 'Spor o platbu '.$disputeId);
                $case->update(['units_revoked' => $units, 'revoke_entitlement' => true]);
                $this->audit->record('billing.dispute.opened', $case, [], ['order_id' => $order->id, 'units_revoked' => $units], $reason);
            }

            return $case;
        });
    }

    public function syncOrderStatus(Order $order): void
    {
        $refunded = $this->refundedCents($order);
        if ($refunded <= 0) {
            return;
        }
        $order->update(['status' => $refunded >= $order->amount_cents ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded]);
    }

    public function refundedCents(Order $order): int
    {
        return (int) $order->refundCases()->whereIn('status', [RefundStatus::Processed, RefundStatus::NeedsReview])->sum('amount_cents');
    }

    /** @return Collection<int, UsageGrant> */
    private function grantsOf(Order $order)
    {
        $entitlementIds = $order->entitlements()->pluck('id');

        return UsageGrant::query()
            ->where(fn ($q) => $q->where('order_id', $order->id)->orWhereIn('paid_entitlement_id', $entitlementIds))
            ->orderByDesc('id')
            ->get();
    }

    /** @param  array<string, int>  $units */
    private function assertUnitsAvailable(Order $order, array $units): void
    {
        $grants = $this->grantsOf($order);
        foreach ($units as $kindValue => $wanted) {
            $kind = UsageKind::tryFrom((string) $kindValue);
            if ($kind === null) {
                throw new InvalidArgumentException("Neznámy druh použitia {$kindValue}.");
            }
            $available = (int) $grants->where('kind', $kind)->sum(fn (UsageGrant $g) => $g->available());
            if ((int) $wanted > $available) {
                throw new InvalidArgumentException("Objednávka má iba {$available} nevyužitých použití ({$kind->label()}), nie {$wanted}.");
            }
        }
    }

    /** @param  array<string, int>  $units */
    private function revokeUnits(Order $order, array $units, string $keyPrefix, ?User $by, string $note): void
    {
        $grants = $this->grantsOf($order);
        foreach ($units as $kindValue => $wanted) {
            $kind = UsageKind::tryFrom((string) $kindValue);
            $remaining = (int) $wanted;
            if ($kind === null || $remaining < 1) {
                continue;
            }
            foreach ($grants->where('kind', $kind) as $grant) {
                $take = min($remaining, $grant->fresh()->available());
                if ($take < 1) {
                    continue;
                }
                $this->ledger->revoke($grant, $take, $keyPrefix.':grant:'.$grant->id, $by, $note);
                $remaining -= $take;
                if ($remaining < 1) {
                    break;
                }
            }
            if ($remaining > 0) {
                throw new InvalidArgumentException("Objednávka nemá {$wanted} nevyužitých použití ({$kind->label()}); chýba {$remaining}.");
            }
        }
    }

    private function revokeEntitlements(Order $order, string $reason): void
    {
        $order->entitlements()->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => mb_substr($reason, 0, 500), 'updated_at' => now()]);
    }
}

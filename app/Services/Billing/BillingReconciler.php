<?php

namespace App\Services\Billing;

use App\Enums\OrderStatus;
use App\Enums\StripeEventState;
use App\Models\Household;
use App\Models\Order;
use App\Models\PaidEntitlement;
use App\Models\StripeEvent;
use App\Services\Usage\UsageProvisioner;

/**
 * Daily housekeeping: open the current monthly grants, close abandoned checkouts, retry failed webhook events.
 * Everything here is idempotent; running it twice changes nothing.
 */
class BillingReconciler
{
    public const MAX_EVENT_ATTEMPTS = 5;

    public function __construct(private UsageProvisioner $provisioner, private StripeEventProcessor $events) {}

    /** @return array{grants_opened: int, orders_expired: int, events_retried: int, events_failed: int} */
    public function run(): array
    {
        $grants = 0;
        PaidEntitlement::query()
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->distinct()
            ->pluck('household_id')
            ->each(function (int $householdId) use (&$grants) {
                $household = Household::find($householdId);
                if ($household !== null) {
                    $grants += count($this->provisioner->openCurrentGrants($household));
                }
            });

        $expired = Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('created_at', '<', now()->subHours((int) config('recipes.billing.pending_order_ttl_hours', 24)))
            ->update(['status' => OrderStatus::Expired->value, 'updated_at' => now()]);

        $retried = 0;
        $stillFailed = 0;
        StripeEvent::query()
            ->where('state', StripeEventState::Failed)
            ->where('attempts', '<', self::MAX_EVENT_ATTEMPTS)
            ->orderBy('id')
            ->each(function (StripeEvent $event) use (&$retried, &$stillFailed) {
                $this->events->process($event);
                $retried++;
                if ($event->fresh()->state === StripeEventState::Failed) {
                    $stillFailed++;
                }
            });

        return ['grants_opened' => $grants, 'orders_expired' => $expired, 'events_retried' => $retried, 'events_failed' => $stillFailed];
    }
}

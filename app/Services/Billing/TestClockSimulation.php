<?php

namespace App\Services\Billing;

use App\Models\BillingAccount;
use App\Models\Household;
use App\Models\PaidEntitlement;
use App\Models\UsageGrant;
use App\Services\Admin\AdminAuditor;
use App\Services\Admin\AppSettings;
use App\Services\Billing\Gateway\StripeGateway;
use App\Services\Billing\Gateway\StripeTestClocks;
use App\Services\Usage\UsageProvisioner;
use App\Support\StripeDashboard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stage 7 staging aid: a household whose Stripe customer lives on a test clock, so renewals, monthly grants of the
 * annual plan, cancellation and failed renewals are verified against the real (sandbox) Stripe account through the
 * same webhooks production will receive. Never available with live keys.
 */
class TestClockSimulation
{
    public const REGISTRY_KEY = 'billing.test_clocks';

    public function __construct(
        private StripeTestClocks $clocks,
        private StripeGateway $gateway,
        private BillingAccounts $accounts,
        private Catalog $catalog,
        private PlanStatus $plans,
        private UsageProvisioner $provisioner,
        private AppSettings $settings,
        private AdminAuditor $audit,
    ) {}

    /**
     * @return array{clock: string, customer: string, subscription: string, status: string}
     */
    public function start(Household $household, string $planCode, CarbonInterface $frozenAt, ?string $name = null): array
    {
        $this->assertSandbox();

        $plan = $this->catalog->plan($planCode) ?? throw new InvalidArgumentException("Plán „{$planCode}“ nie je v aktívnom katalógu.");
        if (! $plan->stripe_price_id) {
            throw new InvalidArgumentException("Plán „{$planCode}“ nemá Stripe price ID – je nepredajný.");
        }

        $account = $this->accounts->forHousehold($household);
        if ($account->stripe_id) {
            throw new InvalidArgumentException("Domácnosť {$household->id} už má Stripe zákazníka ({$account->stripe_id}); test clock potrebuje novú domácnosť bez zákazníka.");
        }
        if ($this->plans->hasSubscription($household)) {
            throw new InvalidArgumentException("Domácnosť {$household->id} už má predplatné.");
        }

        $clock = $this->clocks->create($name ?? "Recepty #{$household->id} {$planCode} ".$frozenAt->toDateString(), $frozenAt);

        $result = $this->clocks->startSubscription($account, $plan->stripe_price_id, $clock['id'], [
            'household_id' => (string) $household->id,
            'plan_code' => $planCode,
            'plan_version_id' => (string) $plan->id,
            'test_clock' => $clock['id'],
        ]);

        $registry = $this->registry();
        $registry[$clock['id']] = [
            'household_id' => $household->id,
            'plan_code' => $planCode,
            'frozen_time' => $frozenAt->toIso8601String(),
            'customer' => $result['customer'],
            'subscription' => $result['subscription'],
            'created_at' => CarbonImmutable::now()->toIso8601String(),
        ];
        $this->settings->set(self::REGISTRY_KEY, $registry);

        $this->audit->record('billing.test_clock.started', $household, [], ['clock' => $clock['id'], 'plan' => $planCode, 'frozen_time' => $frozenAt->toIso8601String(), 'subscription' => $result['subscription']], 'staging simulation');

        return ['clock' => $clock['id'], ...$result];
    }

    /**
     * Advance and wait (polling) until Stripe reports the clock ready; the webhooks it produces arrive meanwhile.
     *
     * @return array{id: string, frozen_time: int, status: string}
     */
    public function advance(string $clockId, CarbonInterface $to, int $waitSeconds = 90): array
    {
        $this->assertSandbox();

        $clock = $this->clocks->advance($clockId, $to);
        $deadline = microtime(true) + $waitSeconds;
        while ($clock['status'] !== 'ready' && microtime(true) < $deadline) {
            sleep(2);
            $clock = $this->clocks->retrieve($clockId) ?? throw new RuntimeException("Test clock {$clockId} po posune neexistuje.");
        }

        $household = $this->householdFor($clockId);
        if ($household !== null) {
            // What the daily scheduler would do: open the current monthly grant for the advanced period.
            $this->provisioner->openCurrentGrants($household, CarbonImmutable::createFromTimestamp($clock['frozen_time']));
        }

        $this->audit->record('billing.test_clock.advanced', $household, [], ['clock' => $clockId, 'to' => $to->toIso8601String(), 'status' => $clock['status']], 'staging simulation');

        return $clock;
    }

    /**
     * Local view of what the simulation produced so far (Stripe row + Cashier subscription + entitlements + grants).
     *
     * @return array{clock: array{id: string, frozen_time: int, status: string}|null, household: Household|null, account: BillingAccount|null, subscription: array{stripe_id: string, status: string, ends_at: string|null}|null, entitlements: list<array{id: int, from: string, to: string, invoice: string|null, revoked: string|null}>, grants: list<array{id: int, kind: string, source: string, key: string, quantity: int, used: int, from: string, to: string|null}>}
     */
    public function status(string $clockId, bool $sync = false): array
    {
        $household = $this->householdFor($clockId);
        $account = $household?->billingAccount;
        $subscription = $household !== null ? $this->plans->subscription($household) : null;

        if ($sync && $subscription !== null) {
            $this->gateway->syncSubscription($subscription);
            $subscription->refresh();
        }

        return [
            'clock' => $this->clocks->retrieve($clockId),
            'household' => $household,
            'account' => $account,
            'subscription' => $subscription === null ? null : [
                'stripe_id' => $subscription->stripe_id,
                'status' => $subscription->stripe_status,
                'ends_at' => $subscription->ends_at?->toDateTimeString(),
            ],
            'entitlements' => $household === null ? [] : array_values(PaidEntitlement::query()->where('household_id', $household->id)->orderBy('starts_at')->get()
                ->map(fn (PaidEntitlement $e) => [
                    'id' => $e->id,
                    'from' => $e->starts_at->toDateTimeString(),
                    'to' => $e->ends_at->toDateTimeString(),
                    'invoice' => $e->stripe_invoice_id,
                    'revoked' => $e->revoked_at?->toDateTimeString(),
                ])->all()),
            'grants' => $household === null ? [] : array_values(UsageGrant::query()->where('household_id', $household->id)->orderBy('valid_from')->get()
                ->map(fn (UsageGrant $g) => [
                    'id' => $g->id,
                    'kind' => $g->kind->value,
                    'source' => $g->source->value,
                    'key' => $g->source_key,
                    'quantity' => $g->quantity,
                    'used' => $g->consumed_quantity,
                    'from' => $g->valid_from->toDateTimeString(),
                    'to' => $g->expires_at?->toDateTimeString(),
                ])->all()),
        ];
    }

    /** Deletes the clock in Stripe (customer and subscriptions with it). Local rows stay for inspection. */
    public function delete(string $clockId): void
    {
        $this->assertSandbox();

        $this->clocks->delete($clockId);
        $registry = $this->registry();
        $household = $this->householdFor($clockId);
        unset($registry[$clockId]);
        $this->settings->set(self::REGISTRY_KEY, $registry === [] ? null : $registry);

        $this->audit->record('billing.test_clock.deleted', $household, [], ['clock' => $clockId], 'staging simulation');
    }

    /** @return array<string, array{household_id: int, plan_code: string, frozen_time: string, customer: string, subscription: string, created_at: string}> */
    public function registry(): array
    {
        $registry = $this->settings->get(self::REGISTRY_KEY);

        return is_array($registry) ? $registry : [];
    }

    public function householdFor(string $clockId): ?Household
    {
        $entry = $this->registry()[$clockId] ?? null;

        return $entry !== null ? Household::find((int) $entry['household_id']) : null;
    }

    private function assertSandbox(): void
    {
        if (StripeDashboard::isLive()) {
            throw new RuntimeException('Test clock je dostupný iba so sandbox kľúčmi (sk_test_…); so živými kľúčmi sa simulácia nespúšťa.');
        }
    }
}

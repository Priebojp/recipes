<?php

use App\Enums\OrderStatus;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Enums\UsageKind;
use App\Models\AdminAudit;
use App\Models\PaidEntitlement;
use App\Models\RefundCase;
use App\Models\UsageGrant;
use App\Models\UsageLedgerEntry;
use App\Services\Billing\BillingReconciler;
use App\Services\Billing\PlanStatus;
use App\Services\Billing\RefundService;
use App\Services\Usage\UsageLedger;
use Tests\Support\BillingScenario;
use Tests\Support\StripePayloads as Stripe;

it('refunds a pack fully, revokes only its unused units and ignores a replayed payment event afterwards', function () {
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('images_20_standard');
    BillingScenario::payAddon($order);
    $other = BillingScenario::startAddon('images_20_standard');
    BillingScenario::payAddon($other);

    $case = app(RefundService::class)->request($order, RefundKind::Withdrawal, 399, 'Odstúpenie do 14 dní', ['image_standard' => 20], by: $h['user']);

    expect($case->status)->toBe(RefundStatus::Processed)
        ->and($case->stripe_refund_id)->toBe($h['stripe']->refunds[0]['id'])
        ->and($h['stripe']->refunds[0]['amount'])->toBe(399)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and(UsageGrant::query()->where('order_id', $order->id)->sole()->available())->toBe(0)
        ->and(UsageGrant::query()->where('order_id', $other->id)->sole()->available())->toBe(20)
        ->and(UsageLedgerEntry::query()->where('reason', 'revoked')->count())->toBe(1)
        ->and(AdminAudit::query()->where('action', 'billing.refund.processed')->count())->toBe(1);

    Stripe::post($this, Stripe::checkoutCompleted($order, 'payment'))->assertOk();
    Stripe::post($this, Stripe::chargeRefunded('pi_'.$order->id, 399, 399, [['id' => $case->stripe_refund_id, 'amount' => 399]]))->assertOk();
    expect(UsageGrant::query()->where('order_id', $order->id)->sole()->available())->toBe(0)
        ->and(RefundCase::count())->toBe(1)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::ImageStandard))->toBe(21);
});

it('takes back only the units the administrator names on a partial refund and refuses more than are unused', function () {
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('text_100');
    BillingScenario::payAddon($order);
    $grant = UsageGrant::query()->where('order_id', $order->id)->sole();
    app(UsageLedger::class)->revoke($grant, 0 + 1, 'used:1'); // pretend one unit is gone

    expect(fn () => app(RefundService::class)->request($order, RefundKind::Goodwill, 100, 'x', ['text' => 100]))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(RefundService::class)->request($order, RefundKind::Goodwill, 500, 'x'))->toThrow(InvalidArgumentException::class);

    $case = app(RefundService::class)->request($order, RefundKind::Goodwill, 100, 'Polovičná kompenzácia', ['text' => 50], idempotencyKey: 'ticket-7');
    $again = app(RefundService::class)->request($order, RefundKind::Goodwill, 100, 'Polovičná kompenzácia', ['text' => 50], idempotencyKey: 'ticket-7');

    expect($again->id)->toBe($case->id)
        ->and(count($h['stripe']->refunds))->toBe(1)
        ->and($grant->fresh()->available())->toBe(49)
        ->and($order->fresh()->status)->toBe(OrderStatus::PartiallyRefunded);
});

it('revokes the paid period of a refunded subscription and a replayed invoice does not restore it', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $order = BillingScenario::startPlan('plus_yearly');
    BillingScenario::paySubscription($order, now()->timestamp, now()->addYear()->timestamp);
    $plans = app(PlanStatus::class);
    expect($plans->isPlus($h['household']))->toBeTrue();

    $this->artisan('app:billing-refund', ['order' => $order->id, 'amount' => 2400, '--kind' => 'withdrawal', '--reason' => 'odstúpenie', '--text' => 30, '--images' => 5, '--revoke-plus' => true])->assertSuccessful();

    expect(PaidEntitlement::sole()->revoked_at)->not->toBeNull()
        ->and($plans->isPlus($h['household']))->toBeFalse()
        ->and(app(UsageLedger::class)->balance($h['household'], UsageKind::Text)->includedAvailable)->toBe(3); // trial stays

    Stripe::post($this, Stripe::invoicePaid($order->billingAccount, 'in_'.$order->id.'_1', 'sub_'.$order->id, 'price_plus_yearly', now()->timestamp, now()->addYear()->timestamp, 'subscription_create', orderId: $order->id))->assertOk();
    $this->travel(2)->months();
    app(BillingReconciler::class)->run();

    expect(PaidEntitlement::count())->toBe(1)
        ->and(PaidEntitlement::sole()->revoked_at)->not->toBeNull()
        ->and(UsageGrant::query()->where('source', 'subscription')->count())->toBe(2) // no new monthly grants after the refund
        ->and($plans->isPlus($h['household']))->toBeFalse();
});

it('records a refund made in the Stripe dashboard for review without touching uses, and suspends a disputed purchase', function () {
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('text_100');
    BillingScenario::payAddon($order);

    Stripe::post($this, Stripe::chargeRefunded('pi_'.$order->id, 199, 100, [['id' => 're_dashboard', 'amount' => 100]]))->assertOk();
    $case = RefundCase::sole();
    expect($case->kind)->toBe(RefundKind::External)
        ->and($case->status)->toBe(RefundStatus::NeedsReview)
        ->and($case->amount_cents)->toBe(100)
        ->and(UsageGrant::query()->where('order_id', $order->id)->sole()->available())->toBe(100)
        ->and($order->fresh()->status)->toBe(OrderStatus::PartiallyRefunded);

    Stripe::post($this, Stripe::disputeCreated('pi_'.$order->id, 199))->assertOk();
    Stripe::post($this, Stripe::disputeCreated('pi_'.$order->id, 199))->assertOk();
    $dispute = RefundCase::query()->where('kind', RefundKind::Dispute)->sole();
    expect($dispute->status)->toBe(RefundStatus::Disputed)
        ->and($dispute->units_revoked)->toBe(['text' => 100])
        ->and(UsageGrant::query()->where('order_id', $order->id)->sole()->available())->toBe(0)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::Text))->toBe(3)
        ->and($h['user']->fresh())->not->toBeNull();
});

it('keeps a failed Stripe refund as a failed case without revoking anything', function () {
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('text_100');
    BillingScenario::payAddon($order);
    $h['stripe']->failRefunds = true;

    $case = app(RefundService::class)->request($order, RefundKind::Goodwill, 199, 'test', ['text' => 100]);

    expect($case->status)->toBe(RefundStatus::Failed)
        ->and($case->error)->toContain('already refunded')
        ->and(UsageGrant::query()->where('order_id', $order->id)->sole()->available())->toBe(100)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

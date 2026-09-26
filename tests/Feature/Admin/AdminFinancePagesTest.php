<?php

use App\Enums\RefundKind;
use App\Services\Billing\FinanceReport;
use App\Services\Billing\RefundService;
use Tests\Support\BillingScenario;

it('refuses the finance modules to a household owner and opens them for an administrator', function () {
    household();
    foreach (['admin.subscriptions', 'admin.orders', 'admin.refunds', 'admin.usage', 'admin.catalog', 'admin.stripe-events'] as $route) {
        $this->get(route($route))->assertForbidden();
    }

    actingAsPlatformAdmin();
    $this->get(route('admin.index'))->assertOk()->assertSee('MRR');
    $this->get(route('admin.households'))->assertOk()->assertSee('všetky plány');
    $this->get(route('admin.subscriptions'))->assertOk()->assertSee('Predplatné');
    $this->get(route('admin.orders'))->assertOk()->assertSee('Balíky a objednávky');
    $this->get(route('admin.refunds'))->assertOk()->assertSee('Refundácie a spory');
    $this->get(route('admin.usage'))->assertOk()->assertSee('ledger');
    $this->get(route('admin.catalog'))->assertOk()->assertSee('Katalóg');
    $this->get(route('admin.stripe-events'))->assertOk()->assertSee('Stripe udalosti');
});

it('re-confirms the password before pages with financial actions', function () {
    $admin = actingAsPlatformAdmin();
    $this->flushSession();
    $this->actingAs($admin);

    $this->get(route('admin.catalog'))->assertRedirect(route('password.confirm'));
    $this->get(route('admin.subscriptions'))->assertRedirect(route('password.confirm'));
    $this->get(route('admin.orders'))->assertOk();
});

it('normalises MRR per month and keeps cash, refunds and revenue apart', function () {
    $this->freezeTime();
    config()->set('recipes.billing.usd_eur_rate', 0.9);

    // Household A: yearly plan (24 €) + a 3,99 € pack, partly refunded.
    $a = BillingScenario::start();
    $yearly = BillingScenario::startPlan('plus_yearly');
    BillingScenario::paySubscription($yearly, now()->timestamp, now()->addYear()->timestamp);
    $pack = BillingScenario::startAddon('images_20_standard');
    BillingScenario::payAddon($pack);
    app(RefundService::class)->request($pack, RefundKind::Goodwill, 100, 'test', ['image_standard' => 5], by: $a['user']);

    // Household B: monthly plan (2,49 €).
    $b = household();
    $monthly = BillingScenario::startPlan('plus_monthly');
    BillingScenario::paySubscription($monthly, now()->timestamp, now()->addMonth()->timestamp);

    // Household C: only a trial – never counts as paying.
    household();

    $report = app(FinanceReport::class);
    $recurring = $report->recurring();
    expect($recurring['paying_households'])->toBe(2)
        ->and($recurring['mrr_cents'])->toBe(249 + 200)
        ->and($recurring['monthly'])->toBe(1)
        ->and($recurring['yearly'])->toBe(1);

    $period = $report->period(now()->startOfDay(), now()->endOfDay());
    expect($period['cash_cents'])->toBe(2400 + 399 + 249)
        ->and($period['orders_paid'])->toBe(3)
        ->and($period['addons_cents'])->toBe(399)
        ->and($period['refunds_cents'])->toBe(100)
        ->and($period['refunds_count'])->toBe(1)
        ->and($period['contribution_cents'])->toBe(2400 + 399 + 249 - 100)
        ->and($period['revenue_cents'])->toBeLessThan($period['cash_cents']); // the yearly payment is spread over the year

    // Half a year later the yearly plan still pays, the monthly one has ended, and revenue keeps accruing.
    $this->travel(6)->months();
    expect($report->recurring()['paying_households'])->toBe(1)
        ->and($report->recurring()['mrr_cents'])->toBe(200)
        ->and($report->period(now()->startOfMonth(), now()->endOfMonth())['cash_cents'])->toBe(0)
        ->and($report->period(now()->startOfMonth(), now()->endOfMonth())['revenue_cents'])->toBeGreaterThan(150);

    actingAsPlatformAdmin();
    $this->get(route('admin.index'))->assertOk()->assertSee('2,00 €')->assertSee('Príspevok po variabilných nákladoch');
    $this->get(route('admin.households', ['plan' => 'plus']))->assertOk()->assertSee($a['household']->name)->assertDontSee($b['household']->name);
    $this->get(route('admin.orders', ['status' => 'partially_refunded']))->assertOk()->assertSee('#'.$pack->id);
    $this->get(route('admin.refunds'))->assertOk()->assertSee('1,00 €');
    $this->get(route('admin.subscriptions'))->assertOk()->assertSee('sub_'.$yearly->id);
});

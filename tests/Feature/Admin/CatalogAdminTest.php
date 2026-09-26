<?php

use App\Enums\CatalogState;
use App\Enums\UsageKind;
use App\Models\AddonVersion;
use App\Models\AdminAudit;
use App\Models\PaidEntitlement;
use App\Models\PlanVersion;
use App\Services\Billing\Catalog;
use App\Services\Billing\PlanStatus;
use App\Services\Usage\UsageLedger;
use App\Support\CurrentHousehold;
use Livewire\Livewire;
use Tests\Support\BillingScenario;

it('sells a new price only after activation and never re-prices what was already bought', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $order = BillingScenario::startPlan('plus_monthly');
    BillingScenario::paySubscription($order, now()->timestamp, now()->addMonth()->timestamp);
    $v1 = PlanVersion::query()->where('code', 'plus_monthly')->sole();

    $admin = actingAsPlatformAdmin();
    $this->get(route('admin.catalog'))->assertOk()->assertSee('plus_monthly')->assertSee('2,49 €');

    Livewire::test('pages::admin.catalog')
        ->call('startDraft', 'plan', $v1->id)
        ->assertSet('draft_price', '2,49')
        ->set('draft_price', '2,99')
        ->set('draft_text_uses', '40')
        ->set('draft_stripe_price_id', 'price_plus_monthly_v2')
        ->set('draft_reason', 'Cenník 2027')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $v2 = PlanVersion::query()->where('code', 'plus_monthly')->where('version', 2)->sole();
    expect($v2->state)->toBe(CatalogState::Draft)
        ->and($v2->final_price_cents)->toBe(299)
        ->and($v2->text_uses_per_period)->toBe(40)
        ->and($v2->interval)->toBe($v1->interval)
        ->and(app(Catalog::class)->plan('plus_monthly')->id)->toBe($v1->id); // drafts are not sold

    Livewire::test('pages::admin.catalog')
        ->call('activate', 'plan', $v2->id)
        ->assertHasErrors(['reason'])
        ->set('reason', 'Potvrdené vlastníkom')
        ->call('activate', 'plan', $v2->id)
        ->assertHasNoErrors();

    expect($v1->fresh()->state)->toBe(CatalogState::Retired)
        ->and($v2->fresh()->state)->toBe(CatalogState::Active)
        ->and(app(Catalog::class)->plan('plus_monthly')->id)->toBe($v2->id)
        ->and(AdminAudit::query()->where('action', 'catalog.plan.activated')->where('actor_id', $admin->id)->exists())->toBeTrue();

    // Test 14: the sold snapshot, the paid period and its plan version are untouched; the old price still resolves.
    $order->refresh();
    expect($order->amount_cents)->toBe(249)
        ->and($order->product_snapshot['final_price_cents'])->toBe(249)
        ->and($order->plan_version_id)->toBe($v1->id)
        ->and(PaidEntitlement::sole()->plan_version_id)->toBe($v1->id)
        ->and(app(PlanStatus::class)->isPlus($h['household']))->toBeTrue()
        ->and(app(Catalog::class)->planForStripePrice('price_plus_monthly')?->id)->toBe($v1->id);

    // A new customer pays the new price.
    household();
    $next = BillingScenario::startPlan('plus_monthly');
    expect($next->amount_cents)->toBe(299)
        ->and($next->product_snapshot['stripe_price_id'])->toBe('price_plus_monthly_v2')
        ->and($h['stripe']->checkouts[1]['price'])->toBe('price_plus_monthly_v2');
});

it('retires an add-on version so it disappears from the offer while paid packs stay usable', function () {
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('text_100');
    BillingScenario::payAddon($order);
    actingAsPlatformAdmin();
    $addon = AddonVersion::query()->where('code', 'text_100')->sole();

    Livewire::test('pages::admin.catalog')
        ->set('reason', 'Balík sa nahrádza')
        ->call('retire', 'addon', $addon->id)
        ->assertHasNoErrors();

    expect($addon->fresh()->state)->toBe(CatalogState::Retired)
        ->and(app(Catalog::class)->addon('text_100'))->toBeNull()
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::Text))->toBe(103)
        ->and(AdminAudit::query()->where('action', 'catalog.addon.retired')->exists())->toBeTrue();

    $this->actingAs($h['user']);
    app(CurrentHousehold::class)->set($h['household']);
    $this->from(route('pricing'))->post(route('checkout.addon'), ['addon' => 'text_100'])->assertSessionHasErrors('addon');
});

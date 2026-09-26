<?php

use App\Enums\AiJobKind;
use App\Enums\UsageKind;
use App\Models\AdminAudit;
use App\Models\Order;
use App\Models\PaidEntitlement;
use App\Models\Person;
use App\Models\UsageGrant;
use App\Services\Ai\AiAvailability;
use App\Services\Billing\PlanStatus;
use App\Services\Usage\UsageLedger;
use App\Support\CurrentHousehold;
use Livewire\Livewire;
use Tests\Support\BillingScenario;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
});

it('issues a compensation grant with an audited reason and never twice for the same key', function () {
    $h = BillingScenario::start();
    $admin = actingAsPlatformAdmin();

    Livewire::test('pages::admin.household', ['household' => $h['household']])
        ->assertSee('Kompenzačné použitia')
        ->set('comp_kind', 'image_standard')
        ->set('comp_quantity', '4')
        ->set('comp_reason', 'Výpadok generovania 12. 3.')
        ->set('comp_key', 'ticket-9')
        ->call('grantUses')
        ->assertHasNoErrors()
        ->set('comp_kind', 'image_standard')
        ->set('comp_quantity', '4')
        ->set('comp_reason', 'Výpadok generovania 12. 3.')
        ->set('comp_key', 'ticket-9')
        ->call('grantUses')
        ->assertHasNoErrors();

    $grant = UsageGrant::query()->where('source', 'compensation')->sole();
    expect($grant->quantity)->toBe(4)
        ->and($grant->created_by)->toBe($admin->id)
        ->and($grant->note)->toBe('Výpadok generovania 12. 3.')
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::ImageStandard))->toBe(5)
        ->and(AdminAudit::query()->where('action', 'usage.compensation.granted')->count())->toBe(1);

    Livewire::test('pages::admin.household', ['household' => $h['household']])
        ->set('comp_reason', '')
        ->call('grantUses')
        ->assertHasErrors(['comp_reason']);
});

it('grants a time-limited Plus period without a payment and can end it early', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    actingAsPlatformAdmin();
    $plans = app(PlanStatus::class);
    expect($plans->isPlus($h['household']))->toBeFalse();

    Livewire::test('pages::admin.household', ['household' => $h['household']])
        ->set('plus_plan', 'plus_monthly')
        ->set('plus_from', now()->toDateString())
        ->set('plus_to', now()->addDays(14)->toDateString())
        ->set('plus_reason', 'Kompenzácia za výpadok')
        ->call('grantPlus')
        ->assertHasNoErrors();

    $entitlement = PaidEntitlement::sole();
    expect($entitlement->order_id)->toBeNull()
        ->and($entitlement->source_key)->toStartWith('compensation:plus:')
        ->and($plans->isPlus($h['household']))->toBeTrue()
        ->and(UsageGrant::query()->where('source', 'subscription')->where('paid_entitlement_id', $entitlement->id)->count())->toBe(2)
        ->and(AdminAudit::query()->where('action', 'billing.plus.granted')->exists())->toBeTrue()
        ->and(Order::count())->toBe(0);

    Livewire::test('pages::admin.household', ['household' => $h['household']])
        ->call('revokePlus', $entitlement->id)
        ->assertHasNoErrors();

    expect($entitlement->fresh()->revoked_at)->not->toBeNull()
        ->and($plans->isPlus($h['household']))->toBeFalse();
});

it('blocks a household from new AI jobs and purchases, keeps its data and lifts the block with an audit trail', function () {
    $h = BillingScenario::start();
    actingAsPlatformAdmin();

    Livewire::test('pages::admin.household', ['household' => $h['household']])
        ->call('block')
        ->assertHasErrors(['block_reason'])
        ->set('block_reason', 'Opakované zneužívanie skúšobných účtov')
        ->call('block')
        ->assertHasNoErrors();

    $household = $h['household']->fresh();
    expect($household->isBlocked())->toBeTrue()
        ->and(app(AiAvailability::class)->reasonUnavailable($household, AiJobKind::Text))->toContain('pozastavené')
        ->and(app(UsageLedger::class)->available($household, UsageKind::Text))->toBe(3)
        ->and(AdminAudit::query()->where('action', 'household.blocked')->sole()->reason)->toBe('Opakované zneužívanie skúšobných účtov');

    $this->actingAs($h['user']);
    app(CurrentHousehold::class)->set($household);
    $this->from(route('pricing'))->post(route('checkout.plan'), ['plan' => 'plus_monthly'])->assertSessionHasErrors('plan');
    $this->from(route('pricing'))->post(route('checkout.addon'), ['addon' => 'text_100'])->assertSessionHasErrors('addon');
    expect(Order::count())->toBe(0);

    actingAsPlatformAdmin();
    Livewire::test('pages::admin.household', ['household' => $household])
        ->set('block_reason', 'Vysvetlené, prípad uzavretý')
        ->call('unblock')
        ->assertHasNoErrors();

    expect($household->fresh()->isBlocked())->toBeFalse()
        ->and(app(AiAvailability::class)->reasonUnavailable($household->fresh(), AiJobKind::Text))->toBeNull()
        ->and(AdminAudit::query()->where('action', 'household.unblocked')->exists())->toBeTrue();
});

it('shows the household detail with plan, grants and orders and lets the administrator manage renewal through the gateway', function () {
    $h = BillingScenario::start();
    $this->freezeTime();
    $order = BillingScenario::startPlan('plus_monthly');
    BillingScenario::paySubscription($order, now()->timestamp, now()->addMonth()->timestamp);
    Person::create(['household_id' => $h['household']->id, 'name' => 'Tajný Stravník']);
    actingAsPlatformAdmin();

    $this->get(route('admin.households.show', $h['household']))
        ->assertOk()
        ->assertSee('Plus')
        ->assertSee('sub_'.$order->id)
        ->assertSee('#'.$order->id)
        ->assertDontSee('Tajný Stravník');

    Livewire::test('pages::admin.household', ['household' => $h['household']])
        ->call('syncSubscription')
        ->call('cancelRenewal')
        ->assertHasErrors(['subscription_reason'])
        ->set('subscription_reason', 'Na žiadosť zákazníka (e-mail 26. 9.)')
        ->call('cancelRenewal')
        ->assertHasNoErrors();

    expect($h['stripe']->synced)->toBe(['sub_'.$order->id])
        ->and($h['stripe']->canceled)->toBe(['sub_'.$order->id])
        ->and(AdminAudit::query()->where('action', 'billing.subscription.synced')->exists())->toBeTrue()
        ->and(AdminAudit::query()->where('action', 'billing.subscription.renewal_canceled')->sole()->reason)->toContain('e-mail 26. 9.')
        ->and(app(PlanStatus::class)->isPlus($h['household']))->toBeTrue();
});

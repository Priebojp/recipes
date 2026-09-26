<?php

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Enums\UsageKind;
use App\Models\AdminAudit;
use App\Models\RefundCase;
use App\Models\UsageGrant;
use App\Services\Usage\UsageLedger;
use Livewire\Livewire;
use Tests\Support\BillingScenario;
use Tests\Support\StripePayloads as Stripe;

it('refunds an order from the admin page with the named units, an idempotency key and an audit entry', function () {
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('text_100');
    BillingScenario::payAddon($order);
    $admin = actingAsPlatformAdmin();

    $this->get(route('admin.orders.show', $order))->assertOk()->assertSee('Refundovať')->assertSee('100 × textové operácie');

    Livewire::test('pages::admin.order', ['order' => $order])
        ->set('refund_units.text', '150')
        ->set('refund_amount', '1,00')
        ->set('refund_reason', 'Čiastočná kompenzácia')
        ->call('refund')
        ->assertHasErrors(['refund_amount']) // more units than the order still holds
        ->set('refund_units.text', '50')
        ->set('refund_kind', 'complaint')
        ->set('refund_key', 'ticket-11')
        ->call('refund')
        ->assertHasNoErrors();

    $case = RefundCase::sole();
    expect($case->status)->toBe(RefundStatus::Processed)
        ->and($case->amount_cents)->toBe(100)
        ->and($case->requested_by)->toBe($admin->id)
        ->and($case->idempotency_key)->toBe('refund:'.$order->id.':ticket-11')
        ->and($h['stripe']->refunds[0]['amount'])->toBe(100)
        ->and(UsageGrant::query()->where('order_id', $order->id)->sole()->available())->toBe(50)
        ->and($order->fresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and(AdminAudit::query()->where('action', 'billing.refund.processed')->where('actor_id', $admin->id)->exists())->toBeTrue();
});

it('lets the administrator close a Stripe-dashboard refund by deciding which unused units go back', function () {
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('images_20_standard');
    BillingScenario::payAddon($order);
    Stripe::post($this, Stripe::chargeRefunded('pi_'.$order->id, 399, 200, [['id' => 're_dashboard', 'amount' => 200]]))->assertOk();
    $case = RefundCase::sole();
    expect($case->status)->toBe(RefundStatus::NeedsReview);

    $admin = actingAsPlatformAdmin();
    $this->get(route('admin.refunds', ['status' => 'needs_review']))->assertOk()->assertSee('čaká na posúdenie');

    Livewire::test('pages::admin.order', ['order' => $order])
        ->call('startReview', $case->id)
        ->set('review_units.image_standard', '10')
        ->set('review_note', 'Zákazník dostal späť polovicu, odoberáme 10 obrázkov')
        ->call('review')
        ->assertHasNoErrors()
        ->assertSet('review_case', null);

    expect($case->fresh()->status)->toBe(RefundStatus::Reviewed)
        ->and($case->fresh()->units_revoked)->toBe(['image_standard' => 10])
        ->and(UsageGrant::query()->where('order_id', $order->id)->sole()->available())->toBe(10)
        ->and(app(UsageLedger::class)->available($h['household'], UsageKind::ImageStandard))->toBe(11)
        ->and($order->fresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and(AdminAudit::query()->where('action', 'billing.refund.reviewed')->where('actor_id', $admin->id)->exists())->toBeTrue();

    // A second review of the same case is refused; nothing moves twice.
    Livewire::test('pages::admin.order', ['order' => $order])
        ->call('startReview', $case->id)
        ->set('review_units.image_standard', '5')
        ->set('review_note', 'ešte raz to isté')
        ->call('review')
        ->assertHasErrors(['review_note']);
    expect(UsageGrant::query()->where('order_id', $order->id)->sole()->available())->toBe(10);
});

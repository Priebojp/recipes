<?php

use App\Enums\OrderStatus;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Enums\WithdrawalStatus;
use App\Mail\WithdrawalDecidedMail;
use App\Mail\WithdrawalReceivedMail;
use App\Models\AdminAudit;
use App\Models\RefundCase;
use App\Models\UsageGrant;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\BillingScenario;

it('accepts an online withdrawal separately from cancelling renewal, confirms receipt at once and refunds through the refund workflow', function () {
    Mail::fake();
    $h = BillingScenario::start();
    $order = BillingScenario::startPlan();
    BillingScenario::paySubscription($order, now()->timestamp, now()->addMonth()->timestamp);

    $this->get(route('subscription.edit'))->assertOk()->assertSee('data-test="withdrawal-link"', false);
    $this->get(route('legal.withdrawal.form'))->assertOk()->assertSee('#'.$order->id)->assertSee('zrušenie obnovovania');

    Livewire::test('pages::legal.withdrawal-form')
        ->set('order_reference', (string) $order->id)
        ->call('submit')
        ->assertHasErrors(['confirm'])
        ->set('confirm', true)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('Odstúpenie prijaté pod číslom ODS-');

    $request = WithdrawalRequest::query()->sole();
    expect($request->order_id)->toBe($order->id)
        ->and($request->status)->toBe(WithdrawalStatus::Received)
        ->and($request->receipt_sent_at)->not->toBeNull()
        ->and($h['stripe']->canceled)->toBe([]); // withdrawal did not touch renewal – a different act
    Mail::assertQueued(WithdrawalReceivedMail::class, fn (WithdrawalReceivedMail $mail) => $mail->hasTo($h['user']->email) && $mail->request->is($request));

    $admin = actingAsPlatformAdmin();
    $this->get(route('admin.privacy'))->assertOk()->assertSee($request->reference);
    Livewire::test('pages::admin.privacy')
        ->call('wstart', $request->id)
        ->set('wnote', 'Odstúpenie v lehote, vraciame celú cenu')
        ->call('refund')
        ->assertHasNoErrors();

    $case = RefundCase::query()->sole();
    expect($case->kind)->toBe(RefundKind::Withdrawal)
        ->and($case->status)->toBe(RefundStatus::Processed)
        ->and($case->amount_cents)->toBe(249)
        ->and($case->revoke_entitlement)->toBeTrue()
        ->and($request->fresh()->status)->toBe(WithdrawalStatus::Refunded)
        ->and($request->fresh()->refund_case_id)->toBe($case->id)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and(UsageGrant::query()->where('paid_entitlement_id', $order->entitlements()->first()->id)->get()->sum(fn ($g) => $g->available()))->toBe(0)
        ->and(AdminAudit::query()->where('action', 'legal.withdrawal.refunded')->where('actor_id', $admin->id)->exists())->toBeTrue();
    Mail::assertQueued(WithdrawalDecidedMail::class, fn (WithdrawalDecidedMail $mail) => $mail->hasTo($h['user']->email));

    // Deciding twice is refused; nothing moves again.
    Livewire::test('pages::admin.privacy')->call('wstart', $request->id)->set('wnote', 'ešte raz')->call('refund')->assertHasErrors(['wnote']);
    expect(RefundCase::query()->count())->toBe(1);
});

it('works without an account: the payer e-mail matches the order, a foreign e-mail stays unmatched but still gets a receipt, and a rejection is explained', function () {
    Mail::fake();
    $h = BillingScenario::start();
    $order = BillingScenario::startAddon('text_100');
    BillingScenario::payAddon($order);
    auth()->logout();

    $this->get(route('legal.withdrawal.form'))->assertOk()->assertSee('Číslo objednávky');

    Livewire::test('pages::legal.withdrawal-form')
        ->set('email', $h['user']->email)->set('order_reference', '#'.$order->id)->set('confirm', true)->call('submit')->assertHasNoErrors();
    Livewire::test('pages::legal.withdrawal-form')
        ->set('email', 'cudzi@example.test')->set('order_reference', '#'.$order->id)->set('confirm', true)->call('submit')->assertHasNoErrors();

    [$matched, $foreign] = WithdrawalRequest::query()->orderBy('id')->get();
    expect($matched->order_id)->toBe($order->id)->and($matched->user_id)->toBeNull()
        ->and($foreign->order_id)->toBeNull()->and($foreign->email)->toBe('cudzi@example.test');
    Mail::assertQueued(WithdrawalReceivedMail::class, 2);

    $admin = actingAsPlatformAdmin();
    Livewire::test('pages::admin.privacy')
        ->call('wstart', $foreign->id)
        ->set('wnote', 'Objednávka nepatrí k uvedenému e-mailu; prosíme o doplnenie údajov.')
        ->call('reject')
        ->assertHasNoErrors();
    expect($foreign->fresh()->status)->toBe(WithdrawalStatus::Rejected)
        ->and(AdminAudit::query()->where('action', 'legal.withdrawal.rejected')->where('actor_id', $admin->id)->exists())->toBeTrue();
    Mail::assertQueued(WithdrawalDecidedMail::class, fn (WithdrawalDecidedMail $mail) => $mail->hasTo('cudzi@example.test'));
});

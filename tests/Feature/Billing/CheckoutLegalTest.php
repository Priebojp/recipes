<?php

use App\Enums\LegalAcceptanceAction;
use App\Enums\LegalDocumentType;
use App\Mail\OrderConfirmationMail;
use App\Models\LegalAcceptance;
use App\Models\Order;
use App\Models\Recipe;
use App\Services\Legal\LegalDocuments;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BillingScenario;
use Tests\Support\LegalScenario;
use Tests\Support\StripePayloads as Stripe;

it('blocks live checkout while the operator identity or the legal documents are missing, but never the recipes', function () {
    $h = BillingScenario::start(legalReady: false);
    Recipe::factory()->create(['household_id' => $h['household']->id, 'title' => 'Halušky']);

    $this->from(route('pricing'))->post(route('checkout.plan'), ['plan' => 'plus_monthly'])->assertSessionHasErrors('plan');
    expect(Order::query()->count())->toBe(0);
    $this->get(route('pricing'))->assertOk()->assertSee('Platby ešte nie sú zapnuté');
    $this->get(route('checkout.review', ['plan' => 'plus_monthly']))->assertOk()->assertSee('Platby ešte nie sú zapnuté');
    $this->get(route('recipes.index'))->assertOk()->assertSee('Halušky');

    // Identity alone is not enough: the required documents must be published too.
    LegalScenario::identifyOperator();
    $this->from(route('pricing'))->post(route('checkout.plan'), ['plan' => 'plus_monthly'])->assertSessionHasErrors('plan');

    LegalScenario::ready();
    $this->get(route('pricing'))->assertOk()->assertDontSee('Platby ešte nie sú zapnuté');
    BillingScenario::startPlan();
    expect(Order::query()->count())->toBe(1);
});

it('shows the pre-payment summary and captures the exact terms version, a separate early-performance request and the confirmation e-mail', function () {
    Mail::fake();
    $h = BillingScenario::start();

    $this->get(route('checkout.review', ['plan' => 'plus_yearly']))->assertOk()
        ->assertSee('Objednať s povinnosťou platby')->assertSee('24,00 €')->assertSee('účtuje raz ročne')
        ->assertSee('Testovací prevádzkovateľ s. r. o.')->assertSee('obchodné podmienky, verzia 1')->assertSee('online formulárom');

    // The acceptance must name the current version; a stale form is refused.
    $this->from(route('checkout.review'))->post(route('checkout.plan'), ['plan' => 'plus_monthly'])->assertSessionHasErrors('terms');
    $this->from(route('checkout.review'))->post(route('checkout.plan'), ['plan' => 'plus_monthly', 'terms' => '1', 'terms_version' => 7])->assertSessionHasErrors('terms_version');
    expect(Order::query()->count())->toBe(0);

    $this->post(route('checkout.addon'), ['addon' => 'text_100', 'terms' => '1', 'terms_version' => 1, 'early_performance' => '1'])->assertRedirectContains('checkout.stripe.test');
    $order = Order::query()->sole();
    $terms = LegalScenario::currentTerms();
    expect($order->terms_version_id)->toBe($terms->id);
    $acceptance = LegalAcceptance::query()->where('order_id', $order->id)->sole();
    expect($acceptance->action)->toBe(LegalAcceptanceAction::Checkout)
        ->and($acceptance->acknowledgements)->toBe(['early_performance_requested' => true])
        ->and($acceptance->user_id)->toBe($h['user']->id);

    // A newer terms version does not change what the order was concluded under.
    $documents = app(LegalDocuments::class);
    $documents->publish($documents->newDraft(LegalDocumentType::Terms), 'ok', $h['user']);
    expect($order->fresh()->termsVersion->version)->toBe(1);

    BillingScenario::payAddon($order);
    Mail::assertQueued(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($order, $h) {
        $attachments = $mail->attachments();

        return $mail->order->is($order) && $mail->terms->version === 1 && $mail->hasTo($h['user']->email)
            && count($attachments) === 1 && str_contains((string) $mail->render(), 'verzia 1');
    });

    // A replayed webhook does not send the confirmation twice.
    Stripe::post($this, Stripe::checkoutCompleted($order, 'payment'))->assertOk();
    Mail::assertQueued(OrderConfirmationMail::class, 1);
    expect($order->fresh()->confirmation_sent_at)->not->toBeNull();
});

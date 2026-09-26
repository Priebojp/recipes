<?php

namespace App\Services\Billing;

use App\Enums\LegalAcceptanceAction;
use App\Enums\LegalDocumentType;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Models\Household;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\Gateway\StripeGateway;
use App\Services\Legal\CheckoutReadiness;
use App\Services\Legal\LegalDocuments;

/**
 * Starts hosted Stripe Checkout for an offer code. Price and product come from the catalogue snapshot;
 * the success URL never grants anything – webhooks do.
 */
class CheckoutService
{
    public function __construct(
        private Catalog $catalog,
        private BillingAccounts $accounts,
        private PlanStatus $plans,
        private StripeGateway $gateway,
        private CheckoutReadiness $readiness,
        private LegalDocuments $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $acknowledgements  e.g. ['early_performance_requested' => true]
     *
     * @throws CheckoutException
     */
    public function startSubscription(Household $household, User $by, string $planCode, array $acknowledgements = []): StartedCheckout
    {
        $this->assertNotBlocked($household);
        $this->assertReady();
        $plan = $this->catalog->plan($planCode);
        if ($plan === null || ! $plan->stripe_price_id) {
            throw new CheckoutException('Táto ponuka momentálne nie je dostupná.');
        }

        if ($this->plans->hasSubscription($household)) {
            throw new CheckoutException('Domácnosť už má predplatné Plus. Zmenu obdobia urobíš v správe platby.');
        }

        $account = $this->accounts->forHousehold($household, $by);
        $terms = $this->documents->current(LegalDocumentType::Terms);

        $order = Order::create([
            'household_id' => $household->id,
            'billing_account_id' => $account->id,
            'created_by' => $by->id,
            'kind' => OrderKind::Subscription,
            'plan_version_id' => $plan->id,
            'product_snapshot' => $plan->snapshot(),
            'amount_cents' => $plan->final_price_cents,
            'currency' => $plan->currency,
            'status' => OrderStatus::Pending,
            'terms_version_id' => $terms?->id,
        ]);
        $this->recordAcceptance($order, $by, $acknowledgements);

        $session = $this->gateway->createSubscriptionCheckout(
            $account,
            $plan->stripe_price_id,
            route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            route('checkout.cancel', ['order' => $order->id]),
            $this->metadata($order),
        );

        $order->update(['stripe_checkout_session_id' => $session['id']]);

        return new StartedCheckout($order, $session['url']);
    }

    /**
     * @param  array<string, mixed>  $acknowledgements
     *
     * @throws CheckoutException
     */
    public function startAddon(Household $household, User $by, string $addonCode, array $acknowledgements = []): StartedCheckout
    {
        $this->assertNotBlocked($household);
        $this->assertReady();
        $addon = $this->catalog->addon($addonCode);
        if ($addon === null || ! $addon->stripe_price_id) {
            throw new CheckoutException('Tento balík momentálne nie je dostupný.');
        }

        $account = $this->accounts->forHousehold($household, $by);
        $terms = $this->documents->current(LegalDocumentType::Terms);

        $order = Order::create([
            'household_id' => $household->id,
            'billing_account_id' => $account->id,
            'created_by' => $by->id,
            'kind' => OrderKind::Addon,
            'addon_version_id' => $addon->id,
            'product_snapshot' => $addon->snapshot(),
            'amount_cents' => $addon->final_price_cents,
            'currency' => $addon->currency,
            'status' => OrderStatus::Pending,
            'terms_version_id' => $terms?->id,
        ]);
        $this->recordAcceptance($order, $by, $acknowledgements);

        $session = $this->gateway->createPaymentCheckout(
            $account,
            $addon->stripe_price_id,
            route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            route('checkout.cancel', ['order' => $order->id]),
            $this->metadata($order),
        );

        $order->update(['stripe_checkout_session_id' => $session['id']]);

        return new StartedCheckout($order, $session['url']);
    }

    /** The customer left Checkout: a pending order is closed, nothing else changes. */
    public function cancel(Order $order): void
    {
        if ($order->status === OrderStatus::Pending) {
            $order->update(['status' => OrderStatus::Canceled]);
        }
    }

    /** Whether paid checkout may run at all (operator identity, published terms) – acceptance test 20. */
    public function isReady(): bool
    {
        return $this->readiness->isReady();
    }

    /** @throws CheckoutException */
    private function assertReady(): void
    {
        if (! $this->readiness->isReady()) {
            throw new CheckoutException('Platby ešte nie sú zapnuté: chýba identifikácia prevádzkovateľa alebo publikované obchodné podmienky. Bezplatné funkcie fungujú ďalej.');
        }
    }

    /**
     * The order records the exact terms version accepted at the button with the obligation to pay; a separate
     * acknowledgement (early performance) is stored apart from marketing and cookies.
     *
     * @param  array<string, mixed>  $acknowledgements
     */
    private function recordAcceptance(Order $order, User $by, array $acknowledgements): void
    {
        $terms = $order->termsVersion;
        if ($terms === null) {
            return;
        }
        $this->documents->recordAcceptance($terms, LegalAcceptanceAction::Checkout, $by, $order->household, $order, $acknowledgements);
    }

    /** @throws CheckoutException */
    private function assertNotBlocked(Household $household): void
    {
        if ($household->isBlocked()) {
            throw new CheckoutException('Nákupy sú pre túto domácnosť pozastavené. Kontaktuj podporu.');
        }
    }

    /** @return array<string, string> */
    private function metadata(Order $order): array
    {
        return ['order_id' => (string) $order->id, 'household_id' => (string) $order->household_id];
    }
}

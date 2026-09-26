<?php

namespace App\Http\Controllers;

use App\Enums\LegalDocumentType;
use App\Models\Order;
use App\Services\Billing\CheckoutException;
use App\Services\Billing\CheckoutService;
use App\Services\Legal\LegalDocuments;
use App\Support\CurrentHousehold;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The client sends an offer code; price, currency and Stripe price come from the server catalogue.
 * Only the household owner may buy for the household.
 */
class CheckoutController extends Controller
{
    public function subscribe(Request $request, CurrentHousehold $current, CheckoutService $checkout): RedirectResponse
    {
        $household = $current->get();
        $request->user()->can('manage', $household) || abort(403);
        $data = $request->validate(['plan' => ['required', 'string', 'max:50'], ...$this->termsRules()]);

        try {
            $started = $checkout->startSubscription($household, $request->user(), $data['plan'], $this->acknowledgements($request));
        } catch (CheckoutException $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }

        return redirect()->away($started->url);
    }

    public function addon(Request $request, CurrentHousehold $current, CheckoutService $checkout): RedirectResponse
    {
        $household = $current->get();
        $request->user()->can('manage', $household) || abort(403);
        $data = $request->validate(['addon' => ['required', 'string', 'max:50'], ...$this->termsRules()]);

        try {
            $started = $checkout->startAddon($household, $request->user(), $data['addon'], $this->acknowledgements($request));
        } catch (CheckoutException $e) {
            throw ValidationException::withMessages(['addon' => $e->getMessage()]);
        }

        return redirect()->away($started->url);
    }

    /**
     * The order form must carry a separate acceptance of the *current* terms version (the review page shows it).
     *
     * @return array<string, array<int, string>>
     */
    private function termsRules(): array
    {
        $terms = app(LegalDocuments::class)->current(LegalDocumentType::Terms);
        if ($terms === null) {
            return [];
        }

        return [
            'terms' => ['accepted'],
            'terms_version' => ['required', 'integer', 'in:'.$terms->version],
            'early_performance' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function acknowledgements(Request $request): array
    {
        return $request->boolean('early_performance') ? ['early_performance_requested' => true] : [];
    }

    public function cancel(Request $request, CurrentHousehold $current, CheckoutService $checkout, Order $order): RedirectResponse
    {
        $order->household_id === $current->id() || abort(404);
        $checkout->cancel($order);

        return redirect()->route('subscription.edit')->with('status', 'Platba nebola dokončená. Nič sa neúčtovalo.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Billing\Gateway\StripeGateway;
use App\Support\CurrentHousehold;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Stripe Customer Portal: payment method, invoices, cancelling renewal. Owner only – never another household's. */
class BillingPortalController extends Controller
{
    public function __invoke(Request $request, CurrentHousehold $current, StripeGateway $gateway): RedirectResponse
    {
        $household = $current->get();
        $request->user()->can('manage', $household) || abort(403);

        $account = $household->billingAccount;
        if ($account === null || ! $account->stripe_id) {
            return redirect()->route('subscription.edit')->with('status', 'Domácnosť zatiaľ nemá žiadnu platbu, portál sa otvorí po prvom nákupe.');
        }

        return redirect()->away($gateway->billingPortalUrl($account, route('subscription.edit')));
    }
}

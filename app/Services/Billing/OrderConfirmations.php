<?php

namespace App\Services\Billing;

use App\Enums\LegalDocumentType;
use App\Enums\OrderStatus;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Services\Legal\LegalDocuments;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * After payment the customer receives the order and the exact terms version on a durable medium (e-mail with the
 * terms attached), not only a link to a page that may change (specification chapter 9). Sent once per order.
 */
class OrderConfirmations
{
    public function __construct(private LegalDocuments $documents) {}

    public function send(Order $order): bool
    {
        $order->refresh();
        if ($order->status !== OrderStatus::Paid || $order->confirmation_sent_at !== null) {
            return false;
        }

        $email = $order->billingAccount?->stripeEmail();
        if (! $email) {
            return false;
        }

        $terms = $order->termsVersion ?? $this->documents->current(LegalDocumentType::Terms);

        try {
            Mail::to($email)->send(new OrderConfirmationMail($order, $terms));
        } catch (Throwable $e) {
            // The payment is fulfilled regardless; the missing confirmation is reported and sent on the next retry.
            report($e);

            return false;
        }

        $order->update(['confirmation_sent_at' => now()]);

        return true;
    }
}

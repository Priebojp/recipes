<?php

namespace App\Services\Privacy;

use App\Enums\OrderStatus;
use App\Enums\RefundKind;
use App\Enums\RefundStatus;
use App\Enums\WithdrawalStatus;
use App\Mail\WithdrawalDecidedMail;
use App\Mail\WithdrawalReceivedMail;
use App\Models\Order;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Admin\AdminAuditor;
use App\Services\Billing\RefundService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Online withdrawal from the contract (specification chapter 9): identify the order → confirm the intent → immediate
 * receipt on a durable medium (e-mail) → refund workflow. Works without an account and is distinct from "cancel
 * renewal". The refund itself goes through RefundService, so money, units and audit stay in one place.
 */
class WithdrawalService
{
    public function __construct(private RefundService $refunds, private AdminAuditor $audit) {}

    public function submit(string $email, ?string $orderReference, ?string $message, ?User $user = null): WithdrawalRequest
    {
        $order = $this->matchOrder($email, $orderReference, $user);

        $request = WithdrawalRequest::create([
            'reference' => $this->reference(),
            'email' => Str::lower(trim($email)),
            'order_reference' => $orderReference !== null ? mb_substr(trim($orderReference), 0, 100) : null,
            'order_id' => $order?->id,
            'user_id' => $user?->id,
            'message' => $message !== null ? mb_substr(trim($message), 0, 2000) : null,
            'status' => WithdrawalStatus::Received,
            'received_at' => now(),
        ]);

        Mail::to($request->email)->send(new WithdrawalReceivedMail($request));
        $request->update(['receipt_sent_at' => now()]);

        return $request;
    }

    /** Full refund of the matched order with every unused unit and the paid period taken back. */
    public function refund(WithdrawalRequest $request, User $by, string $note): WithdrawalRequest
    {
        $order = $request->order;
        if ($request->status !== WithdrawalStatus::Received || $order === null) {
            throw new InvalidArgumentException('Odstúpenie nie je otvorené alebo nemá priradenú objednávku.');
        }

        $amount = $order->amount_cents - $this->refunds->refundedCents($order);
        if ($amount < 1) {
            throw new InvalidArgumentException('Objednávka je už celá refundovaná.');
        }

        $case = $this->refunds->request(
            $order,
            RefundKind::Withdrawal,
            $amount,
            'Odstúpenie od zmluvy '.$request->reference.': '.$note,
            $this->refunds->revocableUnits($order),
            true,
            $by,
            'withdrawal-'.$request->id,
        );

        if ($case->status === RefundStatus::Failed) {
            $request->update(['refund_case_id' => $case->id, 'decision_note' => 'Refundácia v Stripe zlyhala: '.$case->error]);
            throw new InvalidArgumentException('Refundácia v Stripe zlyhala: '.$case->error);
        }

        $request->update([
            'status' => WithdrawalStatus::Refunded,
            'refund_case_id' => $case->id,
            'decision_note' => $note,
            'handled_by' => $by->id,
            'decided_at' => now(),
        ]);
        $this->audit->record('legal.withdrawal.refunded', $request, [], ['order_id' => $order->id, 'refund_case_id' => $case->id, 'amount_cents' => $amount], $note, $by);
        Mail::to($request->email)->send(new WithdrawalDecidedMail($request->fresh()));

        return $request->fresh();
    }

    public function reject(WithdrawalRequest $request, User $by, string $note): WithdrawalRequest
    {
        if ($request->status !== WithdrawalStatus::Received) {
            throw new InvalidArgumentException('Odstúpenie už bolo rozhodnuté.');
        }

        $request->update(['status' => WithdrawalStatus::Rejected, 'decision_note' => $note, 'handled_by' => $by->id, 'decided_at' => now()]);
        $this->audit->record('legal.withdrawal.rejected', $request, [], [], $note, $by);
        Mail::to($request->email)->send(new WithdrawalDecidedMail($request->fresh()));

        return $request->fresh();
    }

    /** The administrator links an order the automatic match did not find (e.g. the customer typed a Stripe id). */
    public function attachOrder(WithdrawalRequest $request, Order $order, User $by): WithdrawalRequest
    {
        $request->update(['order_id' => $order->id]);
        $this->audit->record('legal.withdrawal.order_attached', $request, [], ['order_id' => $order->id], null, $by);

        return $request;
    }

    /**
     * Match by order number for a logged-in owner (their household's orders) or by e-mail of the payer / billing
     * contact for anonymous requests. Nothing is revealed to the customer either way.
     */
    private function matchOrder(string $email, ?string $reference, ?User $user): ?Order
    {
        $id = (int) preg_replace('/\D+/', '', (string) $reference);
        if ($id < 1) {
            return null;
        }

        $query = Order::query()->whereKey($id)->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded]);
        if ($user !== null) {
            $query->whereIn('household_id', $user->households()->pluck('households.id'));
        } else {
            $normalised = Str::lower(trim($email));
            $query->whereHas('billingAccount', fn ($q) => $q->whereRaw('lower(billing_email) = ?', [$normalised])
                ->orWhereHas('payer', fn ($p) => $p->whereRaw('lower(email) = ?', [$normalised])));
        }

        return $query->first();
    }

    private function reference(): string
    {
        do {
            $reference = 'ODS-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (WithdrawalRequest::query()->where('reference', $reference)->exists());

        return $reference;
    }
}

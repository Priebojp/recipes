<?php

namespace App\Mail;

use App\Enums\WithdrawalStatus;
use App\Models\WithdrawalRequest;
use App\Services\Billing\Catalog;
use App\Services\Legal\OperatorIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalDecidedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public WithdrawalRequest $request) {}

    public function envelope(): Envelope
    {
        $subject = $this->request->status === WithdrawalStatus::Refunded ? __('Odstúpenie od zmluvy :reference – refundácia', ['reference' => $this->request->reference]) : __('Odstúpenie od zmluvy :reference – rozhodnutie', ['reference' => $this->request->reference]);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $case = $this->request->refundCase;

        return new Content(markdown: 'mail.withdrawal-decided', with: [
            'request' => $this->request,
            'refunded' => $this->request->status === WithdrawalStatus::Refunded,
            'amount' => $case !== null ? Catalog::formatCents($case->amount_cents, $case->currency) : null,
            'operator' => app(OperatorIdentity::class),
        ]);
    }
}

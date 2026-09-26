<?php

namespace App\Mail;

use App\Models\LegalDocumentVersion;
use App\Models\Order;
use App\Services\Billing\Catalog;
use App\Services\Legal\OperatorIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation of a paid order with the exact terms version the customer accepted, attached as a file.
 */
class OrderConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public ?LegalDocumentVersion $terms) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Potvrdenie objednávky #'.$this->order->id.' – '.config('app.name'));
    }

    public function content(): Content
    {
        $snapshot = $this->order->product_snapshot;

        return new Content(markdown: 'mail.order-confirmation', with: [
            'order' => $this->order,
            'terms' => $this->terms,
            'amount' => Catalog::formatCents($this->order->amount_cents, $this->order->currency),
            'interval' => $snapshot['interval'] ?? null,
            'isSubscription' => ($snapshot['type'] ?? '') === 'plan',
            'operator' => app(OperatorIdentity::class),
            'withdrawalDays' => (int) config('recipes.legal.withdrawal_days', 14),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->terms === null) {
            return [];
        }
        $terms = $this->terms;

        return [
            Attachment::fromData(fn () => '# '.$terms->title." (v{$terms->version})\n\n".$terms->content, 'vop-v'.$terms->version.'.md')->withMime('text/markdown'),
        ];
    }
}

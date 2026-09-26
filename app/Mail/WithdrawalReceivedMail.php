<?php

namespace App\Mail;

use App\Models\WithdrawalRequest;
use App\Services\Legal\OperatorIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Immediate acknowledgement of a withdrawal on a durable medium (the law requires it without delay). */
class WithdrawalReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public WithdrawalRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Potvrdenie prijatia odstúpenia od zmluvy '.$this->request->reference);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.withdrawal-received', with: [
            'request' => $this->request,
            'operator' => app(OperatorIdentity::class),
        ]);
    }
}

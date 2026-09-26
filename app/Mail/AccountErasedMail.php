<?php

namespace App\Mail;

use App\Services\Legal\OperatorIdentity;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Confirmation that an erasure request was carried out; sent to the address the account had. */
class AccountErasedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public int $requestId, public CarbonInterface $erasedAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Váš účet bol vymazaný – '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account-erased', with: [
            'requestId' => $this->requestId,
            'erasedAt' => $this->erasedAt,
            'operator' => app(OperatorIdentity::class),
        ]);
    }
}

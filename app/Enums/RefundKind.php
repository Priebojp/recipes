<?php

namespace App\Enums;

/**
 * Four different things (specification chapter 7): withdrawal from the contract, a complaint about defective
 * service, a voluntary refund, a card dispute – plus refunds issued directly in the Stripe dashboard.
 */
enum RefundKind: string
{
    case Withdrawal = 'withdrawal';
    case Complaint = 'complaint';
    case Goodwill = 'goodwill';
    case Dispute = 'dispute';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Withdrawal => 'odstúpenie od zmluvy',
            self::Complaint => 'reklamácia',
            self::Goodwill => 'dobrovoľná refundácia',
            self::Dispute => 'spor (chargeback)',
            self::External => 'refundácia zo Stripe',
        };
    }
}

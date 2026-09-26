<?php

namespace App\Enums;

/**
 * Why a ledger row exists. The ledger is append-only; the signed movement of all rows of a grant equals its
 * currently available quantity (grant − reserved − consumed − revoked).
 */
enum UsageLedgerReason: string
{
    case Granted = 'granted';
    case Reserved = 'reserved';
    case Released = 'released';
    case Consumed = 'consumed';
    case Revoked = 'revoked';
}

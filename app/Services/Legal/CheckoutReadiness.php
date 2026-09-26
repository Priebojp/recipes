<?php

namespace App\Services\Legal;

use App\Enums\LegalDocumentType;

/**
 * Whether paid checkout may run (acceptance test 20): the operator is identified, the terms, privacy and withdrawal
 * documents are published without placeholders. Free features and recipes are never blocked by this.
 */
class CheckoutReadiness
{
    public function __construct(private OperatorIdentity $operator, private LegalDocuments $documents) {}

    /** @return list<string> human-readable blockers; empty when checkout may go live */
    public function blockers(): array
    {
        $blockers = [];

        $missing = $this->operator->missing();
        if ($missing !== []) {
            $blockers[] = 'Chýba identita prevádzkovateľa: '.implode(', ', $missing).'.';
        }

        foreach (LegalDocumentType::cases() as $type) {
            if (! $type->requiredForCheckout()) {
                continue;
            }
            $current = $this->documents->current($type);
            if ($current === null) {
                $blockers[] = $type->shortLabel().': nie je publikovaná žiadna verzia.';
            } elseif ($current->hasPlaceholders()) {
                $blockers[] = $type->shortLabel().' v'.$current->version.' obsahuje nevyplnené údaje.';
            }
        }

        return $blockers;
    }

    public function isReady(): bool
    {
        return $this->blockers() === [];
    }
}

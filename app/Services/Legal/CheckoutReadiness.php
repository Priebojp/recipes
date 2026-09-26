<?php

namespace App\Services\Legal;

use App\Enums\LegalDocumentType;

/**
 * Whether paid checkout may run (acceptance test 20): the deploy-time switch is on, the operator is identified, the
 * terms, privacy and withdrawal documents are published without placeholders. Free features and recipes are never
 * blocked by this.
 */
class CheckoutReadiness
{
    public function __construct(private OperatorIdentity $operator, private LegalDocuments $documents) {}

    /** @return list<string> human-readable blockers; empty when checkout may go live */
    public function blockers(): array
    {
        $blockers = $this->switchedOn() ? [] : ['Platby nie sú zapnuté nasadením (RECIPES_CHECKOUT_ENABLED).'];

        return [...$blockers, ...$this->legalBlockers()];
    }

    /** @return list<string> the legal side only (operator identity, published documents), regardless of the switch */
    public function legalBlockers(): array
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

    /** The deploy-time switch (stage 7): payments go live in a separate deployment, never by a data change alone. */
    public function switchedOn(): bool
    {
        return (bool) config('recipes.billing.checkout_enabled');
    }
}

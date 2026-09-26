<?php

namespace Tests\Support;

use App\Enums\LegalDocumentType;
use App\Models\LegalDocumentVersion;
use App\Models\User;
use App\Services\Legal\LegalDocuments;
use App\Services\Legal\OperatorIdentity;
use Database\Seeders\LegalDocumentSeeder;

/**
 * Puts the legal side into a "may sell" state: operator identified, seeded drafts filled and published.
 */
class LegalScenario
{
    /** @return array<string, string> */
    public static function operator(): array
    {
        return [
            'business_name' => 'Testovací prevádzkovateľ s. r. o.',
            'legal_form' => 's. r. o.',
            'address' => 'Kuchynská 1, 811 01 Bratislava',
            'registration_id' => '12345678',
            'register' => 'OR OS Bratislava I, odd. Sro, vl. 1/B',
            'support_email' => 'podpora@example.test',
            'complaints_email' => 'reklamacie@example.test',
            'privacy_email' => 'privacy@example.test',
            'ars_body' => 'Slovenská obchodná inšpekcia, ars@soi.sk',
            'countries' => 'Slovensko',
        ];
    }

    public static function identifyOperator(?User $by = null): void
    {
        app(OperatorIdentity::class)->update(self::operator(), $by, 'test');
    }

    /** Seed the drafts (idempotent) and publish every type without placeholders. */
    public static function ready(?User $approver = null): void
    {
        self::identifyOperator($approver);
        test()->seed(LegalDocumentSeeder::class);

        $approver ??= User::factory()->create();
        $documents = app(LegalDocuments::class);
        $identity = app(OperatorIdentity::class);

        foreach (LegalDocumentType::cases() as $type) {
            $draft = $documents->draft($type);
            if ($draft === null) {
                continue;
            }
            $content = preg_replace(LegalDocumentVersion::PLACEHOLDER_PATTERN, '(doplnené v teste)', $identity->fillPlaceholders($draft->content));
            $documents->updateDraft($draft, ['content' => $content], $approver);
            $documents->publish($draft->fresh(), 'Schválené v teste', $approver);
        }
    }

    public static function currentTerms(): LegalDocumentVersion
    {
        return app(LegalDocuments::class)->current(LegalDocumentType::Terms) ?? throw new \RuntimeException('No published terms.');
    }
}

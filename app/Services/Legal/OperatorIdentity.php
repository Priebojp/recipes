<?php

namespace App\Services\Legal;

use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Admin\AppSettings;

/**
 * Identity and contacts of the operator (specification chapter 9, "Povinné vstupy od prevádzkovateľa"). Stored in
 * app_settings under operator.*; nothing is guessed or copied from another project. Placeholders in the working
 * legal texts are filled from here.
 */
class OperatorIdentity
{
    /** @var array<string, array{label: string, required: bool, hint?: string}> */
    public const FIELDS = [
        'business_name' => ['label' => 'Obchodné meno', 'required' => true],
        'legal_form' => ['label' => 'Právna forma', 'required' => true, 'hint' => 'napr. fyzická osoba – podnikateľ, s. r. o.'],
        'address' => ['label' => 'Sídlo / miesto podnikania', 'required' => true],
        'registration_id' => ['label' => 'IČO', 'required' => true],
        'register' => ['label' => 'Registrácia (register, oddiel, vložka / živnostenský register)', 'required' => true],
        'tax_id' => ['label' => 'DIČ', 'required' => false],
        'vat_id' => ['label' => 'IČ DPH', 'required' => false, 'hint' => 'iba ak je prevádzkovateľ platiteľom DPH'],
        'support_email' => ['label' => 'E-mail podpory', 'required' => true],
        'complaints_email' => ['label' => 'E-mail pre reklamácie a odstúpenie', 'required' => true],
        'privacy_email' => ['label' => 'Kontakt pre ochranu osobných údajov', 'required' => true],
        'phone' => ['label' => 'Telefón', 'required' => false, 'hint' => 'ak platné povinnosti vyžadujú'],
        'ars_body' => ['label' => 'Subjekt alternatívneho riešenia sporov (ARS)', 'required' => true, 'hint' => 'názov a kontakt aktuálne príslušného orgánu – overiť'],
        'countries' => ['label' => 'Cieľové krajiny', 'required' => true, 'hint' => 'napr. Slovensko'],
        'tax_regime' => ['label' => 'Daňový režim', 'required' => false, 'hint' => 'neplatiteľ DPH / platiteľ / OSS – po rozhodnutí'],
    ];

    public function __construct(private AppSettings $settings, private AdminAuditor $audit) {}

    /** @return array<string, string> */
    public function all(): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $values[$field] = trim((string) $this->settings->get('operator.'.$field, ''));
        }

        return $values;
    }

    public function get(string $field): string
    {
        return trim((string) $this->settings->get('operator.'.$field, ''));
    }

    /** @return list<string> labels of required fields that are still empty */
    public function missing(): array
    {
        $missing = [];
        foreach (self::FIELDS as $field => $meta) {
            if ($meta['required'] && $this->get($field) === '') {
                $missing[] = $meta['label'];
            }
        }

        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    /**
     * @param  array<string, string>  $values
     */
    public function update(array $values, ?User $by = null, ?string $reason = null): void
    {
        $before = $this->all();
        $after = [];
        foreach (array_keys(self::FIELDS) as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $value = trim((string) $values[$field]);
            $this->settings->set('operator.'.$field, $value === '' ? null : $value, $by);
            $after[$field] = $value;
        }

        $this->audit->record('legal.operator.updated', 'operator', array_intersect_key($before, $after), $after, $reason, $by);
    }

    /** "Obchodné meno, sídlo, IČO 12345678, zapísaný v …" */
    public function identityLine(): string
    {
        $parts = array_filter([
            $this->get('business_name'),
            $this->get('address'),
            $this->get('registration_id') !== '' ? 'IČO '.$this->get('registration_id') : '',
            $this->get('tax_id') !== '' ? 'DIČ '.$this->get('tax_id') : '',
            $this->get('vat_id') !== '' ? 'IČ DPH '.$this->get('vat_id') : '',
            $this->get('register') !== '' ? 'zapísaný v '.$this->get('register') : '',
        ]);

        return implode(', ', $parts);
    }

    /**
     * Replace the known placeholders of the working texts with the operator's data. Unknown placeholders stay,
     * so a document with an unresolved [DOPRACOVAŤ …] still cannot be published.
     */
    public function fillPlaceholders(string $content): string
    {
        $map = [
            '[OBCHODNÉ MENO, SÍDLO, IČO, REGISTER]' => $this->identityLine(),
            '[IDENTITA A KONTAKT]' => trim($this->identityLine().($this->get('privacy_email') !== '' ? ', kontakt '.$this->get('privacy_email') : '')),
            '[EMAIL]' => $this->get('support_email'),
            '[PRIVACY EMAIL]' => $this->get('privacy_email'),
            '[E-MAIL PRE REKLAMÁCIE]' => $this->get('complaints_email'),
            '[ARS]' => $this->get('ars_body'),
        ];

        foreach ($map as $placeholder => $value) {
            if ($value !== '') {
                $content = str_replace($placeholder, $value, $content);
            }
        }

        return $content;
    }
}

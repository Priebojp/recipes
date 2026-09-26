<?php

namespace App\Services\Launch;

use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Admin\AppSettings;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Manual launch decisions the application cannot verify itself (specification chapters 7 and 18): prices, invoices,
 * tax regime, legal approval, simulations, AI measurement, operations. Each confirmation records who, when and a
 * note; confirming and withdrawing are audited. A confirmation is a statement by the operator, never a legal opinion.
 */
class LaunchSignoffs
{
    private const PREFIX = 'launch.signoff.';

    /** @var array<string, array{label: string, hint: string}> */
    public const ITEMS = [
        'prices' => [
            'label' => 'Ceny a limity potvrdené',
            'hint' => '2,49 €/mes., 24 €/rok, 30 textov a 5 obrázkov Standard za obdobie; 20 obrázkov 3,99 €, 100 textov 1,99 € (zadanie kap. 18). Zmena = nová verzia katalógu.',
        ],
        'stripe_account' => [
            'label' => 'Stripe účet aktivovaný a vyplnený',
            'hint' => 'Overená identita a výplaty, presné obchodné meno, verejné meno „Moje recepty“, statement descriptor, funkčný support e-mail (dodatok v2.1 kap. 11). Stripe Tax nezapnuté.',
        ],
        'invoices' => [
            'label' => 'Doklady zo Stripe overené s účtovníkom',
            'hint' => 'Náležitosti, číslovanie, text o DPH, dobropisy pri refundácii; jeden autoritatívny proces vystavovania dokladov (zadanie kap. 7).',
        ],
        'tax' => [
            'label' => 'DPH / OSS režim rozhodnutý',
            'hint' => 'Neplatiteľ / § 7a / platiteľ, cieľové krajiny a miesto dodania elektronickej služby; zapísať do identity prevádzkovateľa (pole „daňový režim“).',
        ],
        'legal_review' => [
            'label' => 'Právne texty schválené právnikom',
            'hint' => 'VOP, odstúpenie, reklamácie a privacy register – publikované verzie bez placeholderov, schválenie zapísané pri publikovaní.',
        ],
        'test_clock' => [
            'label' => 'Simulácia test clock prebehla',
            'hint' => 'Obnova mesačného aj ročného plánu, zrušenie obnovovania, anchor 31. 1. → február, neúspešná obnova (php artisan app:billing-test-clock) – výsledky sedia s ledgerom a nárokmi.',
        ],
        'ai_measurement' => [
            'label' => 'Meranie AI nákladov vyhodnotené',
            'hint' => '30 textových + 30 obrázkových úloh na reálnom kľúči (php artisan app:ai-measure); skutočná cena mesiaca Plus a balíkov dáva s cenníkom zmysel.',
        ],
        'operations' => [
            'label' => 'Hosting, e-mail, zálohy, monitoring, príjemcovia dát',
            'hint' => 'Produkčný SMTP, denné zálohy DB a médií s otestovanou obnovou, monitoring fronty a scheduleru, zoznam príjemcov v privacy registri.',
        ],
    ];

    public function __construct(private AppSettings $settings, private AdminAuditor $audit) {}

    /** @return array{by: int|null, at: string, note: string}|null */
    public function get(string $key): ?array
    {
        $this->assertKnown($key);
        $value = $this->settings->get(self::PREFIX.$key);
        if (! is_array($value) || ! is_string($value['at'] ?? null)) {
            return null;
        }

        return [
            'by' => is_int($value['by'] ?? null) ? $value['by'] : null,
            'at' => $value['at'],
            'note' => (string) ($value['note'] ?? ''),
        ];
    }

    public function isConfirmed(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** @return array<string, array{by: int|null, at: string, note: string}|null> keyed like ITEMS */
    public function all(): array
    {
        $all = [];
        foreach (array_keys(self::ITEMS) as $key) {
            $all[$key] = $this->get($key);
        }

        return $all;
    }

    /** @return list<string> */
    public function missing(): array
    {
        return array_values(array_filter(array_keys(self::ITEMS), fn (string $key) => ! $this->isConfirmed($key)));
    }

    public function confirm(string $key, User $by, string $note): void
    {
        $this->assertKnown($key);
        $note = trim($note);
        if ($note === '') {
            throw new InvalidArgumentException('Potvrdenie potrebuje poznámku (kto/čo overil, dátum, dokument).');
        }

        $before = $this->get($key);
        $after = ['by' => $by->id, 'at' => CarbonImmutable::now()->toIso8601String(), 'note' => mb_substr($note, 0, 1000)];
        $this->settings->set(self::PREFIX.$key, $after, $by);
        $this->audit->record('launch.signoff.confirmed', 'launch:'.$key, $before ?? [], $after, $note, $by);
    }

    public function withdraw(string $key, User $by, string $reason): void
    {
        $this->assertKnown($key);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Odvolanie potvrdenia potrebuje dôvod.');
        }

        $before = $this->get($key);
        if ($before === null) {
            return;
        }

        $this->settings->set(self::PREFIX.$key, null, $by);
        $this->audit->record('launch.signoff.withdrawn', 'launch:'.$key, $before, [], $reason, $by);
    }

    private function assertKnown(string $key): void
    {
        if (! array_key_exists($key, self::ITEMS)) {
            throw new InvalidArgumentException('Neznáma položka launch checklistu: '.$key);
        }
    }
}

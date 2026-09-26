<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Services\Billing\Gateway\StripeTestClocks;
use App\Services\Billing\TestClockSimulation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Stripe test clock simulations against the sandbox account (v2 stage 7): start a frozen subscription for a
 * household, advance time, inspect what the webhooks produced locally, delete the simulation.
 */
class BillingTestClock extends Command
{
    protected $signature = 'app:billing-test-clock
        {action : start|advance|status|list|delete}
        {target? : start: ID domácnosti · advance/status/delete: ID test clocku (clock_…)}
        {--plan=plus_monthly : start: kód plánu (plus_monthly|plus_yearly)}
        {--at= : start: zmrazený čas (napr. 2027-01-31 10:00, predvolene teraz) · advance: cieľový čas (napr. "+1 month", 2027-03-01)}
        {--name= : start: názov simulácie v Stripe}
        {--sync : status: najprv stiahnuť stav predplatného zo Stripe}
        {--wait=90 : advance: koľko sekúnd čakať, kým Stripe dokončí posun}';

    protected $description = 'Simulácia predplatného cez Stripe test clock (iba sandbox): start, advance, status, list, delete.';

    public function handle(TestClockSimulation $simulation): int
    {
        try {
            return match ((string) $this->argument('action')) {
                'start' => $this->start($simulation),
                'advance' => $this->advance($simulation),
                'status' => $this->status($simulation),
                'list' => $this->list($simulation),
                'delete' => $this->delete($simulation),
                default => $this->invalid('Akcia musí byť start, advance, status, list alebo delete.'),
            };
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        } catch (Throwable $e) {
            $this->components->error('Stripe: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function start(TestClockSimulation $simulation): int
    {
        $household = Household::find((int) $this->argument('target'));
        if ($household === null) {
            return $this->invalid('Zadaj ID existujúcej domácnosti (bez Stripe zákazníka).');
        }

        $at = $this->option('at') ? CarbonImmutable::parse((string) $this->option('at'), config('recipes.billing.timezone')) : CarbonImmutable::now();
        $result = $simulation->start($household, (string) $this->option('plan'), $at, $this->option('name') ?: null);

        $this->components->info("Test clock {$result['clock']} · zákazník {$result['customer']} · predplatné {$result['subscription']} ({$result['status']}) · zmrazené na {$at->toDateTimeString()} {$at->tzName}.");
        $this->line('Webhooky musia doraziť do tejto inštancie (lokálne: `stripe listen --forward-to '.route('cashier.webhook').'`). Potom: `php artisan app:billing-test-clock status '.$result['clock'].'`.');

        return self::SUCCESS;
    }

    private function advance(TestClockSimulation $simulation): int
    {
        $clockId = (string) $this->argument('target');
        if (! str_starts_with($clockId, 'clock_') || ! $this->option('at')) {
            return $this->invalid('Zadaj ID test clocku a --at (napr. --at="+1 month" alebo --at=2027-03-01).');
        }

        $current = app(StripeTestClocks::class)->retrieve($clockId);
        if ($current === null) {
            return $this->invalid("Test clock {$clockId} neexistuje.");
        }
        $base = CarbonImmutable::createFromTimestamp($current['frozen_time'], config('recipes.billing.timezone'));
        $to = $this->relativeTarget((string) $this->option('at'), $base);

        $this->components->task("Posúvam {$clockId} z {$base->toDateTimeString()} na {$to->toDateTimeString()} {$to->tzName}", fn () => true);
        $clock = $simulation->advance($clockId, $to, max(0, (int) $this->option('wait')));

        if ($clock['status'] !== 'ready') {
            $this->components->warn("Stripe stále posúva (stav {$clock['status']}); skús o chvíľu `status`.");

            return self::SUCCESS;
        }

        $this->components->info('Posunuté. Skontroluj webhooky (`/admin/stripe-events`) a stav: `php artisan app:billing-test-clock status '.$clockId.' --sync`.');

        return self::SUCCESS;
    }

    private function status(TestClockSimulation $simulation): int
    {
        $clockId = (string) $this->argument('target');
        if (! str_starts_with($clockId, 'clock_')) {
            return $this->invalid('Zadaj ID test clocku.');
        }

        $status = $simulation->status($clockId, (bool) $this->option('sync'));
        $clock = $status['clock'];
        $frozen = $clock ? CarbonImmutable::createFromTimestamp($clock['frozen_time'], config('recipes.billing.timezone'))->toDateTimeString() : '–';

        $this->components->twoColumnDetail('Test clock', $clock ? "{$clock['id']} · {$clock['status']} · {$frozen}" : "{$clockId} (v Stripe už neexistuje)");
        $this->components->twoColumnDetail('Domácnosť', $status['household'] ? "#{$status['household']->id} {$status['household']->name}" : 'neznáma (nie je v registri)');
        $this->components->twoColumnDetail('Stripe zákazník', (string) ($status['account']->stripe_id ?? '–'));
        $this->components->twoColumnDetail('Predplatné (lokálne)', $status['subscription'] ? "{$status['subscription']['stripe_id']} · {$status['subscription']['status']}".($status['subscription']['ends_at'] ? " · končí {$status['subscription']['ends_at']}" : '') : 'žiadne');

        $this->newLine();
        $this->line('<options=bold>Zaplatené obdobia</>');
        $this->table(['ID', 'Od (UTC)', 'Do (UTC)', 'Faktúra', 'Revokované'], array_map(fn ($e) => [$e['id'], $e['from'], $e['to'], $e['invoice'], $e['revoked'] ?? ''], $status['entitlements']));
        $this->line('<options=bold>Granty použití</>');
        $this->table(['ID', 'Druh', 'Zdroj', 'Kľúč', 'Množstvo', 'Použité', 'Od (UTC)', 'Do (UTC)'], array_map(fn ($g) => [$g['id'], $g['kind'], $g['source'], $g['key'], $g['quantity'], $g['used'], $g['from'], $g['to'] ?? ''], $status['grants']));

        return self::SUCCESS;
    }

    private function list(TestClockSimulation $simulation): int
    {
        $registry = $simulation->registry();
        $remote = collect(app(StripeTestClocks::class)->all())->keyBy('id');

        $rows = [];
        foreach ($registry as $id => $entry) {
            $clock = $remote->get($id);
            $rows[] = [$id, $entry['household_id'], $entry['plan_code'], $clock ? CarbonImmutable::createFromTimestamp($clock['frozen_time'], config('recipes.billing.timezone'))->toDateTimeString() : 'v Stripe neexistuje', $clock['status'] ?? '–'];
        }
        foreach ($remote as $id => $clock) {
            if (! isset($registry[$id])) {
                $rows[] = [$id, '–', '–', CarbonImmutable::createFromTimestamp($clock['frozen_time'], config('recipes.billing.timezone'))->toDateTimeString(), $clock['status'].' (mimo tejto inštancie)'];
            }
        }

        if ($rows === []) {
            $this->components->info('Žiadne test clocky.');

            return self::SUCCESS;
        }

        $this->table(['Test clock', 'Domácnosť', 'Plán', 'Zmrazený čas', 'Stav'], $rows);

        return self::SUCCESS;
    }

    private function delete(TestClockSimulation $simulation): int
    {
        $clockId = (string) $this->argument('target');
        if (! str_starts_with($clockId, 'clock_')) {
            return $this->invalid('Zadaj ID test clocku.');
        }

        $simulation->delete($clockId);
        $this->components->info("Test clock {$clockId} zmazaný v Stripe (aj so zákazníkom a predplatným). Lokálne záznamy domácnosti ostávajú na kontrolu.");

        return self::SUCCESS;
    }

    private function relativeTarget(string $input, CarbonImmutable $base): CarbonImmutable
    {
        $input = trim($input);

        return str_starts_with($input, '+') ? $base->modify($input) : CarbonImmutable::parse($input, $base->getTimezone());
    }

    private function invalid(string $message): int
    {
        $this->components->error($message);

        return self::INVALID;
    }
}

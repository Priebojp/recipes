<?php

namespace App\Console\Commands;

use App\Services\Admin\PlatformAdminManager;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Idempotent bootstrap of the platform administrator role. The only way the role is granted:
 * no seeded admin/admin, no "first registered user", no public endpoint.
 */
class GrantPlatformAdmin extends Command
{
    protected $signature = 'app:grant-platform-admin
        {email : E-mail účtu (existujúceho alebo nového)}
        {--name= : Meno pri vytváraní nového účtu}
        {--password= : Heslo pri vytváraní nového účtu (inak sa vygeneruje a raz vypíše)}
        {--create : Vytvoriť účet, ak neexistuje}
        {--revoke : Odobrať rolu}
        {--reason= : Dôvod do auditu}';

    protected $description = 'Udelí (alebo odoberie) rolu administrátora platformy existujúcemu účtu; s --create účet vytvorí.';

    public function handle(PlatformAdminManager $admins): int
    {
        $email = PlatformAdminManager::normalize((string) $this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Neplatný e-mail: {$email}");

            return self::INVALID;
        }

        $user = $admins->find($email);
        $reason = $this->stringOption('reason') ?? 'artisan app:grant-platform-admin';

        if ($this->option('revoke')) {
            if ($user === null) {
                $this->warn("Účet {$email} neexistuje – nie je čo odobrať.");

                return self::SUCCESS;
            }

            try {
                $removed = $admins->revoke($user, null, $reason);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info($removed ? "Rola administrátora odobratá účtu {$email}; jeho relácie boli odhlásené." : "Účet {$email} nebol administrátor.");

            return self::SUCCESS;
        }

        if ($user === null) {
            if (! $this->option('create')) {
                $this->error("Účet {$email} neexistuje. Použi --create (voliteľne s --name a --password), alebo nechaj používateľa najprv zaregistrovať a overiť e-mail.");

                return self::FAILURE;
            }

            $created = $admins->createUser($email, $this->stringOption('name'), $this->stringOption('password'));
            $user = $created['user'];
            $this->info("Účet {$email} vytvorený (e-mail označený ako overený).");
            if ($created['generated']) {
                $this->line('Vygenerované heslo (zobrazí sa iba raz, nikde sa neukladá v čitateľnej podobe):');
                $this->line('  '.$created['password']);
            } else {
                $this->warn('Heslo bolo zadané cez parameter – zmeň ho po prvom prihlásení a vymaž ho z histórie shellu.');
            }
        }

        $granted = $admins->grant($user, null, $reason);
        $this->info($granted ? "Rola administrátora udelená účtu {$email}." : "Účet {$email} už je administrátor – nič sa nezmenilo.");

        if ($user->two_factor_confirmed_at === null && config('admin.require_two_factor')) {
            $this->warn('Administrácia vyžaduje zapnuté dvojfaktorové overenie: používateľ si ho zapne v Nastavenia → Zabezpečenie, potom sa /admin otvorí.');
        }

        $this->line('Administrátorov spolu: '.$admins->count());

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}

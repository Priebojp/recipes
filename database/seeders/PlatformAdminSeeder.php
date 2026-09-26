<?php

namespace Database\Seeders;

use App\Services\Admin\PlatformAdminManager;
use Illuminate\Database\Seeder;

/**
 * Bootstraps the support administrator account (ADMIN_EMAIL, default support@moje-recepty.sk).
 * Outside production the password is ADMIN_INITIAL_PASSWORD or "password"; in production the account is only created
 * when ADMIN_INITIAL_PASSWORD is set – otherwise use `php artisan app:grant-platform-admin <email> --create`.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(PlatformAdminManager $admins): void
    {
        $email = (string) config('admin.bootstrap_email');
        if ($email === '') {
            return;
        }

        $user = $admins->find($email);

        if ($user === null) {
            $password = config('admin.bootstrap_password');
            if (! filled($password)) {
                if (app()->isProduction()) {
                    $this->command?->warn("PlatformAdminSeeder: účet {$email} neexistuje a ADMIN_INITIAL_PASSWORD nie je nastavené – spusti `php artisan app:grant-platform-admin {$email} --create`.");

                    return;
                }
                $password = 'password';
            }

            $user = $admins->createUser($email, (string) config('admin.bootstrap_name'), (string) $password)['user'];
            $this->command?->info("PlatformAdminSeeder: vytvorený účet {$email}.");
        }

        if ($admins->grant($user, null, 'database seeder')) {
            $this->command?->info("PlatformAdminSeeder: rola administrátora udelená účtu {$email}. Pre /admin je potrebné zapnúť dvojfaktorové overenie.");
        }
    }
}

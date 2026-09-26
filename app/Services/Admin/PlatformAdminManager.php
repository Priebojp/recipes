<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Grants and revokes the platform administrator role. Idempotent; the last administrator cannot be revoked.
 */
class PlatformAdminManager
{
    public function __construct(private AdminAuditor $audit) {}

    public function find(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [self::normalize($email)])->first();
    }

    /**
     * Create a verified account for the future administrator. Returns the user and the password that was set
     * (generated when none was given) so the caller can hand it over once; it is never stored in plain text.
     *
     * @return array{user: User, password: string, generated: bool}
     */
    public function createUser(string $email, ?string $name = null, ?string $password = null): array
    {
        $email = self::normalize($email);
        $generated = $password === null || $password === '';
        $password = $generated ? Str::password(20) : $password;

        $user = new User([
            'name' => $name !== null && trim($name) !== '' ? trim($name) : Str::before($email, '@'),
            'email' => $email,
            'password' => $password,
        ]);
        $user->email_verified_at = now();
        $user->save();

        return ['user' => $user, 'password' => $password, 'generated' => $generated];
    }

    /**
     * @return bool true when the role was newly granted, false when the user already had it
     */
    public function grant(User $user, ?User $actor = null, ?string $reason = null): bool
    {
        if ($user->isPlatformAdmin()) {
            return false;
        }

        $user->forceFill(['is_platform_admin' => true, 'platform_admin_granted_at' => now()])->save();
        $this->audit->record('admin.role.granted', $user, [], ['email' => $user->email], $reason, $actor);

        return true;
    }

    /**
     * @return bool true when the role was removed, false when the user was not an administrator
     *
     * @throws RuntimeException when this is the last administrator
     */
    public function revoke(User $user, ?User $actor = null, ?string $reason = null): bool
    {
        if (! $user->isPlatformAdmin()) {
            return false;
        }

        if ($this->count() <= 1) {
            throw new RuntimeException('Nemožno odobrať rolu poslednému administrátorovi – najprv udeľ rolu inému účtu.');
        }

        $user->forceFill(['is_platform_admin' => false, 'platform_admin_granted_at' => null])->save();
        $this->logoutEverywhere($user);
        $this->audit->record('admin.role.revoked', $user, ['email' => $user->email], [], $reason, $actor);

        return true;
    }

    public function count(): int
    {
        return User::query()->where('is_platform_admin', true)->count();
    }

    /**
     * Invalidate all sessions of the user (database session driver); other drivers cannot enumerate sessions.
     */
    public function logoutEverywhere(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        // Rotating the remember token invalidates "remember me" cookies regardless of the session driver.
        $user->setRememberToken(Str::random(60));
        $user->save();
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}

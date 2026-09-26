<?php

namespace App\Services\Admin;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value runtime settings stored in the database (app_settings) with a short cache.
 * Values fall back to config() defaults, so a missing table or an empty database keeps the .env behaviour.
 */
class AppSettings
{
    private const CACHE_KEY = 'app_settings.all';

    private const CACHE_SECONDS = 300;

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            $this->loaded = (array) Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
                return AppSetting::query()->pluck('value', 'key')->all();
            });
        } catch (QueryException) {
            // Before the migration ran (or during an install) behave as if nothing was overridden.
            $this->loaded = [];
        }

        return $this->loaded;
    }

    /**
     * Store a value (null removes the override so the config default applies again).
     */
    public function set(string $key, mixed $value, ?User $by = null): void
    {
        if ($value === null) {
            AppSetting::query()->whereKey($key)->delete();
        } else {
            AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
        }

        $this->forget();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, ?User $by = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $by);
        }
    }

    public function forget(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }
}

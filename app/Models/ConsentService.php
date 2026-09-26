<?php

namespace App\Models;

use App\Enums\ConsentCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One registered technology or third-party service: its purpose, category, inventory of cookies / storage and – for
 * optional services – how the client loads it once consent is given.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $provider
 * @property ConsentCategory $category
 * @property string $purpose
 * @property string|null $retention
 * @property string|null $location
 * @property list<array{name: string, kind: string, domain: string, duration: string, purpose: string}>|null $storage
 * @property array<string, mixed>|null $loader
 * @property bool $enabled
 * @property int $consent_version
 * @property int|null $updated_by
 */
#[Fillable(['key', 'name', 'provider', 'category', 'purpose', 'retention', 'location', 'storage', 'loader', 'enabled', 'consent_version', 'updated_by'])]
class ConsentService extends Model
{
    protected function casts(): array
    {
        return [
            'category' => ConsentCategory::class,
            'storage' => 'array',
            'loader' => 'array',
            'enabled' => 'boolean',
            'consent_version' => 'integer',
        ];
    }

    /** @param  Builder<ConsentService>  $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    public function isOptional(): bool
    {
        return $this->category->isOptional();
    }

    /**
     * Names of first-party cookies and storage keys this service sets (removed on withdrawal).
     *
     * @return list<array{name: string, kind: string, domain: string}>
     */
    public function managedStorage(): array
    {
        $entries = [];
        foreach ($this->storage ?? [] as $entry) {
            if ($entry['name'] === '') {
                continue;
            }
            $entries[] = ['name' => $entry['name'], 'kind' => $entry['kind'] !== '' ? $entry['kind'] : 'cookie', 'domain' => $entry['domain']];
        }

        return $entries;
    }
}

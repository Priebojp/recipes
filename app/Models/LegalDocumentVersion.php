<?php

namespace App\Models;

use App\Enums\LegalDocumentState;
use App\Enums\LegalDocumentType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One version of a legal document. Published versions are immutable; a change is a new draft version.
 *
 * @property int $id
 * @property LegalDocumentType $type
 * @property int $version
 * @property string $title
 * @property string $content
 * @property string $checksum
 * @property string|null $change_summary
 * @property LegalDocumentState $state
 * @property CarbonInterface|null $effective_at
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $archived_at
 * @property int|null $approved_by
 * @property CarbonInterface|null $approved_at
 * @property string|null $approval_note
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'type', 'version', 'title', 'content', 'checksum', 'change_summary', 'state', 'effective_at', 'published_at', 'archived_at',
    'approved_by', 'approved_at', 'approval_note', 'created_by',
])]
class LegalDocumentVersion extends Model
{
    /** Placeholders of the working texts: [OBCHODNÉ MENO], [EMAIL], [DOPRACOVAŤ PRED PUBLIKÁCIOU] … */
    public const PLACEHOLDER_PATTERN = '/\[[A-ZÁČĎÉÍĽĹŇÓÔŔŠŤÚÝŽ][^\]\n]{1,120}\]/u';

    protected function casts(): array
    {
        return [
            'type' => LegalDocumentType::class,
            'version' => 'integer',
            'state' => LegalDocumentState::class,
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @param  Builder<LegalDocumentVersion>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('state', LegalDocumentState::Published);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<LegalAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    public function isDraft(): bool
    {
        return $this->state === LegalDocumentState::Draft;
    }

    public function isPublished(): bool
    {
        return $this->state === LegalDocumentState::Published;
    }

    /** @return list<string> distinct placeholders still present in the text */
    public function placeholders(): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $this->content, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function hasPlaceholders(): bool
    {
        return $this->placeholders() !== [];
    }

    public static function checksumOf(string $content): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $content));
    }

    /** Safe HTML from the Markdown content (raw HTML stripped, unsafe links removed). */
    public function html(): string
    {
        return (string) Str::markdown($this->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function label(): string
    {
        return $this->type->shortLabel().' v'.$this->version;
    }
}

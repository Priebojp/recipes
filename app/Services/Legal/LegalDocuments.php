<?php

namespace App\Services\Legal;

use App\Enums\LegalAcceptanceAction;
use App\Enums\LegalDocumentState;
use App\Enums\LegalDocumentType;
use App\Models\Household;
use App\Models\LegalAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\AdminAuditor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Versioned legal documents: one published version per type at a time, immutable once published, drafts editable.
 * Publishing refuses texts with unresolved [PLACEHOLDERS] and records who approved them (specification chapter 9).
 */
class LegalDocuments
{
    public function __construct(private AdminAuditor $audit) {}

    /** The version the public pages, registration and checkout use right now. */
    public function current(LegalDocumentType $type): ?LegalDocumentVersion
    {
        return LegalDocumentVersion::query()->where('type', $type)->published()->orderByDesc('version')->first();
    }

    /** The newest version of any state – what the administrator previews. */
    public function latest(LegalDocumentType $type): ?LegalDocumentVersion
    {
        return LegalDocumentVersion::query()->where('type', $type)->orderByDesc('version')->first();
    }

    public function draft(LegalDocumentType $type): ?LegalDocumentVersion
    {
        return LegalDocumentVersion::query()->where('type', $type)->where('state', LegalDocumentState::Draft)->orderByDesc('version')->first();
    }

    /**
     * Published or archived versions readers may look up (the archive of a document).
     *
     * @return Collection<int, LegalDocumentVersion>
     */
    public function history(LegalDocumentType $type)
    {
        return LegalDocumentVersion::query()->where('type', $type)->whereIn('state', [LegalDocumentState::Published, LegalDocumentState::Archived])->orderByDesc('version')->get();
    }

    public function find(LegalDocumentType $type, int $version): ?LegalDocumentVersion
    {
        return LegalDocumentVersion::query()->where('type', $type)->where('version', $version)->first();
    }

    /**
     * Create a draft: a copy of the newest version (or the given seed text) as the next version number.
     * Only one draft per type exists at a time.
     */
    public function newDraft(LegalDocumentType $type, ?string $title = null, ?string $content = null, ?User $by = null, ?string $summary = null): LegalDocumentVersion
    {
        $existing = $this->draft($type);
        if ($existing !== null) {
            throw new InvalidArgumentException('Dokument už má rozpracovaný návrh v'.$existing->version.'. Uprav ho alebo ho najprv publikuj.');
        }

        $latest = $this->latest($type);
        $content ??= $latest !== null ? $latest->content : '';
        $title ??= $latest !== null ? $latest->title : $type->label();

        $draft = LegalDocumentVersion::create([
            'type' => $type,
            'version' => ($latest !== null ? $latest->version : 0) + 1,
            'title' => $title,
            'content' => $content,
            'checksum' => LegalDocumentVersion::checksumOf($content),
            'change_summary' => $summary,
            'state' => LegalDocumentState::Draft,
            'created_by' => $by?->id,
        ]);

        $this->audit->record('legal.document.draft_created', $draft, [], ['type' => $type->value, 'version' => $draft->version], $summary, $by);

        return $draft;
    }

    /**
     * @param  array{title?: string, content?: string, change_summary?: ?string, effective_at?: mixed}  $values
     */
    public function updateDraft(LegalDocumentVersion $version, array $values, ?User $by = null): LegalDocumentVersion
    {
        if (! $version->isDraft()) {
            throw new InvalidArgumentException('Publikovanú alebo archivovanú verziu nemožno upravovať; vytvor novú verziu.');
        }

        $before = ['title' => $version->title, 'checksum' => $version->checksum, 'effective_at' => $version->effective_at?->toIso8601String()];
        $content = array_key_exists('content', $values) ? str_replace("\r\n", "\n", (string) $values['content']) : $version->content;

        $version->fill([
            'title' => $values['title'] ?? $version->title,
            'content' => $content,
            'checksum' => LegalDocumentVersion::checksumOf($content),
            'change_summary' => array_key_exists('change_summary', $values) ? $values['change_summary'] : $version->change_summary,
            'effective_at' => array_key_exists('effective_at', $values) ? $values['effective_at'] : $version->effective_at,
        ])->save();

        $this->audit->record('legal.document.draft_updated', $version, $before, [
            'title' => $version->title, 'checksum' => $version->checksum, 'effective_at' => $version->effective_at?->toIso8601String(),
        ], null, $by);

        return $version;
    }

    /**
     * Publish a draft: no placeholders, an approval note, the previous published version becomes archived.
     */
    public function publish(LegalDocumentVersion $version, string $approvalNote, User $by): LegalDocumentVersion
    {
        if (! $version->isDraft()) {
            throw new InvalidArgumentException('Publikovať možno iba návrh.');
        }
        if ($version->hasPlaceholders()) {
            throw new InvalidArgumentException('Text obsahuje nevyplnené údaje: '.implode(', ', $version->placeholders()).'. Publikovanie placeholderov ako skutočných údajov nie je dovolené.');
        }
        if (trim($version->content) === '') {
            throw new InvalidArgumentException('Prázdny dokument nemožno publikovať.');
        }

        DB::transaction(function () use ($version, $approvalNote, $by) {
            LegalDocumentVersion::query()
                ->where('type', $version->type)
                ->where('state', LegalDocumentState::Published)
                ->whereKeyNot($version->id)
                ->update(['state' => LegalDocumentState::Archived->value, 'archived_at' => now(), 'updated_at' => now()]);

            $version->fill([
                'state' => LegalDocumentState::Published,
                'published_at' => now(),
                'effective_at' => $version->effective_at ?? now(),
                'approved_by' => $by->id,
                'approved_at' => now(),
                'approval_note' => $approvalNote,
                'checksum' => LegalDocumentVersion::checksumOf($version->content),
            ])->save();
        });

        $this->audit->record('legal.document.published', $version, [], [
            'type' => $version->type->value, 'version' => $version->version, 'checksum' => $version->checksum, 'effective_at' => $version->effective_at?->toIso8601String(),
        ], $approvalNote, $by);

        return $version;
    }

    /** Take a published version offline without a successor (the page then says the document is being prepared). */
    public function archive(LegalDocumentVersion $version, string $reason, User $by): LegalDocumentVersion
    {
        if (! $version->isPublished()) {
            throw new InvalidArgumentException('Archivovať možno iba publikovanú verziu.');
        }
        $version->update(['state' => LegalDocumentState::Archived, 'archived_at' => now()]);
        $this->audit->record('legal.document.archived', $version, [], ['type' => $version->type->value, 'version' => $version->version], $reason, $by);

        return $version;
    }

    /**
     * Record that a person accepted a document version in a given act.
     *
     * @param  array<string, mixed>  $acknowledgements
     */
    public function recordAcceptance(
        LegalDocumentVersion $version,
        LegalAcceptanceAction $action,
        ?User $user = null,
        ?Household $household = null,
        ?Order $order = null,
        array $acknowledgements = [],
        ?string $email = null,
    ): LegalAcceptance {
        return LegalAcceptance::create([
            'legal_document_version_id' => $version->id,
            'user_id' => $user?->id,
            'household_id' => $household?->id,
            'order_id' => $order?->id,
            'action' => $action,
            'checksum' => $version->checksum,
            'acknowledgements' => $acknowledgements === [] ? null : $acknowledgements,
            'email' => $email,
            'accepted_at' => now(),
        ]);
    }
}

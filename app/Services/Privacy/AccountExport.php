<?php

namespace App\Services\Privacy;

use App\Models\ConsentReceipt;
use App\Models\ConsumptionNutritionSnapshot;
use App\Models\LegalAcceptance;
use App\Models\MealAnalysis;
use App\Models\MealAnalysisItem;
use App\Models\MealConsumption;
use App\Models\PrivacyRequest;
use App\Models\User;
use ZipArchive;

/**
 * Machine-readable export of the account itself: profile, memberships, acceptances, consent receipts, requests
 * and – because they are personal, not the household's – the photo analyses (stage 11) with the photos a person
 * kept and the diary (stage 12) with every snapshot revision. Household content (recipes, people, plans, images)
 * is the separate ZIP export of the household.
 */
class AccountExport
{
    /** 2: meal_analyses (with kept photos) and meal_consumptions (with snapshots) added (v2.1 stage 12). */
    public const SCHEMA_VERSION = 2;

    /** @var array<string, string> zip entry => absolute path */
    private array $files = [];

    public function build(User $user): string
    {
        $path = tempnam(sys_get_temp_dir(), 'account-export').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = [];
        $data = $this->data($user, $files);
        $zip->addFromString('ucet.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ($files as $entry => $absolutePath) {
            if (is_file($absolutePath)) {
                $zip->addFile($absolutePath, $entry);
            }
        }
        $zip->close();

        return $path;
    }

    /**
     * @param  array<string, string>  $files  filled with zip entry => absolute path of kept photos
     * @return array<string, mixed>
     */
    public function data(User $user, array &$files = []): array
    {
        $this->files = [];

        $data = [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'meal_photo_notice_accepted_at' => $user->meal_photo_notice_accepted_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'households' => $user->memberships()->with('household:id,name')->get()->map(fn ($m) => ['id' => $m->household_id, 'name' => $m->household?->name, 'role' => $m->role->value])->all(),
            'legal_acceptances' => LegalAcceptance::query()->where('user_id', $user->id)->with('documentVersion:id,type,version,title')->get()->map(fn (LegalAcceptance $a) => [
                'document' => $a->documentVersion?->type->value, 'version' => $a->documentVersion?->version, 'title' => $a->documentVersion?->title,
                'action' => $a->action->value, 'order_id' => $a->order_id, 'acknowledgements' => $a->acknowledgements, 'accepted_at' => $a->accepted_at->toIso8601String(),
            ])->all(),
            'consent_receipts' => ConsentReceipt::query()->where('user_id', $user->id)->orderBy('id')->get()->map(fn (ConsentReceipt $r) => [
                'policy_version' => $r->policy_version, 'action' => $r->action, 'categories' => $r->categories, 'at' => $r->created_at->toIso8601String(),
            ])->all(),
            'privacy_requests' => PrivacyRequest::query()->where('user_id', $user->id)->orderBy('id')->get()->map(fn (PrivacyRequest $r) => [
                'kind' => $r->kind->value, 'status' => $r->status->value, 'received_at' => $r->received_at->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String(),
            ])->all(),
            'meal_analyses' => MealAnalysis::query()->where('user_id', $user->id)->with(['items.record', 'media'])->orderBy('id')->get()->map(fn (MealAnalysis $a) => $this->analysisData($a))->all(),
            'meal_consumptions' => MealConsumption::query()->withTrashed()->where('user_id', $user->id)->with('snapshots')->orderBy('id')->get()->map(fn (MealConsumption $c) => [
                'id' => $c->id, 'household_id' => $c->household_id, 'eaten_at' => $c->eaten_at->toIso8601String(), 'timezone' => $c->timezone,
                'eaten_on' => $c->eaten_on->toDateString(), 'source' => $c->source->value, 'recipe_id' => $c->recipe_id, 'recipe_revision_id' => $c->recipe_revision_id,
                'nutrition_calculation_id' => $c->nutrition_calculation_id, 'meal_analysis_id' => $c->meal_analysis_id, 'title' => $c->title_snapshot,
                'portion_mode' => $c->portion_mode->value, 'portion_fraction' => $c->portion_fraction === null ? null : (float) $c->portion_fraction,
                'grams' => $c->grams === null ? null : (float) $c->grams, 'note' => $c->note, 'created_at' => $c->created_at?->toIso8601String(),
                'deleted_at' => $c->deleted_at?->toIso8601String(),
                'snapshots' => $c->snapshots->map(fn (ConsumptionNutritionSnapshot $s) => [
                    'revision' => $s->revision, 'calculation_version' => $s->calculation_version, 'portion_mode' => $s->portion_mode->value,
                    'portion_fraction' => $s->portion_fraction === null ? null : (float) $s->portion_fraction, 'grams' => $s->grams === null ? null : (float) $s->grams,
                    'component_shares' => $s->component_shares, 'basis' => $s->basis, 'totals' => $s->totals, 'completeness' => $s->completeness?->value,
                    'components' => $s->components, 'missing' => $s->missing, 'assumptions' => $s->assumptions, 'manual_origin' => $s->manual_origin?->value,
                    'created_at' => $s->created_at->toIso8601String(),
                ])->all(),
            ])->all(),
        ];

        $files = $this->files;

        return $data;
    }

    /** @return array<string, mixed> */
    private function analysisData(MealAnalysis $a): array
    {
        $photo = $a->hasPhoto() ? $a->photo() : null;
        $entry = null;
        if ($photo !== null) {
            $entry = 'fotky/analyza-'.$a->id.'-'.$photo->file_name;
            $this->files[$entry] = $photo->getPath();
        }

        return [
            'id' => $a->id, 'household_id' => $a->household_id, 'status' => $a->status->value, 'ai_status' => $a->ai_status?->value, 'note' => $a->note,
            'dish_name' => $a->dish_name, 'questions' => $a->questions, 'limitations' => $a->limitations, 'clarification_count' => $a->clarification_count,
            'ai_result' => $a->ai_result, 'nutrition' => $a->nutrition, 'photo' => $entry, 'photo_retain_until' => $a->photo_retain_until?->toIso8601String(),
            'photo_removed_at' => $a->photo_removed_at?->toIso8601String(), 'confirmed_at' => $a->confirmed_at?->toIso8601String(), 'created_at' => $a->created_at?->toIso8601String(),
            'items' => $a->items->map(fn (MealAnalysisItem $i) => [
                'label' => $i->label, 'alternatives' => $i->alternatives, 'preparation_state' => $i->preparation_state?->value,
                'estimated_grams' => $i->estimated_grams === null ? null : (float) $i->estimated_grams, 'grams' => $i->grams === null ? null : (float) $i->grams,
                'grams_origin' => $i->grams_origin?->value, 'portion_basis' => $i->portion_basis, 'visible_evidence' => $i->visible_evidence, 'assumptions' => $i->assumptions,
                'food' => $i->record === null ? null : ['record_id' => $i->record->id, 'provider' => $i->record->provider, 'external_id' => $i->record->external_id, 'name' => $i->record->name],
                'mapping_status' => $i->mapping_status->value, 'is_unknown' => $i->is_unknown, 'included' => $i->included,
            ])->all(),
        ];
    }
}

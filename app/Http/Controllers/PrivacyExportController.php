<?php

namespace App\Http\Controllers;

use App\Models\ConsentReceipt;
use App\Models\LegalAcceptance;
use App\Models\PrivacyRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Machine-readable export of the account itself (profile, memberships, acceptances, consent receipts, requests).
 * Household content (recipes, people, plans, images) is the separate ZIP export of the household.
 */
class PrivacyExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $data = [
            'schema_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
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
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="ucet-export-'.now()->format('Y-m-d').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

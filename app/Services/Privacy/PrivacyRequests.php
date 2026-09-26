<?php

namespace App\Services\Privacy;

use App\Enums\PrivacyRequestKind;
use App\Enums\PrivacyRequestStatus;
use App\Models\Household;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\Admin\AdminAuditor;

/**
 * Register of data-subject requests with deadlines (specification chapter 11: "viesť evidenciu žiadostí a lehoty").
 */
class PrivacyRequests
{
    public function __construct(private AdminAuditor $audit) {}

    public function open(PrivacyRequestKind $kind, ?User $user = null, ?Household $household = null, ?string $email = null, ?string $message = null): PrivacyRequest
    {
        return PrivacyRequest::create([
            'user_id' => $user?->id,
            'household_id' => $household?->id,
            'subject_email' => $email ?? $user?->email,
            'kind' => $kind,
            'status' => PrivacyRequestStatus::Received,
            'message' => $message,
            'received_at' => now(),
            'deadline_at' => now()->addDays((int) config('recipes.privacy.request_deadline_days', 30)),
        ]);
    }

    public function update(PrivacyRequest $request, PrivacyRequestStatus $status, string $note, User $by): PrivacyRequest
    {
        $before = ['status' => $request->status->value];
        $request->fill([
            'status' => $status,
            'handled_by' => $by->id,
            'completed_at' => in_array($status, [PrivacyRequestStatus::Completed, PrivacyRequestStatus::Rejected], true) ? ($request->completed_at ?? now()) : null,
            'completion_evidence' => trim(($request->completion_evidence ? $request->completion_evidence."\n" : '').now()->format('Y-m-d H:i').' '.$by->email.': '.$note),
        ])->save();

        $this->audit->record('privacy.request.updated', $request, $before, ['status' => $status->value], $note, $by);

        return $request;
    }
}

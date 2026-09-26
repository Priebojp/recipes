<?php

namespace App\Services\Privacy;

use App\Enums\MembershipRole;
use App\Enums\PrivacyRequestKind;
use App\Enums\PrivacyRequestStatus;
use App\Enums\UsageKind;
use App\Mail\AccountErasedMail;
use App\Models\AiJob;
use App\Models\CookingEvent;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMembership;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\PrivacyRequest;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\SelectionSession;
use App\Models\User;
use App\Services\Admin\AdminAuditor;
use App\Services\Billing\Gateway\StripeGateway;
use App\Services\Billing\PlanStatus;
use App\Services\Usage\UsageLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Self-service erasure of an account (specification chapter 11). Recipes, people, plans, history, AI jobs and
 * images of the household go; financial records (orders, refunds, ledger) stay on an anonymised household because
 * of accounting duties – never the recipe profile. Every erasure is a completed privacy request plus an audit row.
 */
class AccountErasure
{
    public function __construct(
        private PrivacyRequests $requests,
        private AdminAuditor $audit,
        private PlanStatus $plans,
        private UsageLedger $ledger,
        private StripeGateway $gateway,
    ) {}

    /**
     * What the person loses, shown before confirmation.
     *
     * @return array{owned: list<array{household: Household, other_members: int, plus_until: ?CarbonInterface, renewal_active: bool, unused_purchased: array<string, int>, financial_records: bool}>, member_of: list<Household>}
     */
    public function impact(User $user): array
    {
        $owned = [];
        $memberOf = [];
        foreach ($user->households()->get() as $household) {
            if ((int) $household->owner_user_id === (int) $user->id) {
                $unused = [];
                foreach (UsageKind::cases() as $kind) {
                    $purchased = $this->ledger->balance($household, $kind)->purchasedAvailable;
                    if ($purchased > 0) {
                        $unused[$kind->value] = $purchased;
                    }
                }
                $subscription = $this->plans->subscription($household);
                $owned[] = [
                    'household' => $household,
                    'other_members' => $household->memberships()->where('user_id', '!=', $user->id)->count(),
                    'plus_until' => $this->plans->paidThrough($household),
                    'renewal_active' => $subscription !== null && ! $subscription->canceled() && ! $subscription->ended(),
                    'unused_purchased' => $unused,
                    'financial_records' => $this->hasFinancialRecords($household),
                ];
            } else {
                $memberOf[] = $household;
            }
        }

        return ['owned' => $owned, 'member_of' => $memberOf];
    }

    /**
     * Erase the account. Owned households are erased (or handed over to $transferTo when given and a member).
     */
    public function erase(User $user, ?User $transferTo = null, ?string $message = null): PrivacyRequest
    {
        $email = $user->email;
        $request = $this->requests->open(PrivacyRequestKind::Erasure, $user, $user->currentHousehold(), $email, $message);
        $evidence = [];
        $keepUser = false;

        DB::transaction(function () use ($user, $transferTo, &$evidence, &$keepUser) {
            foreach ($user->households()->get() as $household) {
                if ((int) $household->owner_user_id !== (int) $user->id) {
                    $evidence[] = $this->leaveHousehold($user, $household);

                    continue;
                }

                if ($transferTo !== null && $transferTo->isNot($user) && HouseholdMembership::query()->where('household_id', $household->id)->where('user_id', $transferTo->id)->exists()) {
                    $evidence[] = $this->transferHousehold($user, $household, $transferTo);

                    continue;
                }

                $this->cancelRenewalQuietly($household);
                $evidence[] = $this->purgeHouseholdContent($household);

                if ($this->hasFinancialRecords($household)) {
                    $household->forceFill([
                        'name' => 'Zrušená domácnosť #'.$household->id,
                        'default_person_ids' => null,
                        'erased_at' => now(),
                    ])->save();
                    $keepUser = true; // households.owner_user_id cascades; the anonymised row must keep an owner
                    $evidence[] = "Domácnosť #{$household->id} anonymizovaná, finančné záznamy ponechané (účtovná povinnosť).";
                } else {
                    $household->delete();
                    $evidence[] = "Domácnosť #{$household->id} zmazaná.";
                }
            }

            if ($keepUser) {
                $this->anonymiseUser($user);
                $evidence[] = "Účet #{$user->id} anonymizovaný (e-mail, meno, heslo, MFA, passkeys, relácie).";
            } else {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->delete();
                $evidence[] = "Účet #{$user->id} zmazaný.";
            }
        });

        $request->fill([
            'status' => PrivacyRequestStatus::Completed,
            'user_id' => $keepUser ? $user->id : null,
            'completed_at' => now(),
            'completion_evidence' => implode("\n", $evidence),
        ])->save();

        $this->audit->record('privacy.account.erased', $request, [], ['evidence' => $evidence], $message, null);

        try {
            Mail::to($email)->send(new AccountErasedMail($request->id, now()));
        } catch (Throwable $e) {
            report($e);
        }

        return $request;
    }

    /**
     * Idempotent re-application after a backup restore (acceptance test 21): every completed erasure whose
     * household content reappeared is purged again.
     *
     * @return list<int> household ids purged again
     */
    public function reapplyCompleted(): array
    {
        $again = [];
        $requests = PrivacyRequest::query()->where('kind', PrivacyRequestKind::Erasure)->where('status', PrivacyRequestStatus::Completed)->whereNotNull('household_id')->get();
        foreach ($requests as $request) {
            $household = Household::query()->find($request->household_id);
            if ($household === null) {
                continue;
            }
            if ($this->hasContent($household) || ! $household->isErased()) {
                DB::transaction(function () use ($household) {
                    $this->purgeHouseholdContent($household);
                    $household->forceFill(['name' => 'Zrušená domácnosť #'.$household->id, 'default_person_ids' => null, 'erased_at' => $household->erased_at ?? now()])->save();
                    if ($household->owner !== null) {
                        $this->anonymiseUser($household->owner);
                    }
                });
                $again[] = $household->id;
                $this->audit->record('privacy.erasure.reapplied', $request, [], ['household_id' => $household->id]);
            }
        }

        return $again;
    }

    public function hasFinancialRecords(Household $household): bool
    {
        return $household->orders()->exists() || $household->refundCases()->exists() || $household->paidEntitlements()->whereNotNull('order_id')->exists();
    }

    public function hasContent(Household $household): bool
    {
        return $household->recipes()->exists() || $household->people()->exists() || $household->mealPlans()->exists()
            || $household->cookingEvents()->exists() || AiJob::query()->where('household_id', $household->id)->exists();
    }

    private function leaveHousehold(User $user, Household $household): string
    {
        Person::query()->where('household_id', $household->id)->where('user_id', $user->id)
            ->update(['name' => 'Bývalý člen', 'user_id' => null, 'archived_at' => now(), 'updated_at' => now()]);
        HouseholdMembership::query()->where('household_id', $household->id)->where('user_id', $user->id)->delete();

        return "Členstvo v domácnosti #{$household->id} ukončené, profil stravníka anonymizovaný.";
    }

    private function transferHousehold(User $user, Household $household, User $to): string
    {
        HouseholdMembership::query()->where('household_id', $household->id)->where('user_id', $to->id)->update(['role' => MembershipRole::Owner->value, 'updated_at' => now()]);
        $household->forceFill(['owner_user_id' => $to->id])->save();
        $household->billingAccount()->where('payer_user_id', $user->id)->update(['payer_user_id' => $to->id, 'updated_at' => now()]);
        $this->leaveHousehold($user, $household);

        return "Domácnosť #{$household->id} prevedená na používateľa #{$to->id}.";
    }

    private function purgeHouseholdContent(Household $household): string
    {
        $counts = ['recipes' => 0, 'people' => 0, 'ai_jobs' => 0];

        foreach (Recipe::query()->where('household_id', $household->id)->get() as $recipe) {
            $recipe->clearMediaCollection(Recipe::COVER_COLLECTION);
            foreach ($recipe->steps as $step) {
                $step->clearMediaCollection(RecipeStep::IMAGES_COLLECTION);
            }
            $recipe->delete();
            $counts['recipes']++;
        }

        $counts['ai_jobs'] = AiJob::query()->where('household_id', $household->id)->delete();
        SelectionSession::query()->where('household_id', $household->id)->delete();
        MealPlan::query()->where('household_id', $household->id)->delete();
        CookingEvent::query()->where('household_id', $household->id)->delete();
        HouseholdInvitation::query()->where('household_id', $household->id)->delete();
        $counts['people'] = Person::query()->where('household_id', $household->id)->delete();
        HouseholdMembership::query()->where('household_id', $household->id)->delete();

        return "Domácnosť #{$household->id}: zmazaných {$counts['recipes']} receptov s obrázkami, {$counts['people']} profilov, {$counts['ai_jobs']} AI úloh, plány, história, pozvánky a členstvá.";
    }

    private function anonymiseUser(User $user): void
    {
        DB::table('passkeys')->where('user_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill([
            'name' => 'Zrušený účet',
            'email' => 'erased-'.$user->id.'@erased.invalid',
            'password' => Str::random(48),
            'email_verified_at' => null,
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'is_platform_admin' => false,
        ])->save();
    }

    private function cancelRenewalQuietly(Household $household): void
    {
        $subscription = $this->plans->subscription($household);
        if ($subscription === null || $subscription->canceled() || $subscription->ended()) {
            return;
        }
        try {
            $this->gateway->cancelRenewal($subscription);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

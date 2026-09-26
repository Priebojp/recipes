<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\LegalAcceptanceAction;
use App\Enums\LegalDocumentType;
use App\Models\User;
use App\Services\Legal\LegalDocuments;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private LegalDocuments $documents) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Registration takes a separate acceptance of the published terms only. Operational processing rests on the
        // contract, so no "GDPR consent" checkbox exists; marketing consent, if ever added, is separate and optional.
        $terms = $this->documents->current(LegalDocumentType::Terms);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'terms' => $terms !== null ? ['accepted'] : ['nullable'],
        ], [
            'terms.accepted' => 'Pre vytvorenie účtu je potrebné prijať obchodné podmienky.',
        ])->validate();

        return DB::transaction(function () use ($input, $terms) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $household = CurrentHousehold::createFor($user);

            if ($terms !== null) {
                $this->documents->recordAcceptance($terms, LegalAcceptanceAction::Registration, $user, $household);
            }

            return $user;
        });
    }
}

<?php

namespace Database\Seeders;

use App\Enums\PersonKind;
use App\Enums\Preference;
use App\Models\Person;
use App\Models\Recipe;
use App\Models\User;
use App\Services\CookingHistoryService;
use App\Services\MealPlanningService;
use App\Services\PreferenceService;
use App\Services\RecipeService;
use App\Support\CurrentHousehold;
use Illuminate\Database\Seeder;

/**
 * Fictional demo household (no real people). Login: demo@example.com / password
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create(['name' => 'Demo', 'email' => 'demo@example.com']);
        $household = CurrentHousehold::createFor($user, 'Demo domácnosť');
        app(CurrentHousehold::class)->set($household);

        $demo = $household->people()->firstOrFail();
        $partner = Person::create(['household_id' => $household->id, 'name' => 'Partnerka', 'color' => '#ec4899']);
        $child = Person::create(['household_id' => $household->id, 'name' => 'Dieťa', 'color' => '#22c55e']);
        $guest = Person::create(['household_id' => $household->id, 'name' => 'Návšteva', 'kind' => PersonKind::Guest]);
        $household->update(['default_person_ids' => [$demo->id, $partner->id, $child->id]]);

        $recipes = app(RecipeService::class);
        $prefs = app(PreferenceService::class);

        $data = [
            ['Praženica', null, ['breakfast'], null, null, [], [], 'unknown'],
            ['Kurací paprikáš', 'Kuracie kúsky na paprike so smotanovou omáčkou', ['lunch', 'dinner'], 4, [10, 35],
                [['Kuracie prsia', '600', 'g'], ['Cibuľa', '2', 'ks'], ['Mletá paprika', '1', 'PL'], ['Smotana na varenie', '200', 'ml'], ['Soľ', 'podľa chuti', null]],
                ['Cibuľu opražíme na masle do sklovita.', 'Pridáme mäso, papriku a krátko orestujeme.', 'Podlejeme vodou a dusíme 30 minút.', 'Zjemníme smotanou a dochutíme.'],
                'needs_side'],
            ['Hríbové rizoto', 'Krémové rizoto s lesnými hríbmi a parmezánom', ['lunch', 'dinner'], 2, [15, 25],
                [['Ryža arborio', '250', 'g'], ['Hríby', '300', 'g'], ['Parmezán', '50', 'g'], ['Vývar', '1', 'l'], ['Maslo', 'trochu', null]],
                ['Hríby orestujeme a odložíme.', 'Ryžu zapotíme na cibuľke a postupne podlievame vývarom.', 'Vmiešame hríby, maslo a parmezán.'],
                'complete'],
            ['Kapustnica', 'Kyslá kapustová polievka s klobásou', ['lunch', 'dinner'], 6, [20, 90],
                [['Kyslá kapusta', '700', 'g'], ['Klobása', '300', 'g'], ['Sušené huby', '1', 'hrsť'], ['Cibuľa', '1', 'ks']],
                ['Kapustu s hubami varíme v hrnci.', 'Pridáme klobásu a dovaríme.'],
                'complete'],
            ['Palacinky', 'Tenké palacinky s džemom', ['breakfast', 'dinner'], 4, [5, 20],
                [['Múka', '250', 'g'], ['Mlieko', '500', 'ml'], ['Vajcia', '2', 'ks'], ['Džem', 'podľa chuti', null]],
                ['Zmiešame cesto a necháme odpočinúť.', 'Smažíme na panvici z oboch strán.'],
                'complete'],
            ['Pečené kura so zemiakmi', 'Kura pečené v jednom pekáči so zemiakmi a rozmarínom', ['lunch', 'dinner'], 4, [15, 75],
                [['Kura', '1', 'ks'], ['Zemiaky', '1', 'kg'], ['Rozmarín', '2', 'vetvičky'], ['Cesnak', '4', 'strúčiky']],
                ['Kura potrieme korením a uložíme do pekáča so zemiakmi.', 'Pečieme v rúre 75 minút.'],
                'complete'],
            ['Šošovicový prívarok', 'Hustý prívarok zo šošovice', ['lunch', 'dinner'], 4, [10, 40],
                [['Šošovica', '300', 'g'], ['Cibuľa', '1', 'ks'], ['Múka', '2', 'PL'], ['Ocot', 'podľa chuti', null]],
                ['Šošovicu uvaríme.', 'Pripravíme zápražku a zahustíme.'],
                'needs_side'],
            ['Babkina dobrota', null, [], null, null, [], [], 'unknown'],
            ['Špagety bolonské', 'Špagety s hovädzou omáčkou', ['lunch', 'dinner'], 4, [10, 45],
                [['Špagety', '400', 'g'], ['Mleté hovädzie', '500', 'g'], ['Paradajky v konzerve', '400', 'g'], ['Cibuľa', '1', 'ks']],
                ['Omáčku dusíme 40 minút.', 'Špagety uvaríme al dente.'],
                'complete'],
            ['Ovsená kaša', null, ['breakfast'], 1, [2, 5], [['Ovsené vločky', '60', 'g'], ['Mlieko', '200', 'ml']], ['Varíme za stáleho miešania.'], 'complete'],
        ];

        /** @var array<string, Recipe> $models */
        $models = [];
        foreach ($data as [$title, $description, $types, $servings, $times, $ingredients, $steps, $side]) {
            $recipe = $recipes->quickCreate($household, $user, ['title' => $title, 'description' => $description, 'meal_types' => $types]);
            $models[$title] = $recipes->update($recipe, $user, [
                'base_servings' => $servings,
                'prep_minutes' => $times[0] ?? null,
                'cook_minutes' => $times[1] ?? null,
                'side_requirement' => $side,
                'ingredients' => array_map(fn ($l) => ['name' => $l[0], 'amount' => $l[1], 'unit' => $l[2]], $ingredients),
                'steps' => array_map(fn ($s) => ['text' => $s], $steps),
            ]);
        }

        $prefs->set($demo, $models['Kurací paprikáš'], Preference::Favorite);
        $prefs->set($partner, $models['Kurací paprikáš'], Preference::Eats);
        $prefs->set($child, $models['Kurací paprikáš'], Preference::Favorite);
        $prefs->set($demo, $models['Hríbové rizoto'], Preference::Favorite);
        $prefs->set($partner, $models['Hríbové rizoto'], Preference::Favorite);
        $prefs->set($child, $models['Hríbové rizoto'], Preference::Dislikes);
        $prefs->set($child, $models['Palacinky'], Preference::Favorite);
        $prefs->set($demo, $models['Kapustnica'], Preference::Eats);
        $prefs->set($partner, $models['Šošovicový prívarok'], Preference::Dislikes);
        $prefs->exclude($child, $models['Kapustnica'], 'príliš pikantné', $user);

        $history = app(CookingHistoryService::class);
        $history->record($household, $models['Špagety bolonské'], ['cooked_on' => now()->subDay()->toDateString(), 'person_ids' => [$demo->id, $partner->id, $child->id]], $user);
        $history->record($household, $models['Kurací paprikáš'], ['cooked_on' => now()->subDays(10)->toDateString(), 'person_ids' => [$demo->id, $partner->id]], $user);
        $history->record($household, $models['Kapustnica'], ['cooked_on' => now()->subDays(3)->toDateString(), 'person_ids' => [$guest->id]], $user);

        app(MealPlanningService::class)->create($household, $models['Palacinky'], ['mode' => 'date', 'scheduled_date' => now()->addDay()->toDateString(), 'meal_type' => 'dinner', 'servings' => 3, 'person_ids' => [$demo->id, $partner->id, $child->id]], $user);
        app(MealPlanningService::class)->create($household, $models['Pečené kura so zemiakmi'], ['mode' => 'someday'], $user);
    }
}

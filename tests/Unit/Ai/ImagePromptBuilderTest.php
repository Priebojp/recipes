<?php

use App\Services\Ai\ImagePromptBuilder;

it('asks for a description when only a title is known', function () {
    $result = (new ImagePromptBuilder)->build(['title' => 'Babkina dobrota']);

    expect($result['needs_description'])->toBeTrue()->and($result['prompt'])->toBeNull();
});

it('puts a sauce that needs a missing side into a casserole without a side', function () {
    $result = (new ImagePromptBuilder)->build([
        'title' => 'Paprikáš',
        'description' => 'Kuracie kúsky na paprike so smotanovou omáčkou',
        'side_requirement' => 'needs_side',
        'steps' => [['text' => 'V kastróle opražíme cibuľu a dusíme mäso.']],
        'ingredients' => [['name' => 'kuracie prsia'], ['name' => 'paprika']],
    ]);

    expect($result['serving_mode'])->toBe('casserole')
        ->and($result['auto_suggested'])->toBeTrue()
        ->and($result['summary'])->toContain('v kastróle')->toContain('bez prílohy')
        ->and($result['prompt'])->toContain('Potvrdené servírovanie: v kastróle')
        ->toContain('Samostatná príloha, iba ak je explicitne súčasťou receptu: žiadna')
        ->not->toContain('ryža');
});

it('puts a complete meal on a plate, soup in a bowl and roasted meat in a baking dish', function () {
    $builder = new ImagePromptBuilder;

    $risotto = $builder->build(['title' => 'Rizoto', 'description' => 'Hríbové rizoto', 'side_requirement' => 'complete']);
    $soup = $builder->build(['title' => 'Kapustnica', 'description' => 'Kyslá kapustová polievka', 'side_requirement' => 'complete']);
    $roast = $builder->build(['title' => 'Pečené mäso', 'description' => 'Bravčové pečené v rúre', 'side_requirement' => 'needs_side', 'steps' => [['text' => 'Pečieme v pekáči 2 hodiny.']]]);

    expect($risotto['serving_mode'])->toBe('plate')
        ->and($soup['serving_mode'])->toBe('bowl')
        ->and($roast['serving_mode'])->toBe('baking_dish');
});

it('lets the user override the serving mode and mentions an explicitly included side', function () {
    $result = (new ImagePromptBuilder)->build([
        'title' => 'Kuracie s ryžou', 'description' => 'Kura', 'side_requirement' => 'complete', 'included_side' => 'ryža', 'serving_mode' => 'auto',
    ], null, 'pot');

    expect($result['serving_mode'])->toBe('pot')
        ->and($result['auto_suggested'])->toBeFalse()
        ->and($result['prompt'])->toContain('Samostatná príloha, iba ak je explicitne súčasťou receptu: ryža');
});

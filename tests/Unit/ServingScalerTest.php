<?php

use App\Models\IngredientLine;
use App\Services\IngredientAmountParser;
use App\Services\ServingScaler;

it('scales numeric amounts, keeps text amounts and does not touch the originals', function () {
    $lines = [
        new IngredientLine(['name' => 'Kuracie prsia', 'numeric_amount' => '500', 'unit' => 'g']),
        new IngredientLine(['name' => 'Soľ', 'text_amount' => 'podľa chuti']),
        new IngredientLine(['name' => 'Cibuľa', 'numeric_amount' => '0.5', 'unit' => 'ks']),
    ];

    $scaled = (new ServingScaler)->scale($lines, 2, 4);

    expect($scaled[0]['amount'])->toBe('1000')->and($scaled[0]['scaled'])->toBeTrue()
        ->and($scaled[1]['amount'])->toBe('podľa chuti')->and($scaled[1]['scaled'])->toBeFalse()
        ->and($scaled[2]['amount'])->toBe('1')
        ->and($lines[0]->numeric_amount)->toBe('500.000');
});

it('does not scale without base servings', function () {
    $lines = [new IngredientLine(['name' => 'Múka', 'numeric_amount' => '300', 'unit' => 'g'])];
    $scaler = new ServingScaler;

    expect($scaler->canScale(null))->toBeFalse()
        ->and($scaler->scale($lines, null, 4)[0]['amount'])->toBe('300');
});

it('parses numeric and textual amounts', function () {
    $parser = new IngredientAmountParser;

    expect($parser->parse('2'))->toBe(['numeric' => '2', 'text' => null])
        ->and($parser->parse('1/2'))->toBe(['numeric' => '0.5', 'text' => null])
        ->and($parser->parse('1 1/2'))->toBe(['numeric' => '1.5', 'text' => null])
        ->and($parser->parse('1,5'))->toBe(['numeric' => '1.5', 'text' => null])
        ->and($parser->parse('podľa chuti'))->toBe(['numeric' => null, 'text' => 'podľa chuti'])
        ->and($parser->parse('trochu'))->toBe(['numeric' => null, 'text' => 'trochu'])
        ->and($parser->parse(''))->toBe(['numeric' => null, 'text' => null])
        ->and($parser->parse(null))->toBe(['numeric' => null, 'text' => null]);
});

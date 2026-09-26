<?php

use App\Models\AiCostRate;
use App\Services\Ai\AiCostCalculator;
use App\Support\Money;

function textRate(): AiCostRate
{
    return new AiCostRate(['input_per_million' => 100_000, 'cached_input_per_million' => 10_000, 'output_per_million' => 500_000]);
}

it('prices the illustrative text operation from the specification at 0,0013 USD', function () {
    $calc = new AiCostCalculator;

    // 3 000 input tokens at 0,10 USD/M + 2 000 billed output tokens at 0,50 USD/M = 0,0003 + 0,0010 USD.
    expect($calc->estimateText(textRate(), 3000, 2000))->toBe(1300)
        ->and(Money::microUsd(1300))->toBe('0,0013 USD')
        ->and($calc->estimateText(textRate(), 3000, 2000) * 30)->toBe(39_000);
});

it('bills cached input tokens at the cached price and never counts more cached than input tokens', function () {
    $calc = new AiCostCalculator;

    // 1 000 uncached at 0,10/M (100) + 2 000 cached at 0,01/M (20) + 500 output at 0,50/M (250).
    expect($calc->estimateText(textRate(), 3000, 500, 2000))->toBe(370)
        ->and($calc->estimateText(textRate(), 1000, 0, 5000))->toBe(10)
        ->and($calc->estimateText(new AiCostRate(['input_per_million' => 100_000, 'output_per_million' => 500_000]), 3000, 0, 1000))->toBe(300);
});

it('rounds token prices half up to whole micro-USD and ignores missing prices', function () {
    expect(AiCostCalculator::perMillion(1, 100_000))->toBe(0)
        ->and(AiCostCalculator::perMillion(5, 100_000))->toBe(1)
        ->and(AiCostCalculator::perMillion(15, 100_000))->toBe(2)
        ->and(AiCostCalculator::perMillion(1_000_000, 500_000))->toBe(500_000)
        ->and(AiCostCalculator::perMillion(1000, null))->toBe(0)
        ->and(AiCostCalculator::perMillion(-5, 100_000))->toBe(0);
});

it('prices images per delivered unit or by image output tokens when both are reported', function () {
    $calc = new AiCostCalculator;
    $flat = new AiCostRate(['per_unit' => 53_000]);
    $tokenised = new AiCostRate(['per_unit' => 53_000, 'output_per_million' => 30_000_000, 'input_per_million' => 5_000_000]);

    expect($calc->estimateImage($flat, 1))->toBe(53_000)
        ->and($calc->estimateImage($flat, 1, 500, 1200))->toBe(53_000) // rate has no token prices → flat
        ->and($calc->estimateImage($tokenised, 1, 1000, 1500))->toBe(45_000 + 5_000)
        ->and($calc->estimateImage($tokenised, 2, 0, null))->toBe(106_000);
});

it('parses and formats USD amounts without floating point drift', function () {
    expect(Money::parseUsdToMicro('0,053'))->toBe(53_000)
        ->and(Money::parseUsdToMicro('2.50'))->toBe(2_500_000)
        ->and(Money::parseUsdToMicro(' 24 '))->toBe(24_000_000)
        ->and(Money::parseUsdToMicro('0.0000001'))->toBeNull()
        ->and(Money::parseUsdToMicro('abc'))->toBeNull()
        ->and(Money::parseUsdToMicro(''))->toBeNull()
        ->and(Money::microToUsdString(53_000))->toBe('0.053')
        ->and(Money::microToUsdString(2_500_000))->toBe('2.5')
        ->and(Money::microToUsdString(0))->toBe('0')
        ->and(Money::microUsd(null))->toBe('–')
        ->and(Money::microUsd(1_234_567, 2))->toBe('1,23 USD');
});

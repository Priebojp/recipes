<?php

use App\Services\Selection\WeightedPicker;

it('picks by cumulative weight boundaries with an injected random source', function (float $random, int $expected) {
    $picker = new WeightedPicker(fn () => $random);

    expect($picker->pick([10 => 1.0, 20 => 2.0, 30 => 1.0]))->toBe($expected);
})->with([
    [0.0, 10], [0.2499, 10], [0.25, 20], [0.7499, 20], [0.75, 30], [0.999999, 30],
]);

it('orders without repetition', function () {
    $values = [0.9, 0.0, 0.5];
    $i = 0;
    $picker = new WeightedPicker(function () use (&$i, $values) {
        return $values[$i++ % count($values)];
    });

    $order = $picker->order([1 => 1.0, 2 => 1.0, 3 => 1.0]);

    expect($order)->toHaveCount(3)->and(array_unique($order))->toHaveCount(3)->and($order[0])->toBe(3);
});

it('returns null for an empty pool', function () {
    expect((new WeightedPicker(fn () => 0.5))->pick([]))->toBeNull();
});

<?php

namespace App\Services\Selection;

use Closure;

/**
 * Weighted random choice without replacement. The random source is injectable for deterministic tests.
 */
class WeightedPicker
{
    private Closure $random;

    /**
     * @param  (callable(): float)|null  $random  returns a float in [0, 1)
     */
    public function __construct(?callable $random = null)
    {
        $this->random = $random !== null
            ? Closure::fromCallable($random)
            : fn (): float => random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }

    /**
     * @param  array<int, float>  $weightsById
     */
    public function pick(array $weightsById): ?int
    {
        if ($weightsById === []) {
            return null;
        }

        $total = array_sum($weightsById);
        if ($total <= 0) {
            return array_key_first($weightsById);
        }

        $target = ($this->random)() * $total;
        $cumulative = 0.0;
        $lastId = null;

        foreach ($weightsById as $id => $weight) {
            $cumulative += $weight;
            $lastId = $id;
            if ($target < $cumulative) {
                return $id;
            }
        }

        return $lastId;
    }

    /**
     * Whole weighted random order without repetition.
     *
     * @param  array<int, float>  $weightsById
     * @return list<int>
     */
    public function order(array $weightsById): array
    {
        $order = [];
        while ($weightsById !== []) {
            $id = $this->pick($weightsById);
            $order[] = $id;
            unset($weightsById[$id]);
        }

        return $order;
    }
}

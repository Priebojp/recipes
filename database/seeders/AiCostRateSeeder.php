<?php

namespace Database\Seeders;

use App\Models\AiCostRate;
use Illuminate\Database\Seeder;

/**
 * Provider list prices from the v2 specification (official documentation read 26. 9. 2026), in micro-USD.
 * Idempotent: rows are matched by provider/model/modality/quality/size/effective_from. New prices = new rows.
 */
class AiCostRateSeeder extends Seeder
{
    public const EFFECTIVE_FROM = '2026-09-26 00:00:00';

    public function run(): void
    {
        $textSource = 'https://developers.openai.com/api/docs/models/gpt-6-luna';
        $imageSource = 'https://developers.openai.com/api/docs/guides/image-generation';

        $rows = [
            [
                'provider' => 'openai', 'model' => 'gpt-6-luna', 'modality' => AiCostRate::MODALITY_TEXT,
                'quality' => null, 'size' => null,
                'input_per_million' => 100_000, 'cached_input_per_million' => null, 'output_per_million' => 500_000, 'per_unit' => null,
                'source' => $textSource, 'note' => '0,10 USD / 1M vstupných, 0,50 USD / 1M výstupných tokenov (vrátane reasoning)',
            ],
        ];

        $imagePrices = [
            '1024x1024' => ['low' => 6_000, 'medium' => 53_000, 'high' => 211_000],
            '1536x1024' => ['low' => 5_000, 'medium' => 41_000, 'high' => 165_000],
        ];
        foreach ($imagePrices as $size => $tiers) {
            foreach ($tiers as $quality => $perUnit) {
                $rows[] = [
                    'provider' => 'openai', 'model' => 'gpt-image-2', 'modality' => AiCostRate::MODALITY_IMAGE,
                    'quality' => $quality, 'size' => $size,
                    'input_per_million' => null, 'cached_input_per_million' => null, 'output_per_million' => null, 'per_unit' => $perUnit,
                    'source' => $imageSource, 'note' => 'Odhad obrazového výstupu podľa sprievodcu; vstupy sa pripočítavajú samostatne',
                ];
            }
        }

        foreach ($rows as $row) {
            AiCostRate::query()->firstOrCreate(
                [
                    'provider' => $row['provider'], 'model' => $row['model'], 'modality' => $row['modality'],
                    'quality' => $row['quality'], 'size' => $row['size'], 'effective_from' => self::EFFECTIVE_FROM,
                ],
                [...$row, 'currency' => 'USD'],
            );
        }
    }
}

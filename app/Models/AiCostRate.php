<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One version of a provider list price. New prices are new rows; old jobs keep the rate they were priced with.
 * All amounts are integer micro-USD (1 USD = 1 000 000 micro-USD).
 *
 * @property int $id
 * @property string $provider
 * @property string $model
 * @property string $modality
 * @property string|null $quality
 * @property string|null $size
 * @property string $currency
 * @property int|null $input_per_million
 * @property int|null $cached_input_per_million
 * @property int|null $output_per_million
 * @property int|null $per_unit
 * @property Carbon $effective_from
 * @property string|null $source
 * @property string|null $note
 * @property int|null $created_by
 */
#[Fillable([
    'provider', 'model', 'modality', 'quality', 'size', 'currency', 'input_per_million', 'cached_input_per_million',
    'output_per_million', 'per_unit', 'effective_from', 'source', 'note', 'created_by',
])]
class AiCostRate extends Model
{
    public const MODALITY_TEXT = 'text';

    public const MODALITY_IMAGE = 'image';

    protected function casts(): array
    {
        return [
            'input_per_million' => 'integer',
            'cached_input_per_million' => 'integer',
            'output_per_million' => 'integer',
            'per_unit' => 'integer',
            'effective_from' => 'datetime',
        ];
    }

    public function label(): string
    {
        $parts = [$this->model, $this->modality];
        if ($this->quality) {
            $parts[] = $this->quality;
        }
        if ($this->size) {
            $parts[] = $this->size;
        }

        return implode(' · ', $parts);
    }
}

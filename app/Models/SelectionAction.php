<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $session_id
 * @property int $recipe_id
 * @property string $action
 * @property int $sequence
 */
#[Fillable(['session_id', 'recipe_id', 'action', 'sequence', 'created_at'])]
class SelectionAction extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}

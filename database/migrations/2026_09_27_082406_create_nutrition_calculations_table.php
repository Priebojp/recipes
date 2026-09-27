<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.1 stage 10 – nutrition values of a recipe. Pure arithmetic over the stage 9 mappings: one calculation per
 * run, bound to the recipe revision it was computed for, with every component's source and grams stored so the
 * result stays explainable after the recipe, the mapping or the food database changes. No AI, no usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_revision_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('calculation_version'); // NutritionCalculator::VERSION at the time
            $table->unsignedInteger('servings')->nullable(); // recipe base servings the per-serving values divide by
            $table->decimal('final_weight_g', 10, 2)->nullable(); // entered by a person; per 100 g only with it
            $table->json('totals'); // per nutrient: value or null (unknown, never 0)
            $table->json('per_serving')->nullable();
            $table->json('per_100g')->nullable();
            $table->string('completeness', 10); // complete|partial
            $table->json('components'); // every ingredient line with source, grams, origin and share
            $table->json('missing'); // lines without a value or grams
            $table->json('assumptions'); // e.g. "olej započítaný na polovicu"
            $table->timestamp('stale_at')->nullable(); // set when a newer revision changed ingredients or servings
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['recipe_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_calculations');
    }
};

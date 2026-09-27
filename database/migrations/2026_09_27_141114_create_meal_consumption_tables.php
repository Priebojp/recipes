<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.1 stage 12 – the private "Zjedol som" diary. A consumption is an event of eating (not of cooking: cooking
 * events stay the input of the repetition generator) and belongs to one signed-in person, never to the household.
 * Every entry carries an immutable snapshot of the calculation it was saved with; a correction adds a new
 * revision of the snapshot and the old one stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // owner of the diary – only they read or edit it
            $table->foreignId('household_id')->constrained()->cascadeOnDelete(); // context the entry was made in
            $table->timestamp('eaten_at'); // UTC instant
            $table->string('timezone', 64); // zone the person entered it in
            $table->date('eaten_on'); // local calendar day of eaten_at in that zone – the diary groups by it
            $table->string('source', 20); // recipe|analysis|manual
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipe_revision_id')->nullable()->constrained('recipe_revisions')->nullOnDelete();
            $table->foreignId('nutrition_calculation_id')->nullable()->constrained('nutrition_calculations')->nullOnDelete();
            $table->foreignId('meal_analysis_id')->nullable()->constrained('meal_analyses')->nullOnDelete();
            $table->string('title_snapshot', 200);
            $table->string('portion_mode', 20); // fraction|grams|per_component
            $table->decimal('portion_fraction', 6, 4)->nullable(); // of one unit (a serving of a recipe, the whole plate of a photo)
            $table->decimal('grams', 8, 2)->nullable(); // grams eaten, in grams mode
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'eaten_on']);
            $table->index(['user_id', 'eaten_at']);
        });

        Schema::create('consumption_nutrition_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_consumption_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision'); // 1 = as saved; a correction adds the next revision, older ones stay
            $table->unsignedSmallInteger('calculation_version');
            $table->string('portion_mode', 20);
            $table->decimal('portion_fraction', 6, 4)->nullable();
            $table->decimal('grams', 8, 2)->nullable();
            $table->json('component_shares')->nullable(); // per_component: component index => share eaten (0..1)
            $table->json('basis'); // what the numbers were computed from, frozen: source, unit totals, unit grams, servings, versions
            $table->json('totals')->nullable(); // eaten values; null = saved without calories
            $table->string('completeness', 20)->nullable(); // complete|partial; null without calories
            $table->json('components'); // per component: unit grams, share eaten, grams eaten, source, values per 100 g
            $table->json('missing');
            $table->json('assumptions');
            $table->string('manual_origin', 20)->nullable(); // manual values: label|estimate – never silent numbers
            $table->timestamp('created_at');
            $table->unique(['meal_consumption_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumption_nutrition_snapshots');
        Schema::dropIfExists('meal_consumptions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.1 stage 9 – food database and ingredient mapping. One verified source (USDA FoodData Central) with stored
 * snapshots and licence, a hand-curated SK/CZ dictionary of common ingredients, per-food unit conversions and
 * the link from a recipe's ingredient line to a food record. No AI, no nutrition calculation yet (stage 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_source_records', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30); // usda_fdc
            $table->string('external_id', 50);
            $table->string('license', 40); // CC0-1.0 for USDA
            $table->string('name', 255); // provider's description (English for USDA)
            $table->string('name_sk', 200)->nullable(); // curated Slovak label
            $table->string('preparation_state', 20); // raw|cooked|dry|canned|unknown
            $table->string('basis', 10); // 100g|100ml|serving
            $table->decimal('energy_kcal', 8, 2)->nullable(); // null = unknown, never 0 as a substitute
            $table->decimal('energy_kj', 8, 2)->nullable();
            $table->decimal('protein_g', 8, 2)->nullable();
            $table->decimal('carbohydrate_g', 8, 2)->nullable();
            $table->string('carbohydrate_method', 30)->nullable(); // by_difference|by_summation
            $table->decimal('fat_g', 8, 2)->nullable();
            $table->decimal('fiber_g', 8, 2)->nullable();
            $table->json('source_snapshot')->nullable(); // provider's original payload
            $table->timestamp('fetched_at')->nullable();
            $table->boolean('is_curated')->default(false);
            $table->string('sync_warning', 255)->nullable(); // e.g. description no longer matches the seeded expectation
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
        });

        Schema::create('food_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_source_record_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 100); // as written: "ryža"
            $table->string('normalized', 100); // lower-case, single spaces – same rule as the shopping list merge key
            $table->string('locale', 5); // sk|cs
            $table->string('preparation_state', 20); // the state this alias means (raw rice vs. cooked rice are two aliases)
            $table->foreignId('curated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('curated_at')->nullable();
            $table->timestamps();
            $table->unique(['normalized', 'food_source_record_id']);
            $table->index('normalized');
        });

        Schema::create('food_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_source_record_id')->constrained()->cascadeOnDelete();
            $table->string('unit', 20); // ks|PL|ČL|šálka|ml (ml = density: grams of one millilitre)
            $table->decimal('grams', 8, 2);
            $table->string('source', 20); // usda_portion|manual|label
            $table->string('note', 200)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['food_source_record_id', 'unit']);
        });

        Schema::create('ingredient_food_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_source_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('preparation_state', 20)->nullable();
            $table->decimal('grams', 10, 2)->nullable();
            $table->string('grams_origin', 20)->nullable(); // unit_conversion|user_entered|estimated
            $table->foreignId('conversion_id')->nullable()->constrained('food_unit_conversions')->nullOnDelete();
            $table->string('status', 20); // suggested|confirmed|rejected|unresolved
            $table->string('unresolved_reason', 200)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique('ingredient_line_id');
            $table->index(['status', 'food_source_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_food_mappings');
        Schema::dropIfExists('food_unit_conversions');
        Schema::dropIfExists('food_aliases');
        Schema::dropIfExists('food_source_records');
    }
};

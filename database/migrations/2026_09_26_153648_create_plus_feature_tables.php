<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 stage 6 – Plus features: saved selection presets, and shopping lists generated from the weekly plan.
 * The weekly menu proposal itself creates ordinary meal_plans rows and needs no table of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('person_ids');
            $table->string('meal_type', 20)->nullable();
            $table->json('filters');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['household_id', 'name']);
        });

        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->date('week_start_date');
            $table->timestamp('generated_at')->nullable();
            $table->unsignedSmallInteger('plan_count')->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['household_id', 'week_start_date']);
        });

        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('merge_key', 260); // normalised name + unit; keeps the checked state across regenerations
            $table->string('name', 200);
            $table->string('unit', 50)->nullable();
            $table->decimal('numeric_amount', 12, 3)->nullable();
            $table->json('text_amounts')->nullable(); // ["podľa chuti (Praženica)"]
            $table->json('sources')->nullable(); // [{recipe_id, title, plan_id, scaled}]
            $table->boolean('manual')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index(['shopping_list_id', 'position']);
            $table->unique(['shopping_list_id', 'merge_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
        Schema::dropIfExists('selection_presets');
    }
};

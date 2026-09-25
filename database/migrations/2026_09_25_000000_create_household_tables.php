<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('timezone')->default('Europe/Bratislava');
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('default_person_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('household_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->timestamps();
            $table->unique(['household_id', 'user_id']);
        });

        Schema::create('household_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable();
            $table->string('email')->nullable();
            $table->string('role', 20)->default('member');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable();
            $table->timestamps();
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('color', 20)->nullable();
            $table->string('kind', 10)->default('member'); // member|guest
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'archived_at']);
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('base_servings')->nullable();
            $table->unsignedSmallInteger('prep_minutes')->nullable();
            $table->unsignedSmallInteger('cook_minutes')->nullable();
            $table->string('side_requirement', 20)->default('unknown'); // unknown|complete|needs_side
            $table->string('included_side', 200)->nullable();
            $table->string('serving_mode', 20)->default('auto'); // auto|plate|bowl|pot|casserole|baking_dish
            $table->text('raw_text')->nullable();
            $table->string('source', 500)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('cover_media_id')->nullable();
            $table->unsignedBigInteger('active_revision_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'archived_at']);
        });

        Schema::create('recipe_meal_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('meal_type', 20); // breakfast|lunch|dinner
            $table->unique(['recipe_id', 'meal_type']);
        });

        Schema::create('ingredient_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 200);
            $table->decimal('numeric_amount', 10, 3)->nullable();
            $table->string('text_amount', 100)->nullable();
            $table->string('unit', 50)->nullable();
            $table->string('note', 200)->nullable();
            $table->string('source_text', 500)->nullable();
            $table->timestamps();
            $table->index(['recipe_id', 'position']);
        });

        Schema::create('recipe_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('text');
            $table->timestamps();
            $table->index(['recipe_id', 'position']);
        });

        Schema::create('recipe_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->string('source', 20)->default('manual'); // manual|ai
            $table->unsignedBigInteger('previous_revision_id')->nullable();
            $table->timestamp('created_at');
            $table->index('recipe_id');
        });

        Schema::create('person_recipe_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('preference', 20); // favorite|eats|dislikes
            $table->timestamps();
            $table->unique(['person_id', 'recipe_id']);
            $table->index(['recipe_id', 'preference']);
        });

        Schema::create('person_recipe_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['person_id', 'recipe_id']);
        });

        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 10); // date|week|someday
            $table->date('scheduled_date')->nullable();
            $table->date('week_start_date')->nullable();
            $table->string('meal_type', 20)->nullable();
            $table->unsignedSmallInteger('servings')->nullable();
            $table->string('status', 20)->default('planned'); // planned|cooked|cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['household_id', 'status', 'scheduled_date']);
            $table->index(['household_id', 'status', 'week_start_date']);
            $table->index(['recipe_id', 'status']);
        });

        Schema::create('meal_plan_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unique(['meal_plan_id', 'person_id']);
        });

        Schema::create('cooking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meal_plan_id')->nullable()->constrained()->nullOnDelete();
            // Set to meal_plan_id while the event is active; NULL once voided. Enforces one active event per plan.
            $table->unsignedBigInteger('active_plan_key')->nullable()->unique();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->date('cooked_on');
            $table->unsignedSmallInteger('servings')->nullable();
            $table->text('note')->nullable();
            $table->string('recipe_title_snapshot', 200);
            $table->unsignedBigInteger('recipe_revision_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['household_id', 'cooked_on']);
            $table->index(['recipe_id', 'voided_at', 'cooked_on']);
        });

        Schema::create('cooking_event_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooking_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->unique(['cooking_event_id', 'person_id']);
        });

        Schema::create('selection_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('inputs');
            $table->unsignedInteger('config_version');
            $table->json('candidates');
            $table->json('state');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['household_id', 'expires_at']);
        });

        Schema::create('selection_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('selection_sessions')->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20); // shown|skipped|accepted|undo
            $table->unsignedInteger('sequence');
            $table->timestamp('created_at');
            $table->index(['session_id', 'sequence']);
        });

        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // text|image
            $table->unsignedBigInteger('input_revision_id')->nullable();
            $table->string('status', 20)->default('queued'); // queued|running|succeeded|failed
            $table->string('request_key', 64)->unique();
            $table->string('provider', 50)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('provider_job_id', 200)->nullable();
            $table->string('prompt_version', 20);
            $table->json('input')->nullable();
            $table->text('prompt')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('result_media_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['recipe_id', 'kind', 'status']);
            $table->index(['household_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'ai_jobs', 'selection_actions', 'selection_sessions', 'cooking_event_people', 'cooking_events',
            'meal_plan_people', 'meal_plans', 'person_recipe_exclusions', 'person_recipe_preferences',
            'recipe_revisions', 'recipe_steps', 'ingredient_lines', 'recipe_meal_types', 'recipes',
            'people', 'household_invitations', 'household_memberships', 'households',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

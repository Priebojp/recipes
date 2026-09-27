<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.1 stage 11 – recognition of a meal from a photo. An AI job may now belong to no recipe (the analysis owns it)
 * and may be a follow-up of another job (clarifications in the same session, not charged again). The analysis and
 * its components are personal records of the person who uploaded the photo, not of the household.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->foreignId('recipe_id')->nullable()->change();
            $table->foreignId('parent_ai_job_id')->nullable()->after('recipe_id')->constrained('ai_jobs')->nullOnDelete();
        });

        Schema::create('meal_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // owner – only they read or edit it
            $table->foreignId('ai_job_id')->nullable()->constrained('ai_jobs')->nullOnDelete(); // the root (charged) job
            $table->string('status', 20); // uploaded|analyzing|needs_review|confirmed|unusable|discarded
            $table->string('note', 500)->nullable(); // the person's hint sent with the photo
            $table->json('ai_result')->nullable(); // validated structured output of the last delivered job
            $table->string('ai_status', 30)->nullable(); // recognized|needs_clarification|not_food|unusable
            $table->string('dish_name', 200)->nullable();
            $table->json('questions')->nullable();
            $table->json('limitations')->nullable();
            $table->unsignedTinyInteger('clarification_count')->default(0);
            $table->json('nutrition')->nullable(); // database calculation stored at confirmation (never AI numbers)
            $table->timestamp('photo_retain_until')->nullable(); // working photo TTL; null = kept with the record or already removed
            $table->timestamp('photo_removed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // draft TTL; null once confirmed
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
            $table->index('photo_retain_until');
        });

        Schema::create('meal_analysis_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_analysis_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('label', 120);
            $table->json('alternatives')->nullable();
            $table->string('preparation_state', 20)->nullable(); // raw|cooked|dry|canned|unknown
            $table->decimal('estimated_grams', 8, 2)->nullable(); // the AI's guess, kept for the audit trail
            $table->decimal('grams', 8, 2)->nullable(); // what counts: the guess until a person confirms or weighs it
            $table->string('grams_origin', 20)->nullable(); // estimated|confirmed|measured
            $table->string('portion_basis', 200)->nullable();
            $table->string('visible_evidence', 300)->nullable();
            $table->json('assumptions')->nullable();
            $table->foreignId('food_source_record_id')->nullable()->constrained()->nullOnDelete(); // verified by the server, never from AI
            $table->string('mapping_status', 20); // suggested|confirmed|rejected|unresolved
            $table->boolean('is_unknown')->default(false); // "neznáma zložka": counted as missing, never as zero
            $table->boolean('included')->default(true);
            $table->timestamps();
            $table->index(['meal_analysis_id', 'position']);
        });

        Schema::table('users', function (Blueprint $table) {
            // One-time product notice "the photo is sent to the AI provider" (not a GDPR consent for other purposes).
            $table->timestamp('meal_photo_notice_accepted_at')->nullable()->after('platform_admin_granted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('meal_photo_notice_accepted_at'));
        Schema::dropIfExists('meal_analysis_items');
        Schema::dropIfExists('meal_analyses');
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_ai_job_id');
            $table->foreignId('recipe_id')->nullable(false)->change();
        });
    }
};

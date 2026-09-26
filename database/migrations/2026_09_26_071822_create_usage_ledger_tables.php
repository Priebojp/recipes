<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A batch of AI uses a household may spend: trial, monthly (subscription), purchased add-on or a compensation.
        // Counters are a cache of the append-only ledger below and can be rebuilt from usage_reservations.
        Schema::create('usage_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30); // text|image_standard
            $table->string('source', 30); // trial|subscription|addon|compensation
            $table->string('source_key', 191)->unique(); // idempotency: the same origin never creates a second grant
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('consumed_quantity')->default(0);
            $table->unsignedInteger('revoked_quantity')->default(0);
            $table->timestamp('valid_from');
            $table->timestamp('expires_at')->nullable(); // null = purchased, no calendar expiry while the service runs
            $table->timestamp('revoked_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['household_id', 'kind', 'expires_at']);
        });

        // One reservation per AI job: the row is created with the job under a lock and settled exactly once.
        Schema::create('usage_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('usage_grant_id')->constrained('usage_grants')->cascadeOnDelete();
            $table->foreignId('ai_job_id')->nullable()->unique()->constrained('ai_jobs')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('state', 20); // reserved|consumed|released
            $table->timestamp('reserved_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'reserved_at']);
        });

        // Append-only movements; corrections are new compensating rows, never updates.
        Schema::create('usage_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('usage_grant_id')->constrained('usage_grants')->cascadeOnDelete();
            $table->foreignId('usage_reservation_id')->nullable()->constrained('usage_reservations')->nullOnDelete();
            $table->integer('movement'); // signed change of the available quantity
            $table->string('reason', 30); // granted|reserved|released|consumed|revoked
            $table->string('source_key', 191)->unique(); // idempotency of every movement
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['usage_grant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_ledger_entries');
        Schema::dropIfExists('usage_reservations');
        Schema::dropIfExists('usage_grants');
    }
};

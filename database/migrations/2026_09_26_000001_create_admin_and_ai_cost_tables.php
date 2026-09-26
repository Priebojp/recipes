<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Runtime settings editable in /admin (AI model, reasoning effort, image quality, kill switch, limits).
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Who / what / when / target / reason. Financial and settings changes are never deleted from the UI.
        Schema::create('admin_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('target_type', 100)->nullable();
            $table->string('target_id', 100)->nullable();
            $table->text('reason')->nullable();
            $table->json('changes')->nullable(); // redacted before/after
            $table->timestamp('created_at');
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });

        // Versioned provider list prices. Money is stored as integer micro-USD (1 USD = 1 000 000), never floats.
        Schema::create('ai_cost_rates', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->string('modality', 10); // text|image
            $table->string('quality', 10)->nullable(); // images: low|medium|high
            $table->string('size', 20)->nullable(); // images: 1024x1024, 1536x1024, ...
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('input_per_million')->nullable(); // micro-USD per 1M input tokens
            $table->unsignedBigInteger('cached_input_per_million')->nullable();
            $table->unsignedBigInteger('output_per_million')->nullable(); // micro-USD per 1M output tokens (incl. reasoning)
            $table->unsignedBigInteger('per_unit')->nullable(); // micro-USD per generated image
            $table->timestamp('effective_from');
            $table->string('source', 500)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['provider', 'model', 'modality', 'effective_from']);
        });

        // Measured usage of every AI job: the provider's usage report is authoritative, the cost is an estimate.
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->json('profile')->nullable()->after('model'); // reasoning effort / quality / size used for this job
            $table->unsignedInteger('input_tokens')->nullable()->after('error');
            $table->unsignedInteger('cached_input_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('output_tokens')->nullable()->after('cached_input_tokens');
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('output_tokens');
            $table->unsignedInteger('image_output_tokens')->nullable()->after('reasoning_tokens');
            $table->unsignedBigInteger('estimated_cost_micro_usd')->nullable()->after('image_output_tokens');
            $table->foreignId('cost_rate_id')->nullable()->after('estimated_cost_micro_usd')->constrained('ai_cost_rates')->nullOnDelete();
            $table->unsignedInteger('duration_ms')->nullable()->after('cost_rate_id');
            $table->index(['kind', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status', 'created_at']);
            $table->dropConstrainedForeignId('cost_rate_id');
            $table->dropColumn([
                'profile', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens',
                'image_output_tokens', 'estimated_cost_micro_usd', 'duration_ms',
            ]);
        });

        Schema::dropIfExists('ai_cost_rates');
        Schema::dropIfExists('admin_audits');
        Schema::dropIfExists('app_settings');
    }
};

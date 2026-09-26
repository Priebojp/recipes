<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Versioned legal documents (terms, privacy, cookies, withdrawal). A published version is immutable; a change
        // is a new version. Drafts may contain [PLACEHOLDERS]; publishing refuses them.
        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40); // terms | privacy | cookies | withdrawal
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->text('content'); // Markdown
            $table->string('checksum', 64);
            $table->string('change_summary', 500)->nullable();
            $table->string('state', 20)->default('draft'); // draft | published | archived
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_note', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'version']);
            $table->index(['type', 'state']);
        });

        // Who accepted which document version, when and in what act (registration, order, withdrawal form).
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_document_version_id')->constrained('legal_document_versions')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('action', 40); // registration | checkout | withdrawal
            $table->string('checksum', 64);
            $table->json('acknowledgements')->nullable(); // e.g. {"early_performance_requested": true}
            $table->string('email', 200)->nullable(); // for acts without an account (withdrawal after losing access)
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->index(['user_id', 'action']);
            $table->index(['order_id']);
        });

        // Register of technologies and services: the cookies page and the consent banner are generated from it.
        Schema::create('consent_services', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name', 120);
            $table->string('provider', 120);
            $table->string('category', 20); // necessary | analytics | marketing
            $table->string('purpose', 500);
            $table->string('retention', 200)->nullable();
            $table->string('location', 200)->nullable(); // where data is processed / transfer mechanism
            $table->json('storage')->nullable(); // [{name, kind, domain, duration, purpose}]
            $table->json('loader')->nullable(); // {type: script|ga4, src, id, ...} – only optional services
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('consent_version')->default(1); // bumped when purpose/storage changes
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['category', 'enabled']);
        });

        // Consent receipts: pseudonymous visitor id, categories, policy version, action, time. No IP addresses.
        Schema::create('consent_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('visitor_id')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('policy_version', 64);
            $table->foreignId('cookies_document_version_id')->nullable()->constrained('legal_document_versions')->nullOnDelete();
            $table->string('action', 20); // accept_all | reject_all | custom | withdraw
            $table->json('categories'); // {"analytics": bool, "marketing": bool}
            $table->timestamp('created_at');
            $table->index(['created_at']);
        });

        // Data-subject requests (export, erasure, rectification) with a deadline and completion evidence.
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->string('subject_email', 200)->nullable();
            $table->string('kind', 30); // export | erasure | rectification | other
            $table->string('status', 30)->default('received'); // received | in_progress | completed | rejected
            $table->text('message')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('deadline_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_evidence')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'deadline_at']);
            $table->index(['household_id']);
        });

        // Online withdrawal from the contract: separate from cancelling renewal, usable without an account.
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // shown to the customer in the receipt
            $table->string('email', 200);
            $table->string('order_reference', 100)->nullable(); // what the customer typed
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message')->nullable();
            $table->string('status', 30)->default('received'); // received | refunded | rejected
            $table->foreignId('refund_case_id')->nullable()->constrained('refund_cases')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamp('receipt_sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'received_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('terms_version_id')->nullable()->after('failure_reason')->constrained('legal_document_versions')->nullOnDelete();
            $table->timestamp('confirmation_sent_at')->nullable()->after('paid_at');
        });

        // An erased household keeps its financial records (orders, refunds, ledger) but no content or names.
        Schema::table('households', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable()->after('blocked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('households', fn (Blueprint $table) => $table->dropColumn('erased_at'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('terms_version_id');
            $table->dropColumn('confirmation_sent_at');
        });
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('consent_receipts');
        Schema::dropIfExists('consent_services');
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_document_versions');
    }
};

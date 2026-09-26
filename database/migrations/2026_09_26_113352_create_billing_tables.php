<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cashier billable: one Stripe customer per household, managed by the owner. Columns mirror Cashier's
        // customer migration; the household (not a user) is the customer, so Cashier's users migration is not used.
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('payer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('billing_name', 200)->nullable();
            $table->string('billing_email', 200)->nullable();
            $table->json('billing_address')->nullable();
            $table->timestamps();
        });

        // Cashier subscription tables keyed by the billing account.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_account_id')->constrained('billing_accounts')->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['billing_account_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'stripe_price']);
        });

        // Versioned catalogue: a price change is a new row; orders and entitlements keep the version they bought.
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50); // plus_monthly | plus_yearly
            $table->string('product_code', 50); // plus
            $table->unsignedInteger('version');
            $table->string('name', 100);
            $table->string('interval', 10); // month | year
            $table->unsignedInteger('final_price_cents');
            $table->string('currency', 3)->default('EUR');
            $table->string('stripe_price_id', 100)->nullable();
            $table->unsignedInteger('text_uses_per_period');
            $table->unsignedInteger('image_uses_per_period');
            $table->json('features')->nullable();
            $table->string('state', 20)->default('draft'); // draft | active | retired
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['code', 'version']);
            $table->index(['code', 'state']);
        });

        Schema::create('addon_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50); // images_20_standard | text_100
            $table->unsignedInteger('version');
            $table->string('name', 100);
            $table->string('unit_kind', 30); // text | image_standard
            $table->unsignedInteger('unit_count');
            $table->unsignedInteger('final_price_cents');
            $table->string('currency', 3)->default('EUR');
            $table->string('stripe_price_id', 100)->nullable();
            $table->string('state', 20)->default('draft');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['code', 'version']);
            $table->index(['code', 'state']);
        });

        // Immutable record of what was bought at which price; Stripe IDs link it to the payment and documents.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_account_id')->constrained('billing_accounts')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20); // subscription | addon
            $table->foreignId('plan_version_id')->nullable()->constrained('plan_versions');
            $table->foreignId('addon_version_id')->nullable()->constrained('addon_versions');
            $table->json('product_snapshot');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('tax_cents')->default(0);
            $table->string('currency', 3);
            $table->string('status', 30)->default('pending'); // pending | paid | failed | canceled | expired | refunded | partially_refunded
            $table->string('stripe_checkout_session_id', 200)->nullable()->unique();
            $table->string('stripe_payment_intent_id', 200)->nullable()->index();
            $table->string('stripe_invoice_id', 200)->nullable()->index();
            $table->string('stripe_subscription_id', 200)->nullable()->index();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['household_id', 'status']);
        });

        // A paid Plus period. Grants and features derive from it; refunds revoke it, cancellations do not.
        Schema::create('paid_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('plan_version_id')->constrained('plan_versions');
            $table->string('source_key', 191)->unique(); // invoice:{id}
            $table->string('stripe_subscription_id', 200)->nullable()->index();
            $table->string('stripe_invoice_id', 200)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['household_id', 'ends_at']);
        });

        // Webhook inbox: every Stripe event is stored before it is acknowledged, processed once, retried on failure.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('type', 100);
            $table->string('object_id', 200)->nullable();
            $table->boolean('livemode')->default(false);
            $table->string('state', 20)->default('received'); // received | processed | ignored | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload'); // data.object only – no full request retention
            $table->timestamp('event_created_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        // Refund / withdrawal / dispute cases: money moves in Stripe, uses and entitlements are revoked here.
        Schema::create('refund_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30); // withdrawal | complaint | goodwill | dispute | external
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->text('reason');
            $table->json('units_revoked')->nullable(); // {text: n, image_standard: n}
            $table->boolean('revoke_entitlement')->default(false);
            $table->string('stripe_refund_id', 200)->nullable()->index();
            $table->string('stripe_payment_intent_id', 200)->nullable();
            $table->string('stripe_dispute_id', 200)->nullable()->index();
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 30)->default('requested'); // requested | processed | failed | needs_review | disputed
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['refund_cases', 'stripe_events', 'paid_entitlements', 'orders', 'addon_versions', 'plan_versions', 'subscription_items', 'subscriptions', 'billing_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

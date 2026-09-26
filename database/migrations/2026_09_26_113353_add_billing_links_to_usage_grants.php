<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which purchase / paid period a grant belongs to, so a refund revokes only its own uses.
        Schema::table('usage_grants', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('source_key')->constrained('orders')->nullOnDelete();
            $table->foreignId('paid_entitlement_id')->nullable()->after('order_id')->constrained('paid_entitlements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usage_grants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_entitlement_id');
            $table->dropConstrainedForeignId('order_id');
        });
    }
};

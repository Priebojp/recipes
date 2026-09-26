<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abuse protection (v2 stage 4): a blocked household cannot start AI jobs or buy anything. Nothing is deleted,
        // recipes stay readable and editable; unblocking is a single audited action.
        Schema::table('households', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('default_person_ids');
            $table->string('blocked_reason', 500)->nullable()->after('blocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'blocked_reason']);
        });
    }
};

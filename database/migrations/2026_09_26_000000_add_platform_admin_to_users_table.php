<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Platform administrator (a different role than a household owner). Never mass assignable.
            $table->boolean('is_platform_admin')->default(false)->after('remember_token');
            $table->timestamp('platform_admin_granted_at')->nullable()->after('is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_platform_admin', 'platform_admin_granted_at']);
        });
    }
};

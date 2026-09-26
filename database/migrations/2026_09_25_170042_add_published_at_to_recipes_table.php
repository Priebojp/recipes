<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('archived_at');
            $table->index(['published_at', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex(['published_at', 'archived_at']);
            $table->dropColumn('published_at');
        });
    }
};

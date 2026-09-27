<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.1 stage 13 – the offer may include Economy images and photo analyses. A plan version says which image profile
 * its monthly image uses pay for (v2 plans implicitly sold Standard) and how many photo analyses a period includes.
 * Existing versions keep Standard and zero analyses: nothing bought changes, nothing new is granted by a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_versions', function (Blueprint $table) {
            $table->string('image_profile_code', 40)->default('image_standard_v1')->after('image_uses_per_period');
            $table->unsignedInteger('meal_analysis_uses_per_period')->default(0)->after('image_profile_code');
        });
    }

    public function down(): void
    {
        Schema::table('plan_versions', function (Blueprint $table) {
            $table->dropColumn(['image_profile_code', 'meal_analysis_uses_per_period']);
        });
    }
};

<?php
// database/migrations/2025_09_15_000001_add_is_active_to_plans.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('period');
        });
    }

    public function down(): void {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};

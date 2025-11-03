<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('nfc_tags_s')) return;
        Schema::table('nfc_tags_s', function (Blueprint $t) {
            if (!Schema::hasColumn('nfc_tags_s','qr_code_id')) {
                $t->unsignedBigInteger('qr_code_id')->nullable()->after('ctr_seed');
                $t->index(['tenant_id','qr_code_id'], 'idx_tenant_qr_code');
            }
        });
    }
    public function down(): void {
        if (!Schema::hasTable('nfc_tags_s')) return;
        Schema::table('nfc_tags_s', function (Blueprint $t) {
            try { $t->dropIndex('idx_tenant_qr_code'); } catch (\Throwable $e) {}
            if (Schema::hasColumn('nfc_tags_s','qr_code_id')) $t->dropColumn('qr_code_id');
        });
    }
};

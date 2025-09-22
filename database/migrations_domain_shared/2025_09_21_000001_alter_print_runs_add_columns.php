<?php 

// database/migrations/2025_09_21_000001_alter_print_runs_add_columns.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::connection('domain_shared')->hasTable('print_runs_s')) return;

        Schema::connection('domain_shared')->table('print_runs_s', function (Blueprint $table) {
            // channel
            if (!Schema::connection('domain_shared')->hasColumn('print_runs_s', 'channel_code') &&
                !Schema::connection('domain_shared')->hasColumn('print_runs_s', 'channel')) {
                $table->string('channel_code', 16)->nullable()->after('tenant_id');
            }
            // batch
            if (!Schema::connection('domain_shared')->hasColumn('print_runs_s', 'batch_code') &&
                !Schema::connection('domain_shared')->hasColumn('print_runs_s', 'batch')) {
                $table->string('batch_code', 64)->nullable()->after('channel_code');
            }
            // vendor
            if (!Schema::connection('domain_shared')->hasColumn('print_runs_s', 'vendor_name') &&
                !Schema::connection('domain_shared')->hasColumn('print_runs_s', 'vendor')) {
                $table->string('vendor_name', 128)->nullable()->after('batch_code');
            }
        });
    }

    public function down(): void
    {
        // no-op (don’t drop columns on down)
    }
};

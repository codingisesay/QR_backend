<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $c = config('database.connections.domain_shared') ? 'domain_shared' : config('database.default');
        if (!Schema::connection($c)->hasTable('device_qr_links_s')) return;

        // Drop UNIQUE(tentant_id, device_id) to allow many QR per one device
        // Keep UNIQUE(tenant_id, qr_code_id) to ensure a QR maps to only one device
        Schema::connection($c)->table('device_qr_links_s', function (Blueprint $t) use ($c) {
            // Determine index name safely
            $uniqueName = null;
            // Common Laravel default name
            $candidates = [
                'device_qr_links_s_tenant_id_device_id_unique',
                'uq_device_qr_links_tenant_device',
                'uniq_device_qr_links_s_tenant_device',
            ];
            // Probe information_schema for MySQL / MariaDB
            try {
                $db = config("database.connections.$c.database");
                $rows = DB::connection($c)->select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE='UNIQUE'", [$db, 'device_qr_links_s']);
                foreach ($rows as $r) {
                    $name = $r->CONSTRAINT_NAME;
                    if (stripos($name, 'device') !== false && stripos($name, 'tenant') !== false) {
                        $uniqueName = $name; break;
                    }
                }
            } catch (\Throwable $e) { /* ignore */ }

            // If still unknown, fallback to first candidate
            if (!$uniqueName) $uniqueName = $candidates[0];

            try { $t->dropUnique($uniqueName); } catch (\Throwable $e) { /* ignore if already dropped */ }

            // Add a non-unique index for fast lookup
            try { $t->index(['tenant_id','device_id'], 'idx_dql_tenant_device'); } catch (\Throwable $e) { /* ignore */ }
        });
    }

    public function down(): void
    {
        // No-op: you generally don't want to re-enforce uniqueness once production data allows many-to-one.
    }
};

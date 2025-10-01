<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Use the shared (tenant-wide) connection.
     * Change this if your connection is named differently.
     */
    protected $connection = 'domain_shared';

    public function up(): void
    {
        // device_qr_links_s
        Schema::connection($this->connection)->table('device_qr_links_s', function (Blueprint $table) {
            if (!Schema::connection($this->connection)->hasColumn('device_qr_links_s', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('device_id');
            }
            if (!Schema::connection($this->connection)->hasColumn('device_qr_links_s', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });

        // device_assembly_links_s
        Schema::connection($this->connection)->table('device_assembly_links_s', function (Blueprint $table) {
            if (!Schema::connection($this->connection)->hasColumn('device_assembly_links_s', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('parent_device_id');
            }
            if (!Schema::connection($this->connection)->hasColumn('device_assembly_links_s', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    public function down(): void
    {
        // device_qr_links_s
        Schema::connection($this->connection)->table('device_qr_links_s', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('device_qr_links_s', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
            if (Schema::connection($this->connection)->hasColumn('device_qr_links_s', 'created_at')) {
                $table->dropColumn('created_at');
            }
        });

        // device_assembly_links_s
        Schema::connection($this->connection)->table('device_assembly_links_s', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('device_assembly_links_s', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
            if (Schema::connection($this->connection)->hasColumn('device_assembly_links_s', 'created_at')) {
                $table->dropColumn('created_at');
            }
        });
    }
};

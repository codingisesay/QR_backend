<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function coreConnection(): string
    {
        if (config('database.connections.core')) return 'core';
        if (config('database.connections.saas_core')) return 'saas_core';
        return config('database.default', 'mysql');
    }

    public function up(): void
    {
        $conn = $this->coreConnection();

        Schema::connection($conn)->create('tenant_app_devices_s', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_app_id')->index();
            $table->string('install_id', 128);             // per-install GUID from the app
            $table->binary('device_hash');                 // 32B HMAC over model/platform/install_id
            $table->json('attestation_json')->nullable();  // Play Integrity / DeviceCheck blobs
            $table->enum('status', ['active','blocked'])->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_app_id','install_id'], 'uq_app_install');
        });

        try {
            Schema::connection($conn)->table('tenant_app_devices_s', function (Blueprint $table) {
                $table->foreign('tenant_app_id')->references('id')->on('tenant_apps_s')->cascadeOnDelete();
            });
        } catch (\Throwable $e) {
            // ignore FK failure if needed
        }
    }

    public function down(): void
    {
        $conn = $this->coreConnection();
        Schema::connection($conn)->dropIfExists('tenant_app_devices_s');
    }
};

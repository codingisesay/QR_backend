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

        Schema::connection($conn)->create('tenant_apps_s', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('app_uid', 64);                 // e.g., APP_xxx (public id)
            $table->string('name', 191);
            $table->enum('status', ['active','revoked','suspended'])->default('active');
            $table->json('scopes_json');                   // ["private-write","ops-write",...]
            $table->json('ip_allow_cidrs_json')->nullable();
            $table->json('geo_allow_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id','app_uid'], 'uq_tenant_app');
        });

        // Optional: add a FK if your core DB has `tenants` in the same database
        try {
            Schema::connection($conn)->table('tenant_apps_s', function (Blueprint $table) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        } catch (\Throwable $e) {
            // safe: skip FK if tenants table name/engine differs
        }
    }

    public function down(): void
    {
        $conn = $this->coreConnection();
        Schema::connection($conn)->dropIfExists('tenant_apps_s');
    }
};

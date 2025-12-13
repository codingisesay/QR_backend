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

        Schema::connection($conn)->create('tenant_app_keys_s', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_app_id')->index();
            $table->string('key_uid', 64);                 // e.g., KEY_2025_11_A
            $table->binary('secret_hash');                 // hash(secret) (store hash, not plaintext)
            $table->enum('algo', ['hmac-sha256'])->default('hmac-sha256');
            $table->timestamp('not_before')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['active','retired','revoked'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_app_id','key_uid'], 'uq_app_key');
            $table->index(['tenant_app_id','status','not_before','expires_at'], 'idx_valid');
        });

        try {
            Schema::connection($conn)->table('tenant_app_keys_s', function (Blueprint $table) {
                $table->foreign('tenant_app_id')->references('id')->on('tenant_apps_s')->cascadeOnDelete();
            });
        } catch (\Throwable $e) {
            // ignore FK failure if needed
        }
    }

    public function down(): void
    {
        $conn = $this->coreConnection();
        Schema::connection($conn)->dropIfExists('tenant_app_keys_s');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    if (Schema::hasTable('puf_jobs_s')) return;
    Schema::create('puf_jobs_s', function (Blueprint $t) {
      $t->bigIncrements('id');
      $t->unsignedBigInteger('tenant_id')->index();
      $t->unsignedBigInteger('qr_code_id')->index();
      $t->unsignedBigInteger('batch_id')->nullable()->index();
      $t->unsignedBigInteger('print_run_id')->nullable()->index();
      $t->enum('status', ['queued','taken','done','failed','cancelled'])->default('queued')->index();
      $t->unsignedBigInteger('taken_by_device_id')->nullable()->index();
      $t->timestamp('taken_at')->nullable();
      $t->timestamp('done_at')->nullable();
      $t->string('error_msg', 255)->nullable();
      $t->timestamps();
      $t->unique(['tenant_id','qr_code_id'], 'uq_tenant_qr_job');
    });
  }
  public function down(): void { Schema::dropIfExists('puf_jobs_s'); }
};

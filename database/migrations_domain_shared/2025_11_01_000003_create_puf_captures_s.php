<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    if (Schema::hasTable('puf_captures_s')) return;
    Schema::create('puf_captures_s', function (Blueprint $t) {
      $t->bigIncrements('id');
      $t->unsignedBigInteger('tenant_id')->index();
      $t->unsignedBigInteger('qr_code_id')->index();
      $t->unsignedBigInteger('job_id')->nullable()->index();
      $t->unsignedBigInteger('device_id')->nullable()->index();

      $t->string('image_path', 255);
      $t->char('fingerprint_hash', 64)->nullable();
      $t->string('alg', 40)->default('ORBv1');
    //   $t->unsignedDecimal('quality', 5, 2)->nullable();
    $t->decimal('quality', 5, 2)->nullable();
      $t->json('meta')->nullable();

      $t->timestamps();
      $t->unique(['tenant_id','qr_code_id'], 'uq_tenant_qr_puf_enrolled');
    });
  }
  public function down(): void { Schema::dropIfExists('puf_captures_s'); }
};

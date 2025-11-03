<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    if (Schema::hasTable('puf_devices_s')) return;
    Schema::create('puf_devices_s', function (Blueprint $t) {
      $t->bigIncrements('id');
      $t->unsignedBigInteger('tenant_id')->index();
      $t->string('code', 64)->unique();
      $t->string('name', 120)->nullable();
      $t->enum('status', ['active','inactive'])->default('active')->index();
      $t->json('caps')->nullable();
      $t->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('puf_devices_s'); }
};

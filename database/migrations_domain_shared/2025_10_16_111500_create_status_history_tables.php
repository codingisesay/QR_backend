<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::connection('domain_shared')->create('qr_status_history_s', function (Blueprint $t) {
      $t->id(); $t->unsignedBigInteger('tenant_id')->index(); $t->unsignedBigInteger('qr_code_id')->index();
      $t->string('old_status',20)->nullable(); $t->string('new_status',20); $t->string('reason',160)->nullable();
      $t->unsignedBigInteger('actor_user_id')->nullable(); $t->timestamp('at')->useCurrent(); $t->json('meta_json')->nullable();
    });
    Schema::connection('domain_shared')->create('device_status_history_s', function (Blueprint $t) {
      $t->id(); $t->unsignedBigInteger('tenant_id')->index(); $t->unsignedBigInteger('device_id')->index();
      $t->string('old_status',20)->nullable(); $t->string('new_status',20); $t->string('reason',160)->nullable();
      $t->unsignedBigInteger('actor_user_id')->nullable(); $t->timestamp('at')->useCurrent(); $t->json('meta_json')->nullable();
    });
  }
  public function down(): void {
    Schema::connection('domain_shared')->dropIfExists('qr_status_history_s');
    Schema::connection('domain_shared')->dropIfExists('device_status_history_s');
  }
};

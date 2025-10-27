<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::table('tenant_settings', function (Blueprint $t) {
      $t->unique(['tenant_id','key'], 'tenant_settings_tenant_key_unique');
    });
  }
  public function down(): void {
    Schema::table('tenant_settings', function (Blueprint $t) {
      $t->dropUnique('tenant_settings_tenant_key_unique');
    });
  }
};

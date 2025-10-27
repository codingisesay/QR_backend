<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
  public function up(): void {
    DB::statement("ALTER TABLE qr_codes_s MODIFY status ENUM('issued','bound','active','in_stock','shipped','sold','returned','retired','void') NOT NULL DEFAULT 'issued'");
    DB::statement("ALTER TABLE devices_s  MODIFY status ENUM('unbound','bound','active','in_stock','shipped','sold','returned','retired') NOT NULL DEFAULT 'unbound'");
  }
  public function down(): void {
    DB::statement("ALTER TABLE qr_codes_s MODIFY status ENUM('issued','bound') NOT NULL DEFAULT 'issued'");
    DB::statement("ALTER TABLE devices_s  MODIFY status ENUM('unbound','bound') NOT NULL DEFAULT 'unbound'");
  }
};

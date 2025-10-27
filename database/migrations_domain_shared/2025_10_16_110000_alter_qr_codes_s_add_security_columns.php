<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
  public function up(): void {
    $conn = 'domain_shared';
    $table = 'qr_codes_s';

    // 1) verification_mode: add if missing, else extend/normalize
    if (!Schema::connection($conn)->hasColumn($table, 'verification_mode')) {
      DB::connection($conn)->statement("
        ALTER TABLE {$table}
        ADD COLUMN verification_mode
          ENUM('qr','qr_puf','qr_nfc','qr_puf_nfc','puf_nfc')
          NOT NULL DEFAULT 'qr'
        AFTER status
      ");
    } else {
      DB::connection($conn)->statement("
        ALTER TABLE {$table}
        MODIFY verification_mode
          ENUM('qr','qr_puf','qr_nfc','qr_puf_nfc','puf_nfc')
          NOT NULL DEFAULT 'qr'
      ");
    }

    // 2) NFC columns (add only if missing)
    Schema::connection($conn)->table($table, function (Blueprint $t) use ($conn, $table) {
      if (!Schema::connection($conn)->hasColumn($table,'nfc_key_ref'))  $t->string('nfc_key_ref',64)->nullable()->index();
      if (!Schema::connection($conn)->hasColumn($table,'nfc_uid'))      $t->string('nfc_uid',32)->nullable()->index();
      if (!Schema::connection($conn)->hasColumn($table,'nfc_ctr_last')) $t->unsignedBigInteger('nfc_ctr_last')->default(0);

      // 3) PUF columns (add only if missing)
      if (!Schema::connection($conn)->hasColumn($table,'puf_id'))                $t->string('puf_id',64)->nullable()->index();
      if (!Schema::connection($conn)->hasColumn($table,'puf_fingerprint_hash'))  $t->char('puf_fingerprint_hash',64)->nullable()->index();
      if (!Schema::connection($conn)->hasColumn($table,'puf_alg'))               $t->string('puf_alg',40)->nullable();
      if (!Schema::connection($conn)->hasColumn($table,'puf_score_threshold'))   $t->decimal('puf_score_threshold',5,2)->nullable();
    });
  }

  public function down(): void {
    $conn = 'domain_shared';
    $table = 'qr_codes_s';

    // Drop the additive columns (safe even if some don't exist)
    Schema::connection($conn)->table($table, function (Blueprint $t) use ($conn, $table) {
      foreach (['nfc_key_ref','nfc_uid','nfc_ctr_last','puf_id','puf_fingerprint_hash','puf_alg','puf_score_threshold'] as $col) {
        if (Schema::connection($conn)->hasColumn($table,$col)) $t->dropColumn($col);
      }
    });

    // Only revert verification_mode if it exists
    if (Schema::connection($conn)->hasColumn($table, 'verification_mode')) {
      DB::connection($conn)->statement("
        ALTER TABLE {$table}
        MODIFY verification_mode ENUM('qr') NOT NULL DEFAULT 'qr'
      ");
    }
  }
};

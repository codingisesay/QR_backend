<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nfc_tags_s', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id');
            $t->string('nfc_uid', 32);                // UID from the chip (hex or string)
            $t->string('nfc_key_ref', 64);            // maps to nfc_keys_s.key_ref OR KMS alias
            $t->enum('chip_family', ['NTAG424','DESFireEV3','Other'])->default('NTAG424');

            $t->enum('status', ['new','qc_pass','reserved','bound','retired','revoked'])->default('new');
            $t->string('qc_notes', 255)->nullable();

            $t->unsignedBigInteger('print_run_id')->nullable();
            $t->unsignedBigInteger('batch_id')->nullable();

            $t->unsignedBigInteger('ctr_seed')->default(0); // optional seed (usually 0)

            $t->timestamp('imported_at')->useCurrent();
            $t->timestamp('reserved_at')->nullable();
            $t->timestamp('bound_at')->nullable();

            $t->timestamps();

            // integrity & speed
            $t->unique(['tenant_id','nfc_uid'], 'uq_tenant_uid');
            $t->index(['tenant_id','status'], 'idx_tenant_status');
            $t->index(['tenant_id','batch_id'], 'idx_tenant_batch');
            $t->index(['tenant_id','print_run_id'], 'idx_tenant_pr');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_tags_s');
    }
};

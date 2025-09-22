<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::connection('domain_shared')->create('devices_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id')->index();
      $t->unsignedBigInteger('product_id')->index();
      $t->string('device_uid', 120)->index();   // e.g., serial/IMEI/custom UID (unique within tenant+product)
      $t->string('serial', 120)->nullable()->index();
      $t->json('attrs_json')->nullable();       // template-driven attributes (imei, mac, mfg_date, etc.)
      $t->string('status', 24)->default('new'); // new | bound | shipped | ...
      $t->timestamps();
      $t->unique(['tenant_id','product_id','device_uid']);
    });

    Schema::connection('domain_shared')->create('device_qr_links_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id')->index();
      $t->unsignedBigInteger('device_id')->index();
      $t->unsignedBigInteger('qr_code_id')->index();
      $t->unsignedBigInteger('user_id')->nullable();   // who bound it (optional)
      $t->string('station_id', 80)->nullable();        // line/station (optional)
      $t->timestamp('bound_at')->useCurrent();
      $t->unique(['tenant_id','qr_code_id']);         // one QR → one device
      $t->unique(['tenant_id','device_id']);          // one device → one QR
    });

    // Optional: parent-child for composite BOM instances (finished good ↔ components)
    Schema::connection('domain_shared')->create('device_assembly_links_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id')->index();
      $t->unsignedBigInteger('parent_device_id')->index();
      $t->unsignedBigInteger('component_device_id')->nullable(); // if component is also a tracked device
      $t->string('component_serial', 120)->nullable();           // or just a serial if not tracked as full device
      $t->timestamps();
      $t->index(['tenant_id','parent_device_id']);
    });
  }

  public function down(): void {
    Schema::connection('domain_shared')->dropIfExists('device_assembly_links_s');
    Schema::connection('domain_shared')->dropIfExists('device_qr_links_s');
    Schema::connection('domain_shared')->dropIfExists('devices_s');
  }
};

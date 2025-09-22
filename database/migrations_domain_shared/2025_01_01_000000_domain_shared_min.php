<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    $c = 'domain_shared';

    // ---------- Catalog ----------
    Schema::connection($c)->create('products_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('sku', 80);
      $t->string('name', 180);
      $t->string('description', 512)->nullable();
      $t->timestamps();
      $t->unique(['tenant_id','sku'], 'uniq_products_tenant_sku');
    });

    Schema::connection($c)->create('product_batches_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('product_id');
      $t->string('batch_code', 64);
      $t->date('mfg_date')->nullable();
      $t->date('exp_date')->nullable();
      $t->integer('quantity_planned')->nullable();
      $t->unique(['tenant_id','batch_code'], 'uniq_batches_tenant_batch');
      $t->index(['product_id'], 'idx_batches_product');
    });

    Schema::connection($c)->create('qr_channels_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('code', 40);
      $t->string('name', 120)->nullable();
      $t->unique(['tenant_id','code'], 'uniq_channel_tenant_code');
    });

    Schema::connection($c)->create('print_runs_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('product_id')->nullable();
      $t->unsignedBigInteger('batch_id')->nullable();
      $t->unsignedBigInteger('channel_id')->nullable();
      $t->string('vendor_name', 120)->nullable();
      $t->string('reel_start', 40)->nullable();
      $t->string('reel_end', 40)->nullable();
      $t->integer('qty_planned')->nullable();
      $t->dateTime('created_at')->nullable();
    });

    // ---------- Codes ----------
    Schema::connection($c)->create('qr_codes_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('token', 27);
      $t->integer('token_ver');
      $t->string('status', 12);          // issued|activated|void|expired
      $t->integer('version');
      $t->unsignedBigInteger('product_id')->nullable();
      $t->unsignedBigInteger('batch_id')->nullable();
      $t->unsignedBigInteger('channel_id')->nullable();
      $t->unsignedBigInteger('print_run_id')->nullable();
      $t->binary('micro_chk', 16)->nullable();       // varbinary(16)
      $t->binary('watermark_hash', 16)->nullable();  // varbinary(16)
      $t->dateTime('issued_at')->nullable();
      $t->dateTime('activated_at')->nullable();
      $t->dateTime('voided_at')->nullable();
      $t->dateTime('expires_at')->nullable();

      $t->unique(['tenant_id','token'], 'uniq_qr_token');
      $t->index(['tenant_id','status'], 'idx_qr_tenant_status');
      $t->index(['tenant_id','product_id','batch_id'], 'idx_qr_bindings');
      $t->index(['tenant_id','channel_id'], 'idx_qr_channel');
    });

    // ---------- Scans & Verifications ----------
    Schema::connection($c)->create('verify_requests_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('token', 27);
      $t->string('app_id', 120)->nullable();
      $t->binary('device_hash', 32)->nullable();
      $t->binary('ip', 16)->nullable();
      $t->string('ua', 255)->nullable();
      $t->decimal('lat', 9, 6)->nullable();
      $t->decimal('lon', 9, 6)->nullable();
      $t->dateTime('created_at');
      $t->index(['tenant_id','created_at'], 'idx_vrfy_tenant_created');
    });

    Schema::connection($c)->create('scan_events_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('qr_id')->nullable();
      $t->string('token', 27);
      $t->string('result', 12); // Authentic|Duplicate|Moved|Expired|Invalid
      $t->unsignedBigInteger('product_id')->nullable();
      $t->unsignedBigInteger('batch_id')->nullable();
      $t->string('app_id', 120)->nullable();
      $t->binary('device_hash', 32)->nullable();
      $t->binary('ip', 16)->nullable();
      $t->string('ua', 255)->nullable();
      $t->decimal('lat', 9, 6)->nullable();
      $t->decimal('lon', 9, 6)->nullable();
      $t->string('reason_code', 64)->nullable();
      $t->json('meta_json')->nullable();
      $t->dateTime('created_at');
      $t->index(['tenant_id','created_at'], 'idx_scans_tenant_time');
      $t->index(['tenant_id','qr_id'], 'idx_scans_qr');
      $t->index(['tenant_id','product_id','batch_id'], 'idx_scans_product_batch');
    });

    // ---------- Risk ----------
    Schema::connection($c)->create('risk_rules_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('key', 64);
      $t->json('cfg_json');
      $t->boolean('enabled')->default(true);
      $t->unique(['tenant_id','key'], 'uniq_risk_rule');
    });

    Schema::connection($c)->create('risk_incidents_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('qr_id')->nullable();
      $t->string('token', 27)->nullable();
      $t->string('rule_key', 64);
      $t->string('severity', 10); // low|medium|high|critical
      $t->string('status', 10);   // open|ack|closed
      $t->string('summary', 255);
      $t->json('details_json')->nullable();
      $t->dateTime('created_at');
      $t->dateTime('closed_at')->nullable();
      $t->index(['tenant_id','status','created_at'], 'idx_inc_tenant_status');
    });

    Schema::connection($c)->create('alerts_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('incident_id')->nullable();
      $t->string('channel', 10); // email|webhook|sms
      $t->string('target', 180);
      $t->json('payload_json')->nullable();
      $t->dateTime('sent_at')->nullable();
      $t->index(['tenant_id','sent_at'], 'idx_alerts_tenant');
    });

    // ---------- Integrations ----------
    Schema::connection($c)->create('webhooks_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('name', 120);
      $t->string('url', 255);
      $t->binary('secret', 32)->nullable();
      $t->json('events');
      $t->boolean('enabled')->default(true);
      $t->unique(['tenant_id','name'], 'uniq_webhook_name');
    });

    Schema::connection($c)->create('api_clients_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('app_id', 120);
      $t->string('name', 120);
      $t->binary('api_key', 32)->nullable();
      $t->integer('rate_limit')->nullable();
      $t->boolean('enabled')->default(true);
      $t->unique(['tenant_id','app_id'], 'uniq_api_app');
    });

    // ---------- Chain anchoring ----------
    Schema::connection($c)->create('chain_batches_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('print_run_id')->nullable();
      $t->binary('merkle_root', 32);
      $t->dateTime('created_at');
      $t->index(['tenant_id','created_at'], 'idx_chain_batches_tenant');
    });

    Schema::connection($c)->create('chain_anchors_s', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('chain_batch_id');
      $t->string('chain', 16); // polygon|ethereum|other
      $t->string('tx_hash', 100);
      $t->dateTime('anchored_at');
      $t->unique(['tenant_id','chain','tx_hash'], 'uniq_chain_tx');
    });

    // ---------- FKs inside the same connection (no FK to core.tenants) ----------
    Schema::connection($c)->table('product_batches_s', function (Blueprint $t) {
      $t->foreign('product_id')->references('id')->on('products_s')->cascadeOnDelete();
    });

    Schema::connection($c)->table('print_runs_s', function (Blueprint $t) {
      $t->foreign('product_id')->references('id')->on('products_s')->nullOnDelete();
      $t->foreign('batch_id')->references('id')->on('product_batches_s')->nullOnDelete();
      $t->foreign('channel_id')->references('id')->on('qr_channels_s')->nullOnDelete();
    });

    Schema::connection($c)->table('qr_codes_s', function (Blueprint $t) {
      $t->foreign('product_id')->references('id')->on('products_s')->nullOnDelete();
      $t->foreign('batch_id')->references('id')->on('product_batches_s')->nullOnDelete();
      $t->foreign('channel_id')->references('id')->on('qr_channels_s')->nullOnDelete();
      $t->foreign('print_run_id')->references('id')->on('print_runs_s')->nullOnDelete();
    });

    Schema::connection($c)->table('scan_events_s', function (Blueprint $t) {
      $t->foreign('qr_id')->references('id')->on('qr_codes_s')->nullOnDelete();
      $t->foreign('product_id')->references('id')->on('products_s')->nullOnDelete();
      $t->foreign('batch_id')->references('id')->on('product_batches_s')->nullOnDelete();
    });

    Schema::connection($c)->table('risk_incidents_s', function (Blueprint $t) {
      $t->foreign('qr_id')->references('id')->on('qr_codes_s')->nullOnDelete();
    });

    Schema::connection($c)->table('alerts_s', function (Blueprint $t) {
      $t->foreign('incident_id')->references('id')->on('risk_incidents_s')->cascadeOnDelete();
    });

    Schema::connection($c)->table('chain_batches_s', function (Blueprint $t) {
      $t->foreign('print_run_id')->references('id')->on('print_runs_s')->nullOnDelete();
    });

    Schema::connection($c)->table('chain_anchors_s', function (Blueprint $t) {
      $t->foreign('chain_batch_id')->references('id')->on('chain_batches_s')->cascadeOnDelete();
    });
  }

  public function down(): void {
    $c = 'domain_shared';
    foreach ([
      'chain_anchors_s','chain_batches_s','api_clients_s','webhooks_s',
      'alerts_s','risk_incidents_s','risk_rules_s','scan_events_s',
      'verify_requests_s','qr_codes_s','print_runs_s','qr_channels_s',
      'product_batches_s','products_s',
      // comm tables (kept minimal earlier; include here ONLY if they exist in this file in your project)
      'comm_events_s','comm_outbox_s'
    ] as $tbl) {
      Schema::connection($c)->dropIfExists($tbl);
    }
  }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    $conn = app('tenant.conn') ?? 'tenant';

    // ---------- Catalog ----------
    Schema::connection($conn)->create('t_products', function (Blueprint $t) {
      $t->id();
      $t->string('sku', 80)->unique();
      $t->string('name', 180);
      $t->string('description', 512)->nullable();
    });

    Schema::connection($conn)->create('t_product_batches', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('product_id');
      $t->string('batch_code', 64)->unique();
      $t->date('mfg_date')->nullable();
      $t->date('exp_date')->nullable();
      $t->integer('quantity_planned')->nullable();
    });

    Schema::connection($conn)->create('t_qr_channels', function (Blueprint $t) {
      $t->id();
      $t->string('code', 40)->unique();
      $t->string('name', 120)->nullable();
    });

    Schema::connection($conn)->create('t_print_runs', function (Blueprint $t) {
      $t->id();
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
    Schema::connection($conn)->create('t_qr_codes', function (Blueprint $t) {
      $t->id();
      $t->string('token', 27)->unique();
      $t->integer('token_ver');
      $t->string('status', 12); // issued|activated|void|expired
      $t->integer('version');
      $t->unsignedBigInteger('product_id')->nullable();
      $t->unsignedBigInteger('batch_id')->nullable();
      $t->unsignedBigInteger('channel_id')->nullable();
      $t->unsignedBigInteger('print_run_id')->nullable();
      $t->binary('micro_chk', 16)->nullable();
      $t->binary('watermark_hash', 16)->nullable();
      $t->dateTime('issued_at')->nullable();
      $t->dateTime('activated_at')->nullable();
      $t->dateTime('voided_at')->nullable();
      $t->dateTime('expires_at')->nullable();
    });

    // ---------- Scans & Verifications ----------
    Schema::connection($conn)->create('t_verify_requests', function (Blueprint $t) {
      $t->id();
      $t->string('token', 27);
      $t->string('app_id', 120)->nullable();
      $t->binary('device_hash', 32)->nullable();
      $t->binary('ip', 16)->nullable();
      $t->string('ua', 255)->nullable();
      $t->decimal('lat', 9, 6)->nullable();
      $t->decimal('lon', 9, 6)->nullable();
      $t->dateTime('created_at');
    });

    Schema::connection($conn)->create('t_scan_events', function (Blueprint $t) {
      $t->id();
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
      $t->index(['qr_id'], 'idx_t_scans_qr');
    });

    // ---------- Risk ----------
    Schema::connection($conn)->create('t_risk_rules', function (Blueprint $t) {
      $t->id();
      $t->string('key', 64)->unique();
      $t->json('cfg_json');
      $t->boolean('enabled')->default(true);
    });

    Schema::connection($conn)->create('t_risk_incidents', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('qr_id')->nullable();
      $t->string('token', 27)->nullable();
      $t->string('rule_key', 64);
      $t->string('severity', 10); // low|medium|high|critical
      $t->string('status', 10);   // open|ack|closed
      $t->string('summary', 255);
      $t->json('details_json')->nullable();
      $t->dateTime('created_at');
      $t->dateTime('closed_at')->nullable();
      $t->index(['status','created_at'], 'idx_t_inc_status');
    });

    Schema::connection($conn)->create('t_alerts', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('incident_id')->nullable();
      $t->string('channel', 10); // email|webhook|sms
      $t->string('target', 180);
      $t->json('payload_json')->nullable();
      $t->dateTime('sent_at')->nullable();
      $t->index(['sent_at'], 'idx_t_alerts_sent');
    });

    // ---------- Integrations ----------
    Schema::connection($conn)->create('t_webhooks', function (Blueprint $t) {
      $t->id();
      $t->string('name', 120)->unique();
      $t->string('url', 255);
      $t->binary('secret', 32)->nullable();
      $t->json('events');
      $t->boolean('enabled')->default(true);
    });

    Schema::connection($conn)->create('t_api_clients', function (Blueprint $t) {
      $t->id();
      $t->string('app_id', 120)->unique();
      $t->string('name', 120);
      $t->binary('api_key', 32)->nullable();
      $t->integer('rate_limit')->nullable();
      $t->boolean('enabled')->default(true);
    });

    // ---------- Chain anchoring ----------
    Schema::connection($conn)->create('t_chain_batches', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('print_run_id')->nullable();
      $t->binary('merkle_root', 32);
      $t->dateTime('created_at');
    });

    Schema::connection($conn)->create('t_chain_anchors', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('chain_batch_id');
      $t->string('chain', 16);
      $t->string('tx_hash', 100);
      $t->dateTime('anchored_at');
      $t->unique(['chain','tx_hash'], 'uniq_t_chain_tx');
    });

    // ---------- Communications (tenant-local) ----------
    Schema::connection($conn)->create('t_comm_templates', function (Blueprint $t) {
      $t->id();
      $t->string('key', 64);
      $t->string('channel', 16); // email|sms|whatsapp|push
      $t->integer('version')->default(1);
      $t->string('name', 120)->nullable();
      $t->string('subject', 191)->nullable();
      $t->text('body_text')->nullable();
      $t->text('body_html')->nullable();
      $t->json('vars_json')->nullable();
      $t->boolean('is_active')->default(true);
      $t->timestamps();
      $t->unique(['key','version'], 'uniq_t_comm_tpl_key_ver');
    });

    Schema::connection($conn)->create('t_comm_outbox', function (Blueprint $t) {
      $t->id();
      $t->string('channel', 16);
      $t->unsignedBigInteger('template_id')->nullable();
      $t->string('to_email', 191)->nullable();
      $t->string('to_phone', 32)->nullable();
      $t->unsignedBigInteger('to_user_id')->nullable();
      $t->string('to_name', 120)->nullable();
      $t->string('subject', 191)->nullable();
      $t->text('body_text')->nullable();
      $t->text('body_html')->nullable();
      $t->json('vars_json')->nullable();
      $t->string('provider_hint', 64)->nullable();
      $t->string('status', 16)->default('queued'); // queued|sending|sent|failed
      $t->integer('priority')->default(0);
      $t->integer('attempt_count')->default(0);
      $t->string('idempotency_key', 64)->nullable()->unique();
      $t->dateTime('scheduled_at')->nullable();
      $t->dateTime('sent_at')->nullable();
      $t->string('last_error', 255)->nullable();
      $t->timestamps();
      $t->index(['status','scheduled_at'], 'idx_t_comm_outbox_status_sched');
    });

    Schema::connection($conn)->create('t_comm_events', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('outbox_id')->nullable();
      $t->string('provider_msg_id', 120)->nullable();
      $t->string('event_type', 16); // sent|delivered|opened|bounced|failed
      $t->dateTime('event_at');
      $t->json('meta_json')->nullable();
      $t->index(['outbox_id','event_at'], 'idx_t_comm_events_outbox_time');
    });

    // ---------- Foreign Keys (all same-DB) ----------
    Schema::connection($conn)->table('t_product_batches', function (Blueprint $t) {
      $t->foreign('product_id')->references('id')->on('t_products')->cascadeOnDelete();
    });

    Schema::connection($conn)->table('t_print_runs', function (Blueprint $t) {
      $t->foreign('product_id')->references('id')->on('t_products')->nullOnDelete();
      $t->foreign('batch_id')->references('id')->on('t_product_batches')->nullOnDelete();
      $t->foreign('channel_id')->references('id')->on('t_qr_channels')->nullOnDelete();
    });

    Schema::connection($conn)->table('t_qr_codes', function (Blueprint $t) {
      $t->foreign('product_id')->references('id')->on('t_products')->nullOnDelete();
      $t->foreign('batch_id')->references('id')->on('t_product_batches')->nullOnDelete();
      $t->foreign('channel_id')->references('id')->on('t_qr_channels')->nullOnDelete();
      $t->foreign('print_run_id')->references('id')->on('t_print_runs')->nullOnDelete();
    });

    Schema::connection($conn)->table('t_scan_events', function (Blueprint $t) {
      $t->foreign('qr_id')->references('id')->on('t_qr_codes')->nullOnDelete();
      $t->foreign('product_id')->references('id')->on('t_products')->nullOnDelete();
      $t->foreign('batch_id')->references('id')->on('t_product_batches')->nullOnDelete();
    });

    Schema::connection($conn)->table('t_risk_incidents', function (Blueprint $t) {
      $t->foreign('qr_id')->references('id')->on('t_qr_codes')->nullOnDelete();
    });

    Schema::connection($conn)->table('t_alerts', function (Blueprint $t) {
      $t->foreign('incident_id')->references('id')->on('t_risk_incidents')->cascadeOnDelete();
    });

    Schema::connection($conn)->table('t_chain_batches', function (Blueprint $t) {
      $t->foreign('print_run_id')->references('id')->on('t_print_runs')->nullOnDelete();
    });

    Schema::connection($conn)->table('t_chain_anchors', function (Blueprint $t) {
      $t->foreign('chain_batch_id')->references('id')->on('t_chain_batches')->cascadeOnDelete();
    });

    Schema::connection($conn)->table('t_comm_outbox', function (Blueprint $t) {
      $t->foreign('template_id')->references('id')->on('t_comm_templates')->nullOnDelete();
    });

    Schema::connection($conn)->table('t_comm_events', function (Blueprint $t) {
      $t->foreign('outbox_id')->references('id')->on('t_comm_outbox')->cascadeOnDelete();
    });
  }

  public function down(): void {
    $conn = app('tenant.conn') ?? 'tenant';
    foreach ([
      't_comm_events','t_comm_outbox','t_comm_templates',
      't_chain_anchors','t_chain_batches','t_api_clients','t_webhooks',
      't_alerts','t_risk_incidents','t_risk_rules','t_scan_events',
      't_verify_requests','t_qr_codes','t_print_runs','t_qr_channels',
      't_product_batches','t_products'
    ] as $tbl) {
      Schema::connection($conn)->dropIfExists($tbl);
    }
  }
};

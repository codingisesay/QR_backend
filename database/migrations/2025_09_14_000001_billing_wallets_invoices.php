<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    // ---------- Billing profile (mode + contact + options) ----------
    Schema::create('billing_profiles', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id')->unique();
      $t->string('mode', 12)->default('wallet'); // wallet|invoice
      $t->unsignedBigInteger('plan_id')->nullable();
      $t->string('currency', 3)->default('INR');
      // invoice mode options
      $t->unsignedSmallInteger('invoice_day_of_month')->default(1); // 1..28
      $t->bigInteger('credit_limit_cents')->default(0);
      // contact / address (adjust as needed)
      $t->string('bill_to_name', 120)->nullable();
      $t->string('bill_to_email', 191)->nullable();
      $t->string('bill_to_phone', 32)->nullable();
      $t->string('gstin', 32)->nullable();
      $t->string('addr_line1', 191)->nullable();
      $t->string('addr_line2', 191)->nullable();
      $t->string('city', 120)->nullable();
      $t->string('state', 120)->nullable();
      $t->string('zip', 20)->nullable();
      $t->string('country', 2)->nullable();
      // payment provider linkage (optional)
      $t->string('provider', 16)->nullable();           // stripe|razorpay|...
      $t->string('provider_customer_id', 120)->nullable();
      $t->timestamps();

      $t->index('tenant_id', 'idx_bprof_tenant');
      $t->index('plan_id', 'idx_bprof_plan');
    });

    // ---------- Wallet ----------
    Schema::create('wallets', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id')->unique();
      $t->bigInteger('balance_cents')->default(0);
      $t->string('currency', 3)->default('INR');
      $t->timestamps();

      $t->index('tenant_id', 'idx_wallet_tenant');
    });

    Schema::create('wallet_transactions', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('wallet_id');
      $t->string('type', 8); // credit|debit
      $t->bigInteger('amount_cents');
      $t->bigInteger('balance_after_cents');
      $t->string('reason', 40)->nullable(); // topup|qr_generation|refund|adjustment
      $t->json('meta')->nullable();
      $t->string('idempotency_key', 120)->nullable()->unique();
      $t->timestamps();

      $t->index(['wallet_id','created_at'], 'idx_wtx_wallet_time');
    });

    // ---------- Usage events (for postpaid/invoices) ----------
    Schema::create('usage_events', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('event_type', 32); // qr_generated, etc.
      $t->integer('quantity')->default(1);
      $t->bigInteger('unit_price_cents'); // snapshot at event time
      $t->dateTime('occurred_at');
      $t->unsignedBigInteger('invoice_id')->nullable();
      $t->string('idempotency_key', 120)->nullable()->unique();
      $t->json('meta')->nullable();
      $t->timestamps();

      $t->index(['tenant_id','occurred_at'], 'idx_usage_tenant_time');
      $t->index(['invoice_id'], 'idx_usage_invoice');
    });

    // ---------- Invoices ----------
    Schema::create('invoices', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('currency', 3)->default('INR');
      $t->string('status', 12)->default('draft'); // draft|open|paid|void
      $t->date('period_start');
      $t->date('period_end');
      $t->bigInteger('subtotal_cents')->default(0);
      $t->bigInteger('tax_cents')->default(0);
      $t->bigInteger('total_cents')->default(0);
      $t->json('bill_to_snapshot')->nullable(); // freeze billing details for the doc
      // provider linkage (if you use Stripe/Razorpay invoices)
      $t->string('provider', 16)->nullable();
      $t->string('provider_invoice_id', 120)->nullable();
      $t->string('provider_status', 32)->nullable();
      $t->dateTime('issued_at')->nullable();
      $t->dateTime('paid_at')->nullable();
      $t->timestamps();

      $t->index(['tenant_id','created_at'], 'idx_inv_tenant_time');
      $t->index(['status','created_at'], 'idx_inv_status_time');
    });

    Schema::create('invoice_items', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('invoice_id');
      $t->string('type', 24); // base|overage|credit|adjustment
      $t->string('description', 191);
      $t->integer('quantity')->default(1);
      $t->bigInteger('unit_price_cents');
      $t->bigInteger('amount_cents'); // quantity * unit or explicit
      $t->json('meta')->nullable();
      $t->timestamps();

      $t->index('invoice_id', 'idx_inv_item_invoice');
    });

    // ---------- Helpful FKs (same core DB) ----------
    Schema::table('billing_profiles', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
      $t->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
    });
    Schema::table('wallets', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    });
    Schema::table('wallet_transactions', function (Blueprint $t) {
      $t->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
    });
    Schema::table('usage_events', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
      $t->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
    });
    Schema::table('invoice_items', function (Blueprint $t) {
      $t->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
    });
  }

  public function down(): void {
    foreach (['invoice_items','invoices','usage_events','wallet_transactions','wallets','billing_profiles'] as $tbl) {
      Schema::dropIfExists($tbl);
    }
  }
};

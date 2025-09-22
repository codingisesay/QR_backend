<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::table('plans', function (Blueprint $t) {
      if (!Schema::hasColumn('plans','price_cents')) {
        $t->bigInteger('price_cents')->nullable()->after('price'); // keep decimal for compat
      }
      if (!Schema::hasColumn('plans','included_qr_per_month')) {
        $t->integer('included_qr_per_month')->default(0)->after('period');
      }
      if (!Schema::hasColumn('plans','overage_price_cents')) {
        $t->bigInteger('overage_price_cents')->default(0)->after('included_qr_per_month');
      }
    });

    Schema::table('subscriptions', function (Blueprint $t) {
      if (!Schema::hasColumn('subscriptions','provider')) {
        $t->string('provider', 16)->nullable()->after('status');
      }
      if (!Schema::hasColumn('subscriptions','provider_sub_id')) {
        $t->string('provider_sub_id', 120)->nullable()->after('provider');
      }
      if (!Schema::hasColumn('subscriptions','cancel_at')) {
        $t->dateTime('cancel_at')->nullable()->after('period_end');
      }
    });
  }

  public function down(): void {
    Schema::table('plans', function (Blueprint $t) {
      $t->dropColumn(['price_cents','included_qr_per_month','overage_price_cents']);
    });
    Schema::table('subscriptions', function (Blueprint $t) {
      $t->dropColumn(['provider','provider_sub_id','cancel_at']);
    });
  }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    // ---------- Base ----------
    Schema::create('plans', function (Blueprint $t) {
      $t->id();
      $t->string('name', 80)->unique();
      $t->decimal('price', 10, 2);
      $t->string('period', 10); // monthly|yearly
      $t->json('limits_json')->nullable();
      $t->timestamps();
    });

    Schema::create('tenants', function (Blueprint $t) {
      $t->id();
      $t->string('slug', 64)->unique();
      $t->string('name', 150);
      $t->string('status', 20)->default('active'); // active|suspended|closed
      $t->unsignedBigInteger('plan_id')->nullable();
      $t->string('isolation_mode', 16)->default('schema'); // schema|shared|database
      $t->string('db_host', 191)->nullable();
      $t->integer('db_port')->nullable();
      $t->string('db_name', 191)->nullable();
      $t->string('db_user', 191)->nullable();
      $t->text('db_pass')->nullable(); // encrypt at rest
      $t->string('schema_version', 32)->nullable();
      $t->timestamps();

      $t->index('plan_id', 'idx_tenants_plan_id');
      // FK added after both tables exist:
    });

    // Users — only add custom columns here, do NOT create the table or touch password fields
if (Schema::hasTable('users')) {
    Schema::table('users', function (Blueprint $t) {
        if (!Schema::hasColumn('users', 'is_superadmin')) {
            // place it after password if it exists, otherwise after email
            $after = Schema::hasColumn('users','password') ? 'password' : 'email';
            $t->boolean('is_superadmin')->default(false)->after($after);
        }

        // IMPORTANT: do not add/restore password_hash here
        // if (Schema::hasColumn('users','password_hash')) { $t->dropColumn('password_hash'); } // optional cleanup (needs doctrine/dbal)
    });
}

    // ---------- Membership & RBAC ----------
    Schema::create('org_members', function (Blueprint $t) {
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('user_id');
      $t->string('status', 16); // active|invited|disabled
      $t->dateTime('joined_at')->nullable();

      $t->primary(['tenant_id', 'user_id'], 'pk_org_members');
      $t->index('user_id', 'idx_org_members_user');
    });

    Schema::create('roles', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('key', 64); // owner|admin|operator|viewer
      $t->string('name', 100)->nullable();
      $t->string('description', 255)->nullable();

      $t->unique(['tenant_id', 'key'], 'uniq_roles_tenant_key');
      $t->index('tenant_id', 'idx_roles_tenant');
    });

    Schema::create('permissions', function (Blueprint $t) {
      $t->id();
      $t->string('key', 64)->unique(); // e.g. product.read
      $t->string('name', 100)->nullable();
      $t->string('description', 255)->nullable();
    });

    Schema::create('role_permissions', function (Blueprint $t) {
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('role_id');
      $t->unsignedBigInteger('permission_id');

      $t->primary(['tenant_id', 'role_id', 'permission_id'], 'pk_role_permissions');
      $t->index('permission_id', 'idx_rp_permission');
    });

    Schema::create('user_roles', function (Blueprint $t) {
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('user_id');
      $t->unsignedBigInteger('role_id');

      $t->primary(['tenant_id', 'user_id', 'role_id'], 'pk_user_roles');
      $t->index('role_id', 'idx_ur_role');
    });

    Schema::create('tenant_settings', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('key', 80);
      $t->json('value_json')->nullable();

      $t->unique(['tenant_id', 'key'], 'uniq_tenant_settings_key');
      $t->index('tenant_id', 'idx_tenant_settings_tenant');
    });

    Schema::create('subscriptions', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('plan_id');
      $t->dateTime('period_start');
      $t->dateTime('period_end');
      $t->string('status', 16); // active|trial|past_due|canceled

      $t->index('tenant_id', 'idx_subscriptions_tenant');
      $t->index('plan_id', 'idx_subscriptions_plan');
    });

    Schema::create('audit_logs', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->unsignedBigInteger('actor_user_id')->nullable();
      $t->string('action', 80);
      $t->string('resource_type', 80);
      $t->unsignedBigInteger('resource_id')->nullable();
      $t->json('data_json')->nullable();
      $t->dateTime('at');

      $t->index('tenant_id', 'idx_audit_tenant');
      $t->index('actor_user_id', 'idx_audit_actor');
    });

    // ---------- Communications (providers + queue + usage) ----------
    Schema::create('comm_providers', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('channel', 16);      // email|sms|whatsapp|push
      $t->string('provider', 24);     // ses|twilio|fcm|...
      $t->string('name', 120);
      $t->json('credentials_json')->nullable(); // provider-specific creds
      $t->string('from_email', 191)->nullable();
      $t->string('from_name', 120)->nullable();
      $t->string('sender_id', 64)->nullable(); // e.g., SMS sender ID
      $t->string('status', 16)->default('active');
      $t->timestamps();

      $t->unique(['tenant_id', 'channel', 'name']);
    });

    Schema::create('comm_sender_identities', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('type', 24);        // email_domain|phone|fcm_key
      $t->string('identity', 191);   // domain or number
      $t->string('display', 120)->nullable();
      $t->string('verify_status', 16)->default('pending');
      $t->string('dkim_selector', 64)->nullable();
      $t->string('dkim_dns', 255)->nullable();
      $t->boolean('spf_required')->default(false);
      $t->boolean('dmarc_required')->default(false);
      $t->timestamps();

      $t->unique(['tenant_id', 'type', 'identity']);
    });

    Schema::create('comm_dispatch_queue', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->string('channel', 16);                 // email|sms|whatsapp|push
      $t->unsignedBigInteger('outbox_local_id'); // points to tenant-local or shared outbox (no cross-DB FK)
      $t->dateTime('due_at');
      $t->integer('priority')->default(0);
      $t->string('state', 16)->default('queued'); // queued|picked|done
      $t->integer('attempts')->default(0);
      $t->string('last_error', 255)->nullable();
      $t->timestamps();

      $t->index(['tenant_id', 'channel', 'due_at', 'state']);
      $t->index(['state', 'due_at']);
    });

    Schema::create('comm_usage_daily', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('tenant_id');
      $t->date('date');
      $t->string('channel', 16);
      $t->integer('sent_count')->default(0);
      $t->integer('failed_count')->default(0);
      $t->integer('cost_cents')->nullable();
      $t->dateTime('updated_at')->nullable();

      $t->unique(['tenant_id', 'date', 'channel']);
    });

    // ---------- Foreign Keys (same-DB only; avoid cross-DB) ----------
    Schema::table('tenants', function (Blueprint $t) {
      $t->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
    });

    Schema::table('org_members', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
      $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    });

    Schema::table('roles', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    });

    Schema::table('role_permissions', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
      $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
      $t->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
    });

    Schema::table('user_roles', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
      $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
      $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    });

    Schema::table('tenant_settings', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    });

    Schema::table('subscriptions', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
      $t->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
    });

    Schema::table('audit_logs', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
      $t->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
    });

    Schema::table('comm_providers', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    });

    Schema::table('comm_sender_identities', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    });

    Schema::table('comm_dispatch_queue', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    });

    Schema::table('comm_usage_daily', function (Blueprint $t) {
      $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    });
  }

  public function down(): void {
    // Drop FKs first to avoid errors (some drivers can handle without, but be safe)
    foreach ([
      'comm_usage_daily','comm_dispatch_queue','comm_sender_identities','comm_providers',
      'audit_logs','subscriptions','tenant_settings','user_roles','role_permissions',
      'permissions','roles','org_members','users','tenants','plans'
    ] as $tbl) {
      Schema::dropIfExists($tbl);
    }
  }
};

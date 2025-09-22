<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $table = 'tenants';

    protected $fillable = [
        'slug','name','status','plan_id','isolation_mode',
        'db_host','db_port','db_name','db_user','db_pass','schema_version',
    ];

    public $timestamps = true;

    // Relations
    public function plan() { return $this->belongsTo(Plan::class, 'plan_id'); }

    public function members() { return $this->hasMany(OrgMember::class, 'tenant_id'); }

    public function users()
    {
        return $this->belongsToMany(User::class, 'org_members', 'tenant_id', 'user_id')
                    ->withPivot('status','joined_at');
    }

    public function roles() { return $this->hasMany(Role::class, 'tenant_id'); }

    public function settings() { return $this->hasMany(TenantSetting::class, 'tenant_id'); }

    public function subscriptions() { return $this->hasMany(Subscription::class, 'tenant_id'); }

    public function auditLogs() { return $this->hasMany(AuditLog::class, 'tenant_id'); }

    public function commProviders() { return $this->hasMany(CommProvider::class, 'tenant_id'); }

    public function senderIdentities() { return $this->hasMany(CommSenderIdentity::class, 'tenant_id'); }

    public function dispatchQueue() { return $this->hasMany(CommDispatchQueue::class, 'tenant_id'); }

    public function usageDaily() { return $this->hasMany(CommUsageDaily::class, 'tenant_id'); }
}

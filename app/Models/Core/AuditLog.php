<?php

namespace App\Models\Core;

class AuditLog extends BaseCoreTenantModel
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'tenant_id','actor_user_id','action','resource_type','resource_id','data_json','at',
    ];

    protected $casts = ['data_json'=>'array','at'=>'datetime'];

    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function actor()  { return $this->belongsTo(User::class, 'actor_user_id'); }
}

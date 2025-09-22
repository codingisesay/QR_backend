<?php

namespace App\Models\Core;

class CommUsageDaily extends BaseCoreTenantModel
{
    protected $table = 'comm_usage_daily';
    public $timestamps = false;

    protected $fillable = [
        'tenant_id','date','channel','sent_count','failed_count','cost_cents','updated_at',
    ];

    protected $casts = [
        'date'=>'date','sent_count'=>'int','failed_count'=>'int','cost_cents'=>'int','updated_at'=>'datetime',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
}

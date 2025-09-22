<?php

namespace App\Models\Core;

class Subscription extends BaseCoreTenantModel
{
    protected $table = 'subscriptions';

    protected $fillable = ['tenant_id','plan_id','period_start','period_end','status'];

    protected $casts = ['period_start'=>'datetime','period_end'=>'datetime'];

    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function plan()   { return $this->belongsTo(Plan::class, 'plan_id'); }
}

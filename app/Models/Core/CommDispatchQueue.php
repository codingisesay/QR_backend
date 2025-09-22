<?php

namespace App\Models\Core;

class CommDispatchQueue extends BaseCoreTenantModel
{
    protected $table = 'comm_dispatch_queue';
    public $timestamps = true;

    protected $fillable = [
        'tenant_id','channel','outbox_local_id','due_at','priority','state','attempts','last_error',
    ];

    protected $casts = [
        'due_at'=>'datetime','priority'=>'int','attempts'=>'int',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
}

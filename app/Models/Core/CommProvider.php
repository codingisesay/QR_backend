<?php

namespace App\Models\Core;

class CommProvider extends BaseCoreTenantModel
{
    protected $table = 'comm_providers';
    public $timestamps = true;

    protected $fillable = [
        'tenant_id','channel','provider','name','credentials_json',
        'from_email','from_name','sender_id','status',
    ];

    protected $casts = ['credentials_json'=>'array'];

    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
}

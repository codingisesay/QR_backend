<?php

namespace App\Models\Core;

class CommSenderIdentity extends BaseCoreTenantModel
{
    protected $table = 'comm_sender_identities';
    public $timestamps = true;

    protected $fillable = [
        'tenant_id','type','identity','display','verify_status',
        'dkim_selector','dkim_dns','spf_required','dmarc_required',
    ];

    protected $casts = [
        'spf_required'=>'bool','dmarc_required'=>'bool',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
}

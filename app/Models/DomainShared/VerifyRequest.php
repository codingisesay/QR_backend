<?php
// app/Models/DomainShared/VerifyRequest.php
namespace App\Models\DomainShared;

class VerifyRequest extends BaseSharedModel
{
    protected $table = 'verify_requests_s';
    protected $fillable = ['tenant_id','token','app_id','device_hash','ip','ua','lat','lon','created_at'];
    protected $casts = ['created_at'=>'datetime','lat'=>'float','lon'=>'float'];
}

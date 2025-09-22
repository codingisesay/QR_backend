<?php
// app/Models/Domain/VerifyRequest.php
namespace App\Models\Domain;

class VerifyRequest extends BaseTenantModel
{
    protected $table = 't_verify_requests';
    protected $fillable = ['token','app_id','device_hash','ip','ua','lat','lon','created_at'];
    protected $casts = ['created_at'=>'datetime','lat'=>'float','lon'=>'float'];
}

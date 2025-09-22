<?php
// app/Models/DomainShared/ApiClient.php
namespace App\Models\DomainShared;

class ApiClient extends BaseSharedModel
{
    protected $table = 'api_clients_s';
    protected $fillable = ['tenant_id','app_id','name','api_key','rate_limit','enabled'];
    protected $casts = ['enabled'=>'bool','rate_limit'=>'int'];
}

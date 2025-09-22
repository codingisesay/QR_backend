<?php
// app/Models/Domain/ApiClient.php
namespace App\Models\Domain;

class ApiClient extends BaseTenantModel
{
    protected $table = 't_api_clients';
    protected $fillable = ['app_id','name','api_key','rate_limit','enabled'];
    protected $casts = ['enabled'=>'bool','rate_limit'=>'int'];
}

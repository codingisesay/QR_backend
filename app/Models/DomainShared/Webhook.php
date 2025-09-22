<?php
// app/Models/DomainShared/Webhook.php
namespace App\Models\DomainShared;

class Webhook extends BaseSharedModel
{
    protected $table = 'webhooks_s';
    protected $fillable = ['tenant_id','name','url','secret','events','enabled'];
    protected $casts = ['events'=>'array','enabled'=>'bool'];
}

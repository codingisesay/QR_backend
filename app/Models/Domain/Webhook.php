<?php
// app/Models/Domain/Webhook.php
namespace App\Models\Domain;

class Webhook extends BaseTenantModel
{
    protected $table = 't_webhooks';
    protected $fillable = ['name','url','secret','events','enabled'];
    protected $casts = ['events'=>'array','enabled'=>'bool'];
}

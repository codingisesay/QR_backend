<?php
// app/Models/DomainShared/RiskRule.php
namespace App\Models\DomainShared;

class RiskRule extends BaseSharedModel
{
    protected $table = 'risk_rules_s';
    protected $fillable = ['tenant_id','key','cfg_json','enabled'];
    protected $casts = ['cfg_json'=>'array','enabled'=>'bool'];
}

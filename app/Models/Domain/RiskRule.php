<?php
// app/Models/Domain/RiskRule.php
namespace App\Models\Domain;

class RiskRule extends BaseTenantModel
{
    protected $table = 't_risk_rules';
    protected $fillable = ['key','cfg_json','enabled'];
    protected $casts = ['cfg_json'=>'array','enabled'=>'bool'];
}

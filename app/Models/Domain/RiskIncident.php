<?php
// app/Models/Domain/RiskIncident.php
namespace App\Models\Domain;

class RiskIncident extends BaseTenantModel
{
    protected $table = 't_risk_incidents';
    protected $fillable = [
        'qr_id','token','rule_key','severity','status','summary','details_json','created_at','closed_at'
    ];
    protected $casts = ['created_at'=>'datetime','closed_at'=>'datetime','details_json'=>'array'];

    public function qr(){ return $this->belongsTo(QrCode::class, 'qr_id'); }
    public function alerts(){ return $this->hasMany(Alert::class, 'incident_id'); }
}

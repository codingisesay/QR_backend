<?php
// app/Models/DomainShared/RiskIncident.php
namespace App\Models\DomainShared;

class RiskIncident extends BaseSharedModel
{
    protected $table = 'risk_incidents_s';
    protected $fillable = [
        'tenant_id','qr_id','token','rule_key','severity','status','summary','details_json','created_at','closed_at'
    ];
    protected $casts = ['created_at'=>'datetime','closed_at'=>'datetime','details_json'=>'array'];

    public function qr(){ return $this->belongsTo(QrCode::class, 'qr_id'); }
    public function alerts(){ return $this->hasMany(Alert::class, 'incident_id'); }
}

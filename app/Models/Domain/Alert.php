<?php
// app/Models/Domain/Alert.php
namespace App\Models\Domain;

class Alert extends BaseTenantModel
{
    protected $table = 't_alerts';
    protected $fillable = ['incident_id','channel','target','payload_json','sent_at'];
    protected $casts = ['sent_at'=>'datetime','payload_json'=>'array'];

    public function incident(){ return $this->belongsTo(RiskIncident::class, 'incident_id'); }
}

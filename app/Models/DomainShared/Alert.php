<?php
// app/Models/DomainShared/Alert.php
namespace App\Models\DomainShared;

class Alert extends BaseSharedModel
{
    protected $table = 'alerts_s';
    protected $fillable = ['tenant_id','incident_id','channel','target','payload_json','sent_at'];
    protected $casts = ['sent_at'=>'datetime','payload_json'=>'array'];

    public function incident(){ return $this->belongsTo(RiskIncident::class, 'incident_id'); }
}

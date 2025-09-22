<?php
// app/Models/Domain/CommEvent.php
namespace App\Models\Domain;

class CommEvent extends BaseTenantModel
{
    protected $table = 't_comm_events';
    protected $fillable = ['outbox_id','provider_msg_id','event_type','event_at','meta_json'];
    protected $casts = ['event_at'=>'datetime','meta_json'=>'array'];

    public function outbox(){ return $this->belongsTo(CommOutbox::class, 'outbox_id'); }
}

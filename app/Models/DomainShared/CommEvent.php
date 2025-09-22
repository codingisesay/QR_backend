<?php
// app/Models/DomainShared/CommEvent.php
namespace App\Models\DomainShared;

class CommEvent extends BaseSharedModel
{
    protected $table = 'comm_events_s';
    protected $fillable = ['tenant_id','outbox_id','provider_msg_id','event_type','event_at','meta_json'];
    protected $casts = ['event_at'=>'datetime','meta_json'=>'array'];

    public function outbox(){ return $this->belongsTo(CommOutbox::class, 'outbox_id'); }
}

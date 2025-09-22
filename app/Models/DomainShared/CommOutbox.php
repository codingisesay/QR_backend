<?php
// app/Models/DomainShared/CommOutbox.php
namespace App\Models\DomainShared;

class CommOutbox extends BaseSharedModel
{
    protected $table = 'comm_outbox_s';
    public $timestamps = true;

    protected $fillable = [
        'tenant_id','channel','template_id','to_email','to_phone','to_user_id','to_name',
        'subject','body_text','body_html','vars_json','provider_hint','status','priority',
        'attempt_count','idempotency_key','scheduled_at','sent_at','last_error'
    ];

    protected $casts = [
        'vars_json'=>'array','scheduled_at'=>'datetime','sent_at'=>'datetime',
        'priority'=>'int','attempt_count'=>'int',
    ];

    public function events(){ return $this->hasMany(CommEvent::class, 'outbox_id'); }
}

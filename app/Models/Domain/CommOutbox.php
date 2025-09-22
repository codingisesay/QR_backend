<?php
// app/Models/Domain/CommOutbox.php
namespace App\Models\Domain;

class CommOutbox extends BaseTenantModel
{
    protected $table = 't_comm_outbox';
    public $timestamps = true;

    protected $fillable = [
        'channel','template_id','to_email','to_phone','to_user_id','to_name',
        'subject','body_text','body_html','vars_json','provider_hint','status',
        'priority','attempt_count','idempotency_key','scheduled_at','sent_at','last_error'
    ];

    protected $casts = [
        'vars_json'=>'array','scheduled_at'=>'datetime','sent_at'=>'datetime',
        'priority'=>'int','attempt_count'=>'int',
    ];

    public function template(){ return $this->belongsTo(CommTemplate::class, 'template_id'); }
    public function events()  { return $this->hasMany(CommEvent::class, 'outbox_id'); }
}

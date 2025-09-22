<?php
// app/Models/Domain/CommTemplate.php
namespace App\Models\Domain;

class CommTemplate extends BaseTenantModel
{
    protected $table = 't_comm_templates';
    public $timestamps = true;

    protected $fillable = [
        'key','channel','version','name','subject','body_text','body_html','vars_json','is_active'
    ];
    protected $casts = ['vars_json'=>'array','is_active'=>'bool'];
}

<?php

namespace App\Models\Core;

class TenantSetting extends BaseCoreTenantModel
{
    protected $table = 'tenant_settings';

    protected $fillable = ['tenant_id','key','value_json'];

    protected $casts = ['value_json' => 'array'];

    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
}

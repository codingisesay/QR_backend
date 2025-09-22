<?php

namespace App\Models\Core;

class OrgMember extends BaseCoreTenantModel
{
    protected $table = 'org_members';
    protected $primaryKey = null;
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id','user_id','status','joined_at'];

    protected $casts = ['joined_at' => 'datetime'];

    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function user()   { return $this->belongsTo(User::class, 'user_id'); }
}

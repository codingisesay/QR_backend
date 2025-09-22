<?php

namespace App\Models\Core;

class UserRole extends BaseCoreTenantModel
{
    protected $table = 'user_roles';
    protected $primaryKey = null;
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id','user_id','role_id'];

    public function user()   { return $this->belongsTo(User::class, 'user_id'); }
    public function role()   { return $this->belongsTo(Role::class, 'role_id'); }
    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }
}

<?php

namespace App\Models\Core;

class Role extends BaseCoreTenantModel
{
    protected $table = 'roles';

    protected $fillable = ['tenant_id','key','name','description'];

    public function tenant() { return $this->belongsTo(Tenant::class, 'tenant_id'); }

    public function permissions()
    {
        // Note: pivot has tenant_id too; filter by current tenant when needed
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
                    ->withPivot('tenant_id');
    }

    public function userLinks() { return $this->hasMany(UserRole::class, 'role_id'); }

    
}

<?php

namespace App\Models\Core;

class RolePermission extends BaseCoreTenantModel
{
    protected $table = 'role_permissions';
    protected $primaryKey = null;
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['tenant_id','role_id','permission_id'];

    public function role()       { return $this->belongsTo(Role::class, 'role_id'); }
    public function permission() { return $this->belongsTo(Permission::class, 'permission_id'); }
    public function tenant()     { return $this->belongsTo(Tenant::class, 'tenant_id'); }
}

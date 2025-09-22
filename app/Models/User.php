<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Core\{OrgMember, Tenant, Role};
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str; 




class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    protected $fillable = ['name','email','password','is_superadmin'];
    protected $hidden   = ['password'];        // hide password, not password_hash
    protected $casts    = ['is_superadmin' => 'bool'];

public function setPasswordAttribute($value)
{
    if (empty($value)) {
        return;
    }

    // If it's already a bcrypt/argon hash, keep as-is (prevents double-hash)
    if (is_string($value) && (Str::startsWith($value, '$2y$') || Str::startsWith($value, '$argon2'))) {
        $this->attributes['password'] = $value;
        return;
    }

    // Otherwise hash the plain text
    $this->attributes['password'] = Hash::make($value);
}

    // ---------- Core relations ----------
    public function orgMemberships()
    {
        return $this->hasMany(OrgMember::class, 'user_id');
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'org_members', 'user_id', 'tenant_id')
                    ->withPivot('status','joined_at');
    }

    public function roles()
    {
        // roles across tenants; filter by pivot tenant_id as needed
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
                    ->withPivot('tenant_id');
    }

    public function rolesForTenant(?int $tenantId = null)
    {
        $tenantId = $tenantId ?? (app()->bound('tenant.id') ? app('tenant.id') : null);
        return $this->roles()->wherePivot('tenant_id', $tenantId);
    }
}

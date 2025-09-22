<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'plans';

    protected $fillable = ['name','price','period','limits_json'];

    protected $casts = [
        'price' => 'decimal:2',
        'limits_json' => 'array',
        'is_active'   => 'boolean',
    ];

       public function scopeActive($q) {
        return $q->where('is_active', true);
    }

    public $timestamps = true;

    public function tenants() { return $this->hasMany(Tenant::class, 'plan_id'); }
}

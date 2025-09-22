<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToTenant;

/**
 * Base for core tables that have a tenant_id column.
 * Adds a global scope on tenant_id and fills tenant_id on create.
 */
abstract class BaseCoreTenantModel extends Model
{
    use BelongsToTenant;

    // Core uses the default 'mysql' connection
    public $timestamps = false;     // set true in children that use timestamps
    protected $guarded = [];        // relax for speed; tighten as needed
}

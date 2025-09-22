<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    public function getConnectionName()
    {
        return app('tenant.conn'); // 'tenant' or 'domain_shared'
    }
}

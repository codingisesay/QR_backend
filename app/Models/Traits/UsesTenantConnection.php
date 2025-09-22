<?php
// app/Models/Traits/UsesTenantConnection.php
namespace App\Models\Traits;

trait UsesTenantConnection
{
    public function getConnectionName()
    {
        // set by middleware/commands; fallback is 'tenant'
        return app()->bound('tenant.conn') ? app('tenant.conn') : 'tenant';
    }
}

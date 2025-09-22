<?php
// app/Models/Traits/BelongsToTenant.php
namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            if (app()->bound('tenant.id') && empty($model->tenant_id)) {
                $model->tenant_id = app('tenant.id');
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            if (app()->bound('tenant.id')) {
                $builder->where(
                    $builder->getModel()->getTable().'.tenant_id',
                    app('tenant.id')
                );
            }
        });
    }
}

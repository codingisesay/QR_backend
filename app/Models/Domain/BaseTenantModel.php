<?php
// app/Models/Domain/BaseTenantModel.php
namespace App\Models\Domain;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\UsesTenantConnection;

abstract class BaseTenantModel extends Model
{
    use UsesTenantConnection;

    public $timestamps = false;   // enable per child if the table has timestamps
    protected $guarded = [];      // tighten as needed
}

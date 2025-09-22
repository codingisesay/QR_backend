<?php
// app/Models/DomainShared/BaseSharedModel.php
namespace App\Models\DomainShared;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToTenant;

abstract class BaseSharedModel extends Model
{
    use BelongsToTenant;

    protected $connection = 'domain_shared';
    public $timestamps = false;   // enable per child if the table has timestamps
    protected $guarded = [];      // tighten as needed
}

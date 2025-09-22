<?php
// app/Models/Billing/BaseBillingModel.php
namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;

abstract class BaseBillingModel extends Model
{
    // If you keep billing tables in a dedicated 'core' connection:
    // protected $connection = 'core'; // <-- change to your core connection name
    // If everything is in default connection, remove this property.
}

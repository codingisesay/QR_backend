<?php
// app/Models/Domain/Product.php
namespace App\Models\Domain;

class Product extends BaseTenantModel
{
    protected $table = 't_products';
    protected $fillable = ['sku','name','description'];

    public function batches(){ return $this->hasMany(ProductBatch::class, 'product_id'); }
    public function qrCodes(){ return $this->hasMany(QrCode::class, 'product_id'); }
    public function printRuns(){ return $this->hasMany(PrintRun::class, 'product_id'); }
    public function scans(){ return $this->hasMany(ScanEvent::class, 'product_id'); }
}

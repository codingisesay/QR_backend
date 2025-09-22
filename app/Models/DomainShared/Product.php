<?php
// app/Models/DomainShared/Product.php
namespace App\Models\DomainShared;

class Product extends BaseSharedModel
{
    protected $table = 'products_s';
    protected $fillable = ['tenant_id','sku','name','description'];

    public function batches() { return $this->hasMany(ProductBatch::class, 'product_id'); }
    public function qrCodes() { return $this->hasMany(QrCode::class, 'product_id'); }
    public function printRuns(){ return $this->hasMany(PrintRun::class, 'product_id'); }
    public function scans()   { return $this->hasMany(ScanEvent::class, 'product_id'); }
}

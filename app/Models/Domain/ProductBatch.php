<?php
// app/Models/Domain/ProductBatch.php
namespace App\Models\Domain;

class ProductBatch extends BaseTenantModel
{
    protected $table = 't_product_batches';
    protected $fillable = ['product_id','batch_code','mfg_date','exp_date','quantity_planned'];
    protected $casts = ['mfg_date'=>'date','exp_date'=>'date'];

    public function product(){ return $this->belongsTo(Product::class, 'product_id'); }
    public function qrCodes(){ return $this->hasMany(QrCode::class, 'batch_id'); }
    public function scans()  { return $this->hasMany(ScanEvent::class, 'batch_id'); }
}

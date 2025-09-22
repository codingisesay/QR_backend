<?php
// app/Models/DomainShared/ProductBatch.php
namespace App\Models\DomainShared;

class ProductBatch extends BaseSharedModel
{
    protected $table = 'product_batches_s';
    protected $fillable = ['tenant_id','product_id','batch_code','mfg_date','exp_date','quantity_planned'];
    protected $casts = ['mfg_date'=>'date','exp_date'=>'date'];

    public function product(){ return $this->belongsTo(Product::class, 'product_id'); }
    public function qrCodes(){ return $this->hasMany(QrCode::class, 'batch_id'); }
    public function scans()  { return $this->hasMany(ScanEvent::class, 'batch_id'); }
}

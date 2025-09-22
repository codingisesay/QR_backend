<?php
// app/Models/Domain/ScanEvent.php
namespace App\Models\Domain;

class ScanEvent extends BaseTenantModel
{
    protected $table = 't_scan_events';
    protected $fillable = [
        'qr_id','token','result','product_id','batch_id','app_id','device_hash','ip','ua',
        'lat','lon','reason_code','meta_json','created_at'
    ];
    protected $casts = ['created_at'=>'datetime','lat'=>'float','lon'=>'float','meta_json'=>'array'];

    public function qr()     { return $this->belongsTo(QrCode::class, 'qr_id'); }
    public function product(){ return $this->belongsTo(Product::class, 'product_id'); }
    public function batch()  { return $this->belongsTo(ProductBatch::class, 'batch_id'); }
}

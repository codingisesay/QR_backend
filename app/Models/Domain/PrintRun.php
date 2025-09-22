<?php
// app/Models/Domain/PrintRun.php
namespace App\Models\Domain;

class PrintRun extends BaseTenantModel
{
    protected $table = 't_print_runs';
    protected $fillable = ['product_id','batch_id','channel_id','vendor_name','reel_start','reel_end','qty_planned','created_at'];
    protected $casts = ['created_at'=>'datetime'];

    public function product(){ return $this->belongsTo(Product::class, 'product_id'); }
    public function batch()  { return $this->belongsTo(ProductBatch::class, 'batch_id'); }
    public function channel(){ return $this->belongsTo(QrChannel::class, 'channel_id'); }
    public function qrCodes(){ return $this->hasMany(QrCode::class, 'print_run_id'); }
}

<?php
// app/Models/Domain/QrCode.php
namespace App\Models\Domain;

class QrCode extends BaseTenantModel
{
    protected $table = 't_qr_codes';
    protected $fillable = [
        'token','token_ver','status','version','product_id','batch_id','channel_id','print_run_id',
        'micro_chk','watermark_hash','issued_at','activated_at','voided_at','expires_at'
    ];
    protected $casts = [
        'issued_at'=>'datetime','activated_at'=>'datetime','voided_at'=>'datetime','expires_at'=>'datetime'
    ];

    public function product(){ return $this->belongsTo(Product::class, 'product_id'); }
    public function batch()  { return $this->belongsTo(ProductBatch::class, 'batch_id'); }
    public function channel(){ return $this->belongsTo(QrChannel::class, 'channel_id'); }
    public function printRun(){ return $this->belongsTo(PrintRun::class, 'print_run_id'); }
    public function scans() { return $this->hasMany(ScanEvent::class, 'qr_id'); }
}

<?php
// app/Models/DomainShared/ChainBatch.php
namespace App\Models\DomainShared;

class ChainBatch extends BaseSharedModel
{
    protected $table = 'chain_batches_s';
    protected $fillable = ['tenant_id','print_run_id','merkle_root','created_at'];
    protected $casts = ['created_at'=>'datetime'];

    public function printRun(){ return $this->belongsTo(PrintRun::class, 'print_run_id'); }
    public function anchors(){ return $this->hasMany(ChainAnchor::class, 'chain_batch_id'); }
}

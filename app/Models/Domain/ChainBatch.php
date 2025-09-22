<?php
// app/Models/Domain/ChainBatch.php
namespace App\Models\Domain;

class ChainBatch extends BaseTenantModel
{
    protected $table = 't_chain_batches';
    protected $fillable = ['print_run_id','merkle_root','created_at'];
    protected $casts = ['created_at'=>'datetime'];

    public function printRun(){ return $this->belongsTo(PrintRun::class, 'print_run_id'); }
    public function anchors(){ return $this->hasMany(ChainAnchor::class, 'chain_batch_id'); }
}

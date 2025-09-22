<?php
// app/Models/Domain/ChainAnchor.php
namespace App\Models\Domain;

class ChainAnchor extends BaseTenantModel
{
    protected $table = 't_chain_anchors';
    protected $fillable = ['chain_batch_id','chain','tx_hash','anchored_at'];
    protected $casts = ['anchored_at'=>'datetime'];

    public function batch(){ return $this->belongsTo(ChainBatch::class, 'chain_batch_id'); }
}

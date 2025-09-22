<?php
// app/Models/DomainShared/ChainAnchor.php
namespace App\Models\DomainShared;

class ChainAnchor extends BaseSharedModel
{
    protected $table = 'chain_anchors_s';
    protected $fillable = ['tenant_id','chain_batch_id','chain','tx_hash','anchored_at'];
    protected $casts = ['anchored_at'=>'datetime'];

    public function batch(){ return $this->belongsTo(ChainBatch::class, 'chain_batch_id'); }
}

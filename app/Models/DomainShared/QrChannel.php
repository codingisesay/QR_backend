<?php
// app/Models/DomainShared/QrChannel.php
namespace App\Models\DomainShared;

class QrChannel extends BaseSharedModel
{
    protected $table = 'qr_channels_s';
    protected $fillable = ['tenant_id','code','name'];

    public function qrCodes()  { return $this->hasMany(QrCode::class, 'channel_id'); }
    public function printRuns(){ return $this->hasMany(PrintRun::class, 'channel_id'); }
}

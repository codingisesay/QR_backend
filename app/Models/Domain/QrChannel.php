<?php
// app/Models/Domain/QrChannel.php
namespace App\Models\Domain;

class QrChannel extends BaseTenantModel
{
    protected $table = 't_qr_channels';
    protected $fillable = ['code','name'];

    public function qrCodes(){ return $this->hasMany(QrCode::class, 'channel_id'); }
    public function printRuns(){ return $this->hasMany(PrintRun::class, 'channel_id'); }
}

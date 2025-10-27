<?php
namespace App\Services;

class SunVerifier {
  // return uppercase hex string of CMAC( keyHex, uidHex||ctr )
  public function computeCmac(string $keyHex, string $uidHex, int $ctr): string {
    $key = hex2bin($keyHex);
    $msg = hex2bin(strtoupper($uidHex)) . pack('N', $ctr);
    $cmacBin = \YourCmacLib::aesCmac($key, $msg); // implement using phpseclib or a small cmac package
    return strtoupper(bin2hex($cmacBin));
  }
}

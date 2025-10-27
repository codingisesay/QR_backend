<?php
namespace App\Services;

class PufVerifier {
  // Example: compare supplied fingerprint to stored hash or call vendor API
  public function verify(array $opts): array {
    // $opts: ['tenant_id'=>.., 'puf_alg'=>.., 'threshold'=>0.85, 'image'=>..., 'stored_hash'=>...]
    // return ['verdict'=>'match'|'low_score'|'no_capture'|'not_enrolled', 'score'=>float|null];
    // Implement by calling external API or local matcher; for now, stub:
    return ['verdict'=>'no_capture','score'=>null];
  }
}

<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;


return new class extends Migration {
public function up(): void
{
$schema = DB::connection('domain_shared')->getSchemaBuilder();
$schema->table('qr_codes_s', function (Blueprint $t) use ($schema) {
// Add columns if missing (safe to re-run)
if (! $schema->hasColumn('qr_codes_s', 'token_hash')) {
$t->char('token_hash', 64)->nullable()->after('token')->index();
}
if (! $schema->hasColumn('qr_codes_s', 'human_code')) {
$t->string('human_code', 32)->nullable()->after('token_hash');
}
if (! $schema->hasColumn('qr_codes_s', 'micro_alg')) {
$t->string('micro_alg', 32)->default('HMAC-SHA256')->after('micro_chk');
}
if (! $schema->hasColumn('qr_codes_s', 'watermark_alg')) {
$t->string('watermark_alg', 32)->default('HMAC-SHA256')->after('watermark_hash');
}
if (! $schema->hasColumn('qr_codes_s', 'watermark_ver')) {
$t->unsignedSmallInteger('watermark_ver')->default(1)->after('watermark_alg');
}
});


// Backfill token_hash for existing rows
DB::connection('domain_shared')->table('qr_codes_s')
->whereNull('token_hash')
->orderBy('id')
->chunkById(1000, function ($rows) {
foreach ($rows as $r) {
if (! empty($r->token)) {
DB::connection('domain_shared')->table('qr_codes_s')
->where('id', $r->id)
->update(['token_hash' => hash('sha256', $r->token)]);
}
}
});
}


public function down(): void
{
$schema = DB::connection('domain_shared')->getSchemaBuilder();
$schema->table('qr_codes_s', function (Blueprint $t) use ($schema) {
if ($schema->hasColumn('qr_codes_s', 'token_hash')) {
$t->dropColumn('token_hash');
}
if ($schema->hasColumn('qr_codes_s', 'human_code')) {
$t->dropColumn('human_code');
}
if ($schema->hasColumn('qr_codes_s', 'micro_alg')) {
$t->dropColumn('micro_alg');
}
if ($schema->hasColumn('qr_codes_s', 'watermark_alg')) {
$t->dropColumn('watermark_alg');
}
if ($schema->hasColumn('qr_codes_s', 'watermark_ver')) {
$t->dropColumn('watermark_ver');
}
});
}
};
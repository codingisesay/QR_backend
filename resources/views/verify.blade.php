{{-- resources/views/verify.blade.php --}}
@php
    // $data (array) and $tenant (object) are expected
    $d = $data ?? [];

    $mask = function (?string $s, int $head = 3, int $tail = 3) {
        if (!$s) return null;
        $s = (string) $s;
        $len = strlen($s);
        return $len <= ($head + $tail) ? $s : substr($s, 0, $head) . '•••' . substr($s, -$tail);
    };
    $safe = function ($v, $fallback = '—') { return isset($v) && $v !== '' ? $v : $fallback; };

    // Tenant name: try multiple fields so we work across schemas
    $tenantName =
        ($tenant->name ?? null)
        ?? ($tenant->display_name ?? null)
        ?? ($tenant->company_name ?? null)
        ?? ($tenant->org_name ?? null)
        ?? ($tenant->title ?? null)
        ?? ($d['tenant'] ?? null);

    $status = $d['status'] ?? 'unknown';
    $tokenMasked = $d['token_masked'] ?? $mask($d['token'] ?? '');

    $micro = $d['micro'] ?? [];
    $microChecked = (bool)($micro['checked'] ?? false);
    $microMatched = (bool)($micro['matched'] ?? false);
    $microExpectedMasked = $micro['expected_masked'] ?? $mask($d['human_code'] ?? null);
    $microProvidedMasked = $micro['provided_masked'] ?? null;

    $printRun = $d['print_run'] ?? null;
    $channel  = $d['channel'] ?? null;
    $batch    = $d['batch'] ?? null;

    $p = $d['product'] ?? null;
    $dev = $d['device'] ?? null;
    $components = $d['components'] ?? [];
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Scan Result</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { --ok:#0a7d33; --warn:#c53030; --dim:#6b7280; --card:#ffffff; --bg:#f7f7fb; --ink:#0f172a; --mono:"ui-monospace",SFMono-Regular,Menlo,Consolas,monospace; }
        html,body { margin:0; padding:0; background:var(--bg); color:var(--ink); font: 16px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji","Segoe UI Emoji"; }
        .wrap { max-width: 980px; margin: 24px auto; padding: 0 16px; }
        h1 { font-size: 28px; margin: 0 0 6px; }
        h2 { font-size: 20px; margin: 0 0 10px; }
        .card { background: var(--card); border-radius: 12px; padding: 16px 18px; margin: 14px 0; box-shadow: 0 1px 2px rgba(0,0,0,.04); border: 1px solid #eef0f4; }
        .muted { color: var(--dim); }
        .mono { font-family: var(--mono); }
        .ok { color: var(--ok); }
        .warn { color: var(--warn); }
        .row { display:flex; gap: 10px; flex-wrap: wrap; align-items:center; }
        .chip { display:inline-block; font-size:12px; padding:4px 8px; background:#eef0f4; border-radius:999px; color:#374151; text-decoration:none; }
        .kv { margin: 6px 0; }
        .kv b { display:inline-block; min-width: 120px; }
        .list { margin: 6px 0 0; padding-left: 18px; }
        .small { font-size: 13px; }
        .input { font: inherit; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; }
        .btn { font: inherit; padding: 8px 12px; border: 1px solid #0f172a; background:#0f172a; color:#fff; border-radius: 8px; cursor:pointer; }
        .btn:disabled { opacity:.6; cursor:not-allowed; }
        .space { height: 6px; }
        @media (max-width:600px){ .kv b { min-width: 100px; } }
    </style>
</head>
<body>
<div class="wrap">

    <div class="card">
        <h1>Scan Result</h1>
        <p class="muted small">Tenant: <b>{{ $tenantName ?: '—' }}</b></p>

        <p class="kv">
            <b>Status:</b>
            @php $statusLower = strtolower((string)$status); @endphp
            @if(in_array($statusLower, ['activated','active','ok']))
                <span class="ok">{{ ucfirst($statusLower) }}</span>
            @elseif(in_array($statusLower, ['void','voided','blocked','revoked','expired']))
                <span class="warn">{{ ucfirst($statusLower) }}</span>
            @else
                <span class="warn">{{ ucfirst($statusLower) }}</span>
                <span class="muted small">(not activated)</span>
            @endif
        </p>

        <p class="kv">
            <b>Token:</b> <span class="mono">{{ $safe($tokenMasked) }}</span>
        </p>

        <div class="space"></div>

        {{-- Micro check summary --}}
        @if ($microChecked)
            <p class="kv">
                <b>Micro check:</b>
                @if ($microMatched)
                    <span class="ok">Matched</span>
                @else
                    <span class="warn">Mismatch</span>
                    @if ($microProvidedMasked)
                        <span class="muted small"> (you entered <span class="mono">{{ $microProvidedMasked }}</span>)</span>
                    @endif
                @endif
            </p>
        @else
            <p class="kv muted">
                <b>Micro check:</b> not provided.
                @if ($microExpectedMasked)
                    Expected (masked): <span class="mono">{{ $microExpectedMasked }}</span>
                @endif
            </p>

            {{-- Optional inline form to submit a micro code for deeper verification --}}
            <form method="get" action="" class="row">
                @foreach(request()->query() as $k => $v)
                    @if($k !== 'hc') <input type="hidden" name="{{ $k }}" value="{{ $v }}"> @endif
                @endforeach
                {{-- <input class="input mono" type="text" name="hc" value="{{ request('hc') }}"
                       maxlength="20" placeholder="Enter micro code (12 char)"> --}}
                        <input class="input mono" type="text" name="hc" value="{{ request('hc') }}"
        maxlength="12" pattern="[0-9A-Z]{12}"
        placeholder="Enter micro code (12 char)">
 <div class="small muted" style="margin-top:4px">
   Tip: we treat I and L as 1, and O as 0.
 </div>
                <button class="btn" type="submit">Check</button>
            </form>
        @endif

        <div class="space"></div>

        {{-- Meta chips --}}
        @if ($printRun)
            <span class="chip">Run: #{{ (int)$printRun }}</span>
        @endif
        @if ($channel)
            <span class="chip">Channel: {{ $channel }}</span>
        @endif
        @if ($batch)
            <span class="chip">Batch: {{ $batch }}</span>
        @endif
    </div>

    {{-- Product --}}
    <div class="card">
        <h2>Product</h2>
        @if ($p)
            <p class="kv"><b>SKU:</b> <span class="mono">{{ $safe($p['sku']) }}</span></p>
            <p class="kv"><b>Name:</b> {{ $safe($p['name']) }}</p>
            <p class="kv muted small">
                Type: {{ $safe($p['type'], 'standard') }} · Status: {{ $safe($p['status'], '—') }}
            </p>
        @else
            <p class="muted">No product is linked to this label.</p>
        @endif
    </div>

    {{-- Device --}}
    <div class="card">
        <h2>Device</h2>
        @if ($dev)
            <p class="kv"><b>Device UID:</b> <span class="mono">{{ $safe($dev['device_uid']) }}</span></p>
            <p class="kv"><b>Status:</b> {{ $safe($dev['status']) }}</p>
            @if (!empty($dev['attrs']) && is_array($dev['attrs']))
                <p class="kv"><b>Attributes:</b></p>
                <ul class="list">
                    @foreach ($dev['attrs'] as $k => $v)
                        <li><span class="mono">{{ $k }}</span>: {{ is_scalar($v) ? e($v) : json_encode($v) }}</li>
                    @endforeach
                </ul>
            @endif
            @if (!empty($dev['bound_at']))
                <p class="muted small">Bound at: {{ $dev['bound_at'] }}</p>
            @endif
        @else
            <p class="muted">This label is not bound to a device yet.</p>
        @endif
    </div>

    {{-- Components --}}
    <div class="card">
        <h2>Components</h2>
        @if (!empty($components))
            <ul class="list">
                @foreach ($components as $c)
                    <li>
                        @if(!empty($c['sku']))
                            <span class="mono">{{ $c['sku'] }}</span> — {{ $safe($c['name']) }}
                        @else
                            {{ $safe($c['name']) }}
                        @endif
                        @if(!empty($c['device_uid']))
                            · Device: <span class="mono">{{ $c['device_uid'] }}</span>
                        @endif
                        @if(isset($c['qty']))
                            · Qty: {{ (float)$c['qty'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="muted">No components linked to this device.</p>
        @endif
    </div>

    <div class="muted small" style="padding:12px 2px 24px;">
        <p>For privacy and security, the token is masked. Operators can view full values in the private console.</p>
    </div>
</div>
</body>
</html>

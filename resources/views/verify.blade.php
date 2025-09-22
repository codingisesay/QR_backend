<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Verify • {{ data_get($data,'product.sku','Unknown') }}</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; background: #f7f7fb; color:#111; }
    .wrap { max-width: 960px; margin: 24px auto; padding: 16px; }
    .card { background: #fff; border-radius: 12px; padding: 16px 20px; box-shadow: 0 2px 10px rgba(0,0,0,.05); margin-bottom: 16px; }
    .h { margin: 0 0 8px 0; }
    .dim { color: #666; }
    .pill { display:inline-block; padding:4px 10px; border-radius: 999px; background:#eef2ff; color:#3742fa; font-size:12px; margin-right:6px; }
    table { width:100%; border-collapse: collapse; }
    th,td { padding: 8px 6px; border-top: 1px solid #eee; text-align:left; }
    .ok { color: #0a7a2a; }
    .warn { color: #b03; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h2 class="h">Scan Result</h2>
      <div class="dim">Tenant: <b>{{ $tenant->slug ?? $tenant->id }}</b></div>

      @if(!data_get($data,'found'))
        <p class="warn">Token not found ({{ data_get($data,'reason','unknown') }}).</p>
      @else
        @php $status = data_get($data,'status'); @endphp
        <p>Status:
          @if($status === 'bound')
            <b class="ok">Bound</b>
          @elseif($status === 'issued')
            <b class="warn">Issued (not activated)</b>
          @else
            <b>{{ strtoupper((string)$status) ?: 'UNKNOWN' }}</b>
          @endif
        </p>
        <p class="mono">Token: {{ data_get($data,'token','—') }}</p>
        <div>
          @if(data_get($data,'channel'))  <span class="pill">Channel: {{ data_get($data,'channel') }}</span> @endif
          @if(data_get($data,'batch'))    <span class="pill">Batch: {{ data_get($data,'batch') }}</span> @endif
          @if(data_get($data,'print_run'))<span class="pill">Run: #{{ data_get($data,'print_run') }}</span> @endif
        </div>
      @endif
    </div>

    <div class="card">
      <h3 class="h">Product</h3>
      @if(data_get($data,'product'))
        <p><b>{{ data_get($data,'product.sku') }}</b> — {{ data_get($data,'product.name') }}</p>
        <p class="dim">
          Type: {{ data_get($data,'product.type','—') }}
          · Status: {{ data_get($data,'product.status','—') }}
        </p>
      @else
        <p class="dim">Unknown product.</p>
      @endif
    </div>

    <div class="card">
      <h3 class="h">Device</h3>
      @if(data_get($data,'device'))
        <p><b>UID</b>: <span class="mono">{{ data_get($data,'device.device_uid','—') }}</span></p>
        @php $attrs = data_get($data,'device.attrs',[]); @endphp
        @if(!empty($attrs))
          <table>
            <thead><tr><th>Attribute</th><th>Value</th></tr></thead>
            <tbody>
              @foreach($attrs as $k => $v)
                <tr><td class="dim">{{ $k }}</td><td>{{ is_array($v) ? json_encode($v) : $v }}</td></tr>
              @endforeach
            </tbody>
          </table>
        @else
          <p class="dim">No attributes recorded.</p>
        @endif
      @else
        <p class="dim">This label is not bound to a device yet.</p>
      @endif
    </div>


    <div class="card">
  <h3 class="h">Components</h3>
  @php
    $comps = data_get($data,'components',[]);
    $cov   = data_get($data,'bom_coverage');
  @endphp

  @if(!empty($comps))
    <table>
      <thead><tr><th>SKU</th><th>Name</th><th>Device UID</th><th>Qty</th></tr></thead>
      <tbody>
        @foreach($comps as $c)
          <tr>
            <td class="mono">{{ data_get($c,'sku') }}</td>
            <td>{{ data_get($c,'name','') }}</td>
            <td class="mono">{{ data_get($c,'device_uid','—') }}</td>
            <td>{{ data_get($c,'qty',1) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @else
    <p class="dim">No components linked to this device.</p>
  @endif

  @if($cov && data_get($cov,'required'))
    <p class="dim" style="margin-top:8px;">
      BOM:
      @php $ok = data_get($cov,'ok',false); @endphp
      <b class="{{ $ok ? 'ok' : 'warn' }}">{{ $ok ? 'Complete' : 'Incomplete' }}</b>
    </p>
  @endif
</div>
  </div>
</body>
</html>

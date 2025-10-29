@php
  // Safe defaults
  $cols      = max(1, (int)($cols ?? 4));
  $rows      = max(1, (int)($rows ?? 7));
  $gapMm     = max(0, (float)($gapMm ?? 2));
  $qrMm      = max(4, (float)($qrMm ?? 32));
  $marginMm  = max(0, (float)($marginMm ?? 10));
  $fontPt    = max(6, (int)($fontPt ?? 9));
  $showText  = (bool)($showText ?? true);
  $showUrl   = (bool)($showUrl ?? false);

  $cellPct = 100 / $cols; // % width per cell
@endphp
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 0; }
    html, body { padding: 0; margin: 0; }
    * { box-sizing: border-box; }

    body { font-family: sans-serif; color: #000; }

    .page {
      padding: {{ $marginMm }}mm;
      page-break-after: always;
    }
    .page:last-child { page-break-after: auto; }

    /* table layout is very stable in dompdf */
    table.layout {
      width: 100%;
      border-collapse: separate;                 /* to allow spacing */
      border-spacing: {{ $gapMm }}mm {{ $gapMm }}mm; /* H | V gaps */
      table-layout: fixed;
    }
    table.layout td {
      vertical-align: top;
      width: {{ number_format($cellPct, 6) }}%;
      padding: 0; /* spacing handled by border-spacing */
    }

    .cell { width: 100%; text-align: center; }
    .qr {
      width: {{ $qrMm }}mm;
      height: {{ $qrMm }}mm;
      display: block;
      margin: 0 auto; /* center */
    }
    .lbl {
      margin-top: 2mm;           /* ensure BELOW the QR */
      font-size: {{ $fontPt }}pt;
      line-height: 1.2;
      word-break: break-word;
      white-space: normal;
    }
    .url {
      margin-top: 1mm;
      font-size: 8pt;
      color: #555;
      word-break: break-all;
    }
  </style>
</head>
<body>

@foreach ($pages as $page)
  <div class="page">
    <table class="layout">
      <tbody>
      @php
        $items = $page;
        $totalCells = $rows * $cols;
        $count = is_countable($items) ? count($items) : 0;
      @endphp

      @for ($r = 0; $r < $rows; $r++)
        <tr>
          @for ($c = 0; $c < $cols; $c++)
            @php
              $idx  = ($r * $cols) + $c;
              $item = $idx < $count ? $items[$idx] : null;
            @endphp

            <td>
              @if ($item)
                <div class="cell">
                  <img class="qr" src="{{ $item['svg_data'] }}" alt="QR">

                  @if ($showText)
                    <div class="lbl">{{ $item['label'] ?? ($item['token'] ?? '') }}</div>
                    @if (!empty($showUrl) && !empty($item['verify_url']))
                      <div class="url">{{ $item['verify_url'] }}</div>
                    @endif
                  @endif
                </div>
              @endif
            </td>
          @endfor
        </tr>
      @endfor
      </tbody>
    </table>
  </div>
@endforeach

</body>
</html>

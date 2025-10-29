@php
  // ensure sane defaults (in case anything is missing)
  $cols     = max(1, (int)($cols ?? 4));
  $rows     = max(1, (int)($rows ?? 7));
  $gapMm    = max(0, (float)($gapMm ?? 2));
  $qrMm     = max(4, (float)($qrMm ?? 32));
  $marginMm = max(0, (float)($marginMm ?? 10));
  $fontPt   = max(6, (int)($fontPt ?? 9));
  $showText = (bool)($showText ?? true);

  // % width per cell
  $cellPct = 100 / $cols;
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">

<style>
  /* page + print setup */
  @page { margin: 0; }
  html, body { padding: 0; margin: 0; }
  * { box-sizing: border-box; }

  body {
    font-family: sans-serif;
    color: #000;
  }

  .page {
    padding: {{ $marginMm }}mm;
    page-break-after: always;
  }
  .page:last-child { page-break-after: auto; }

  /* table layout is much more reliable in dompdf than CSS grid */
  table.layout {
    width: 100%;
    border-collapse: separate; /* to allow spacing */
    border-spacing: {{ $gapMm }}mm {{ $gapMm }}mm; /* horizontal | vertical gaps */
    table-layout: fixed;
  }
  table.layout td {
    vertical-align: top;
    width: {{ number_format($cellPct, 6) }}%;
    padding: 0; /* spacing handled by border-spacing above */
  }

  /* vertical stack inside each cell */
  .cell {
    width: 100%;
    text-align: center;
  }
  .qr {
    width: {{ $qrMm }}mm;
    height: {{ $qrMm }}mm;
    display: block;
    margin: 0 auto; /* center the QR */
  }
  .lbl {
    margin-top: 2mm;                /* gap below the QR */
    font-size: {{ $fontPt }}pt;
    line-height: 1.2;
    word-break: break-word;
    white-space: normal;
  }

  /* optional faint guides for debugging layout — turn on if needed */
  /* .cell { outline: 0.1mm dashed #ccc; } */
</style>
</head>
<body>

@foreach ($pages as $page)
  <div class="page">
    <table class="layout">
      <tbody>
      @php
        // render exactly $rows rows * $cols cols; if fewer items, fill blanks
        $items = $page;
        $totalCells = $rows * $cols;
        $count = is_countable($items) ? count($items) : 0;
      @endphp

      @for ($r = 0; $r < $rows; $r++)
        <tr>
          @for ($c = 0; $c < $cols; $c++)
            @php
              $idx = ($r * $cols) + $c;
              $item = $idx < $count ? $items[$idx] : null;
            @endphp

            <td>
              @if ($item)
                <div class="cell">
                  @if (!empty($item['svg_data']))
                    {{-- inline SVG as data URL --}}
                    <img class="qr" src="{{ $item['svg_data'] }}" alt="QR">
                  @else
                    {{-- fall back to PNG url --}}
                    <img class="qr" src="{{ $item['png_url'] ?? '' }}" alt="QR">
                  @endif

                  @if ($showText)
                    <div class="lbl">
                      {{ $item['label'] ?? ($item['text'] ?? ($item['token'] ?? '')) }}
                    </div>
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

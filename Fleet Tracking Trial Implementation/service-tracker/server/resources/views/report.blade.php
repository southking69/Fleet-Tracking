<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $visitRef }} report</title>
<style>
  @page { margin: 22mm 14mm 18mm 14mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1b2333; margin: 0; }
  h1 { color: #1F3864; font-size: 19px; margin: 0 0 4px 0; }
  h2 { background: #1F3864; color: #ffffff; padding: 4px 8px; font-size: 11.5px; margin: 14px 0 6px 0; }
  table { width: 100%; border-collapse: collapse; }
  table.grid th, table.grid td { border: 1px solid #ccd3df; padding: 4px 6px; text-align: left; vertical-align: top; }
  table.grid th { background: #e8edf5; color: #1b2333; font-weight: bold; }
  table.meta { border-bottom: 3px solid #1F3864; margin-bottom: 10px; }
  table.meta td { vertical-align: top; padding: 0 0 8px 0; }
  table.kv td { padding: 2px 6px 2px 0; vertical-align: top; width: 25%; }
  table.kv td.k { font-weight: bold; width: 17%; }
  table.kv td.v { width: 33%; }
  table.photos td { width: 33.33%; padding: 4px; vertical-align: top; }
  .fig { border: 1px solid #ccd3df; padding: 4px; page-break-inside: avoid; }
  .fig img { width: 100%; height: auto; max-height: 190px; }
  .fig .cap { font-size: 9px; color: #555555; padding-top: 3px; }
  .note { margin-top: 8px; padding: 6px 10px; border: 1px solid #e0a39b; background: #fdf0ee; }
  .right { text-align: right; }
  tr { page-break-inside: avoid; }
  table.sig { margin-top: 34px; }
  table.sig td { width: 31%; border-top: 1px solid #333333; padding-top: 4px; font-size: 10px; }
  table.sig td.gap { width: 3.5%; border-top: 0; }
  .muted { color: #667085; }
</style>
</head>
<body>

@php
  $dash = "\u{2013}";
  $cell = fn ($x) => ($x === null || $x === '') ? '' : $x;
@endphp

<table class="meta">
  <tr>
    <td>
      <h1>Vehicle Service &amp; Damage Report</h1>
      <div>{{ $company }}</div>
      @if ($client)<div>Prepared for: {{ $client }}</div>@endif
    </td>
    <td class="right">
      <div><b>Visit: </b>{{ $visitRef }}</div>
      <div><b>Report date: </b>{{ $reportDate }}</div>
    </td>
  </tr>
</table>

<h2>Vehicle &amp; visit</h2>
<table class="kv">
  @foreach ($kvRows as $pair)
    <tr>
      @foreach ($pair as $kv)
        <td class="k">{{ $kv[0] }}</td>
        <td class="v">{{ $kv[0] === '' ? '' : (($kv[1] === null || $kv[1] === '') ? $dash : $kv[1]) }}</td>
      @endforeach
      @if (count($pair) < 2)<td class="k"></td><td class="v"></td>@endif
    </tr>
  @endforeach
</table>

@if ($opts['vendorTimes'])
  <h2>Time spent at each vendor</h2>
  <table class="grid">
    <thead><tr><th>#</th><th>Vendor</th><th>Arrived</th><th>Left</th><th>Time at vendor (h)</th><th>Approval wait (h)</th><th>Net work (h)</th></tr></thead>
    <tbody>
      @forelse ($stops as $row)
        <tr>@foreach ($row as $c)<td>{{ $cell($c) }}</td>@endforeach</tr>
      @empty
        <tr><td colspan="7" class="muted">No vendor stops recorded</td></tr>
      @endforelse
    </tbody>
  </table>
  @if ($totalVendor !== null)
    <div style="margin-top:4px"><b>Total time at vendors: </b>{{ $totalVendor }} h</div>
  @endif
@endif

<h2>Services carried out</h2>
<table class="grid">
  <thead><tr><th>#</th><th>Service</th><th>Vendor</th><th>Parts replaced</th><th>Odometer (km)</th><th>Next due (km)</th><th>Status at pickup</th></tr></thead>
  <tbody>
    @forelse ($jobs as $row)
      <tr>@foreach ($row as $c)<td>{{ $cell($c) }}</td>@endforeach</tr>
    @empty
      <tr><td colspan="7" class="muted">No services recorded</td></tr>
    @endforelse
  </tbody>
</table>

@if ($opts['overdue'] && count($overdue))
  <div class="note"><b>Service interval note: </b>the vehicle arrived past its previous due mileage for: {{ implode('; ', $overdue) }}.</div>
@endif

<h2>Damage inspection record</h2>
<table class="grid">
  <thead><tr><th>#</th><th>Ref</th><th>Location</th><th>Type</th><th>Severity</th><th>Condition</th><th>Stage</th><th>Recorded</th><th>Photos</th></tr></thead>
  <tbody>
    @forelse ($damages as $row)
      <tr>@foreach ($row as $c)<td>{{ $cell($c) }}</td>@endforeach</tr>
    @empty
      <tr><td colspan="9" class="muted">No damage recorded</td></tr>
    @endforelse
  </tbody>
</table>

@if ($opts['photos'] && count($damagePhotos))
  <h2>Damage photos</h2>
  <table class="photos">
    @foreach (array_chunk($damagePhotos, 3) as $chunk)
      <tr>
        @foreach ($chunk as $p)
          <td><div class="fig"><img src="{{ $p['src'] }}"><div class="cap">{{ $p['caption'] }}</div></div></td>
        @endforeach
        @for ($i = count($chunk); $i < 3; $i++)<td></td>@endfor
      </tr>
    @endforeach
  </table>
@endif

@if ($opts['condPhotos'] && count($condPhotos))
  <h2>Vehicle condition photos (pickup / return)</h2>
  <table class="photos">
    @foreach (array_chunk($condPhotos, 3) as $chunk)
      <tr>
        @foreach ($chunk as $p)
          <td><div class="fig"><img src="{{ $p['src'] }}"><div class="cap">{{ $p['caption'] }}</div></div></td>
        @endforeach
        @for ($i = count($chunk); $i < 3; $i++)<td></td>@endfor
      </tr>
    @endforeach
  </table>
@endif

@if ($opts['petty'] && count($petty))
  <h2>Petty cash spent</h2>
  <table class="grid">
    <thead><tr><th>#</th><th>Date</th><th>Category</th><th>What for</th><th>Paid by</th><th class="right">Amount ({{ $currency }})</th></tr></thead>
    <tbody>
      @foreach ($petty as $row)
        <tr>@foreach ($row as $i => $c)<td @if ($i === 5) class="right" @endif>{{ $cell($c) }}</td>@endforeach</tr>
      @endforeach
    </tbody>
  </table>
  <div class="right" style="margin-top:4px"><b>Total petty cash: </b>{{ $pettyTotal }} {{ $currency }}</div>
@endif

@if ($notes)
  <h2>Notes</h2>
  <div>{{ $notes }}</div>
@endif

<table class="sig">
  <tr>
    <td>Prepared by ({{ $company ?: 'service provider' }})</td>
    <td class="gap"></td>
    <td>Acknowledged by client</td>
    <td class="gap"></td>
    <td>Date</td>
  </tr>
</table>

</body>
</html>

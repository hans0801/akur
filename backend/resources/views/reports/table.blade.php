@php
    $format = function (array $column, $value) use ($timezone) {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($column['type']) {
            'money' => 'Rp '.number_format((int) $value, 0, ',', '.'),
            'datetime' => \Illuminate\Support\Carbon::parse($value)->setTimezone($timezone)->format('d/m/Y H:i'),
            'date' => \Illuminate\Support\Carbon::parse($value)->format('d/m/Y'),
            default => $value,
        };
    };
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $table['title'] }} {{ $table['unit']['name'] }} — {{ $monthLabel }}</title>
<style>
    @page { size: A4 landscape; margin: 14mm; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #111; background: #fff; margin: 0 auto; padding: 12px; }
    h1 { font-size: 14pt; margin: 0 0 2px; }
    .sub { color: #444; margin: 0 0 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #ccc; padding: 4px; text-align: left; vertical-align: top; }
    th { background: #f2f2f2; }
    td.n { text-align: right; white-space: nowrap; }
    .foot { color: #555; font-size: 9pt; margin-top: 16px; }
    .aksi { text-align: center; margin: 20px 0; }
    @media print { .aksi { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<h1>{{ $table['title'] }} — {{ $table['unit']['name'] }}</h1>
<p class="sub">{{ $monthLabel }}. {{ $table['subtitle'] }}</p>
<table>
    <tr>@foreach($table['columns'] as $c)<th>{{ $c['label'] }}</th>@endforeach</tr>
    @forelse($table['rows'] as $row)
        <tr>
            @foreach($table['columns'] as $c)
                <td class="{{ in_array($c['type'], ['money', 'number'], true) ? 'n' : '' }}">{{ $format($c, $row[$c['key']] ?? null) }}</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($table['columns']) }}">Tidak ada data.</td></tr>
    @endforelse
</table>
<p class="foot">Dicetak dari AKUR pada {{ $printedAt }}.</p>
<div class="aksi"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
</body>
</html>

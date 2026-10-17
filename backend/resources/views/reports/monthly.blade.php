@php
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laporan Kas {{ $report['unit']['name'] }} — {{ $monthLabel }}</title>
<style>
    @page { size: A4; margin: 18mm 20mm; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #111; background: #fff; max-width: 170mm; margin: 0 auto; padding: 12px; }
    h1 { font-size: 15pt; margin: 0 0 2px; }
    .sub { color: #444; margin: 0 0 16px; }
    table { width: 100%; border-collapse: collapse; margin: 6px 0 16px; }
    th, td { border-bottom: 1px solid #ccc; padding: 5px 4px; text-align: left; }
    td.n, th.n { text-align: right; white-space: nowrap; }
    tr.total td { font-weight: bold; border-top: 2px solid #111; }
    h2 { font-size: 12pt; margin: 14px 0 0; }
    .foot { color: #555; font-size: 9pt; margin-top: 24px; }
    .aksi { text-align: center; margin: 20px 0; }
    .aksi button { font-size: 11pt; padding: 8px 18px; }
    @media print { .aksi { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<h1>Laporan Kas {{ $report['unit']['name'] }}</h1>
<p class="sub">Periode {{ $monthLabel }}</p>

<table>
    <tr><td>Saldo awal</td><td class="n">{{ $rp($report['opening_balance']) }}</td></tr>
    <tr><td>Total pemasukan</td><td class="n">{{ $rp($report['total_income']) }}</td></tr>
    <tr><td>Total pengeluaran</td><td class="n">({{ $rp($report['total_expense']) }})</td></tr>
    <tr class="total"><td>Saldo akhir</td><td class="n">{{ $rp($report['closing_balance']) }}</td></tr>
</table>

<h2>Pemasukan</h2>
<table>
    @forelse($report['income'] as $row)
        <tr><td>{{ $row['category'] }}</td><td class="n">{{ $rp($row['amount']) }}</td></tr>
    @empty
        <tr><td colspan="2">-</td></tr>
    @endforelse
</table>

<h2>Pengeluaran</h2>
<table>
    @forelse($report['expense'] as $row)
        <tr><td>{{ $row['category'] }}</td><td class="n">{{ $rp($row['amount']) }}</td></tr>
    @empty
        <tr><td colspan="2">-</td></tr>
    @endforelse
</table>

@if($report['dues'] !== [])
<h2>Iuran jatuh tempo bulan ini</h2>
<table>
    <tr><th>Iuran</th><th class="n">Keluarga lunas</th><th class="n">Terkumpul</th><th class="n">Tagihan</th></tr>
    @foreach($report['dues'] as $d)
        <tr>
            <td>{{ $d['dues_type'] }} {{ $d['period_code'] }}</td>
            <td class="n">{{ $d['paid_households'] }} / {{ $d['households'] }}</td>
            <td class="n">{{ $rp($d['total_paid']) }}</td>
            <td class="n">{{ $rp($d['total_due']) }}</td>
        </tr>
    @endforeach
</table>
@endif

<p class="foot">Dicetak dari AKUR pada {{ $printedAt }}. Angka berasal dari buku kas; koreksi tercatat sebagai transaksi pembalik.</p>
<div class="aksi"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
</body>
</html>

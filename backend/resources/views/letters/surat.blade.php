@php
    $parent = $unit->parent;
    $genders = ['male' => 'Laki-laki', 'female' => 'Perempuan'];
    $fieldValue = function (array $field) use ($request, $formatDate) {
        $value = $request->form_data[$field['key']] ?? null;
        if ($value === null || $value === '') {
            return '-';
        }

        return $field['type'] === 'date' ? $formatDate(\Illuminate\Support\Carbon::parse($value)) : $value;
    };
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $request->documentType->name }} — {{ $request->letter_number }}</title>
<style>
    @page { size: A4; margin: 20mm 22mm; }
    body { font-family: "Times New Roman", Times, serif; font-size: 12pt; line-height: 1.5; color: #000; background: #fff; max-width: 170mm; margin: 0 auto; padding: 12px; }
    .kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 6px; margin-bottom: 18px; }
    .kop h1 { font-size: 15pt; margin: 0; letter-spacing: .5px; }
    .kop p { margin: 2px 0 0; font-size: 10.5pt; }
    .judul { text-align: center; margin-bottom: 14px; }
    .judul h2 { font-size: 13pt; text-decoration: underline; margin: 0; text-transform: uppercase; }
    table.data { margin: 8px 0 8px 24px; border-collapse: collapse; }
    table.data td { vertical-align: top; padding: 1px 6px 1px 0; }
    table.data td:first-child { width: 170px; }
    .ttd { margin-top: 36px; margin-left: auto; width: 230px; text-align: center; }
    .ttd .nama { margin-top: 70px; font-weight: bold; text-decoration: underline; }
    .aksi { text-align: center; margin: 24px 0; }
    .aksi button { font-size: 12pt; padding: 8px 18px; }
    @media print { .aksi { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="kop">
    <h1>{{ strtoupper($unit->name) }}@if($parent) — {{ strtoupper($parent->name) }}@endif</h1>
    @if($unit->address)<p>{{ $unit->address }}</p>@endif
</div>

<div class="judul">
    <h2>{{ $request->documentType->name }}</h2>
    <div>Nomor: {{ $request->letter_number }}</div>
</div>

<p>Yang bertanda tangan di bawah ini, Ketua {{ $unit->name }}@if($parent) {{ $parent->name }}@endif, menerangkan bahwa:</p>

<table class="data">
    <tr><td>Nama</td><td>: {{ $resident->full_name }}</td></tr>
    <tr><td>NIK</td><td>: {{ $nik ?? '-' }}</td></tr>
    <tr><td>Tempat/Tgl. Lahir</td><td>: {{ $resident->birth_place ?? '-' }}, {{ $formatDate($resident->birth_date) }}</td></tr>
    <tr><td>Jenis Kelamin</td><td>: {{ $genders[$resident->gender] ?? '-' }}</td></tr>
    <tr><td>Status Perkawinan</td><td>: {{ $resident->marital_status ?? '-' }}</td></tr>
    <tr><td>Pekerjaan</td><td>: {{ $resident->occupation ?? '-' }}</td></tr>
    <tr><td>Alamat</td><td>: {{ $resident->household?->address_text ?? '-' }}</td></tr>
</table>

<p>adalah benar warga {{ $unit->name }}@if($parent) {{ $parent->name }}@endif.@if($fields !== []) Dengan keterangan sebagai berikut:@endif</p>

@if($fields !== [])
<table class="data">
    @foreach($fields as $field)
        <tr><td>{{ $field['label'] }}</td><td>: {{ $fieldValue($field) }}</td></tr>
    @endforeach
</table>
@endif

<p>Surat ini diberikan untuk keperluan: <strong>{{ $request->purpose }}</strong>.</p>
<p>Demikian surat ini dibuat untuk dipergunakan sebagaimana mestinya.</p>

<div class="ttd">
    <div>{{ $formatDate($request->issued_at) }}</div>
    <div>Ketua {{ $unit->name }}</div>
    <div class="nama">{{ $signerName ?? '(....................................)' }}</div>
</div>

<div class="aksi"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
</body>
</html>

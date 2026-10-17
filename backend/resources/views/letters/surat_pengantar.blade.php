{{-- Surat pengantar (dompdf) mengikuti "[Template]Surat Pengantar.docx": Letter, Arial/Helvetica 11pt, margin 1 inci. --}}
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Surat Pengantar {{ $number }}</title>
<style>
    @page { size: letter portrait; margin: 0.45in 1in 0.9in 1in; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; line-height: 1.15; color: #000; margin: 0; }
    .kop { width: 100%; border-collapse: collapse; margin-bottom: 30pt; }
    .kop td { vertical-align: middle; padding: 0; }
    .kop .logo { width: 1.1in; }
    .kop .logo img { width: 1.01in; height: auto; }
    .kop .text { font-weight: bold; font-size: 13pt; line-height: 1.25; padding-left: 10pt; }
    .kop .text .title { font-size: 13pt; }
    .judul { text-align: center; margin: 0 0 28pt; }
    .judul .nama { font-weight: bold; text-decoration: underline; }
    p { margin: 0 0 12pt; }
    .data { border-collapse: collapse; margin: 6pt 0 24pt; }
    .data td { padding: 1.5pt 0; vertical-align: top; }
    .data .label { width: 1.85in; }
    .data .sep { width: 0.2in; }
    .ttd { width: 100%; border-collapse: collapse; margin-top: 22pt; }
    .ttd td { width: 50%; vertical-align: top; padding: 0; }
    .ttd .kanan { padding-left: 0.6in; }
    .ttd .ruang { height: 0.95in; position: relative; }
    .ttd .ruang img { max-height: 0.95in; max-width: 2.2in; }
    .contoh { position: fixed; top: -0.3in; right: 0; font-size: 9pt; font-weight: bold; color: #b00020; border: 1pt solid #b00020; padding: 2pt 6pt; }
</style>
</head>
<body>
@if($sample)
    <div class="contoh">CONTOH — bukan surat resmi</div>
@endif

<table class="kop">
    <tr>
        <td class="logo"><img src="{{ $logo }}" alt=""></td>
        <td class="text">
            <div class="title">{{ $kopTitle }}</div>
            @if($kopRegion !== '')<div>{{ $kopRegion }}</div>@endif
            @if($kopCity !== '')<div>{{ $kopCity }}</div>@endif
        </td>
    </tr>
</table>

<div class="judul">
    <div class="nama">SURAT PENGANTAR</div>
    <div>Nomor: {{ $number }}</div>
</div>

<p>Saya yang bertanda tangan di bawah ini atas nama Ketua RT. {{ $unitPair }}@if($regionText !== '') {{ $regionText }}@endif, menerangkan kepada:</p>

<table class="data">
    @foreach($rows as [$label, $value])
        <tr><td class="label">{{ $label }}</td><td class="sep">:</td><td>{{ $value }}</td></tr>
    @endforeach
</table>

<p>Demikian surat pengantar ini kami berikan guna proses tindak lanjut ke tingkat selanjutnya.</p>

<table class="ttd">
    <tr>
        <td></td>
        <td class="kanan">{{ $city ? $city.', ' : '' }}{{ $date }}</td>
    </tr>
    <tr>
        <td>&nbsp;</td>
        <td class="kanan">&nbsp;</td>
    </tr>
    <tr>
        <td>Ketua RW {{ $rwCode }}</td>
        <td class="kanan">Hormat Kami</td>
    </tr>
    <tr>
        <td></td>
        <td class="kanan">Pengurus RT.{{ $unitPair }}</td>
    </tr>
    <tr>
        <td class="ruang">@if($rwSignature)<img src="{{ $rwSignature }}" alt="">@endif</td>
        <td class="ruang kanan">@if($rtSignature)<img src="{{ $rtSignature }}" alt="">@endif</td>
    </tr>
    <tr>
        <td>({{ $rwSigner ?: str_repeat('.', 30) }})</td>
        <td class="kanan">({{ $rtSigner ?: str_repeat('.', 30) }})</td>
    </tr>
</table>
</body>
</html>

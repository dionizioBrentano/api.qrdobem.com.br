<?php
$meta_color = config('qrdobem.print_batch.meta_color');
$cut_color = config('qrdobem.print_batch.cut_color');
$code_color = config('qrdobem.print_batch.code_color');
$num_color = config('qrdobem.print_batch.num_color');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>QR do Bem — {{ $title }}</title>
<style>
    /* Margem de 10mm: abaixo disso a maioria das impressoras
       domésticas corta o conteúdo da borda. */
    @page { size: A4; margin: 10mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; }
    header { margin-bottom: 6mm; }
    h1 { font-size: 14pt; margin: 0 0 2mm; }
    .meta { font-size: 9pt; color: {{ $meta_color }}; }
    .sheet {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 4mm;
    }
    .card {
        border: 1px dashed {{ $cut_color }};   /* guia de corte */
        padding: 3mm;
        text-align: center;
        /* Impede que uma etiqueta seja partida entre duas folhas. */
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .qr svg { width: 100%; height: auto; display: block; }
    .code { font-family: monospace; font-size: 8pt; color: {{ $code_color }}; margin-top: 1mm; }
    .num  { font-size: 7pt; color: {{ $num_color }}; }
    .noprint { margin-bottom: 5mm; }
    @media print { .noprint { display: none; } }
</style>
</head>
<body>
    <div class="noprint">
        <button onclick="window.print()">Imprimir</button>
    </div>
    <header>
        <h1>{{ $title }}</h1>
        <div class="meta">{{ $spaceName }} &middot; {{ $batchQuantity }} etiquetas &middot; gerado em {{ $generated }}</div>
    </header>
    <div class="sheet">{!! $cards !!}</div>
</body>
</html>

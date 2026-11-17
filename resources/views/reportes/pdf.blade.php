@php
    use App\Support\Reportes\ExportarPdfReporte;
    use App\Support\Reportes\TablaReporte;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $documento->titulo }}</title>
    <style>
        @page { margin: 28px 30px 44px 30px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1f2933; margin: 0; }
        .header { display: table; width: 100%; margin-bottom: 12px; }
        .header .left, .header .right { display: table-cell; vertical-align: top; }
        .header .right { text-align: right; width: 42%; }
        .empresa { font-size: 15px; font-weight: bold; color: #0f4c81; }
        .muted { color: #64748b; }
        .doc-title { font-size: 15px; font-weight: bold; color: #0f4c81; }
        .filtros { border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 9px; margin-bottom: 10px; }
        .filtros span { margin-right: 14px; }
        .filtros strong { color: #0f4c81; }
        h2 { font-size: 11px; color: #0f4c81; margin: 12px 0 5px; text-transform: uppercase; letter-spacing: .4px; page-break-after: avoid; }
        /* El título de una tabla no queda solo al pie de la página. */
        .seccion { page-break-inside: avoid; }
        table { width: 100%; border-collapse: collapse; }
        .resumen td { width: 25%; border: 1px solid #e2e8f0; padding: 5px 7px; vertical-align: top; }
        .resumen .etiqueta { color: #64748b; font-size: 8.5px; }
        .resumen .valor { font-size: 12px; font-weight: bold; }
        .datos th { background: #0f4c81; color: #fff; padding: 5px 5px; text-align: left; font-size: 8.5px; }
        .datos td { padding: 4px 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .datos .num { text-align: right; white-space: nowrap; }
        /* La primera columna es el código o el nombre corto: no se parte. */
        .datos td.clave { white-space: nowrap; }
        .datos tr.totales td { border-top: 2px solid #0f4c81; border-bottom: none; font-weight: bold; background: #f1f5f9; }
        .datos thead { display: table-header-group; }
        .datos tr { page-break-inside: avoid; }
        .vacio { color: #64748b; font-style: italic; padding: 6px 0; }
        .nota { margin-top: 10px; font-size: 8.5px; color: #64748b; }
    </style>
</head>
<body>
    <div class="header">
        <div class="left">
            @if ($empresa->logo_path && file_exists(public_path($empresa->logo_path)))
                <img src="{{ public_path($empresa->logo_path) }}" alt="Logo" style="max-height: 46px; margin-bottom: 4px;">
            @endif
            <div class="empresa">{{ $empresa->nombre }}</div>
            @if ($empresa->nit)
                <div class="muted">NIT: {{ $empresa->nit }}</div>
            @endif
            @php
                $contacto = array_values(array_filter([$empresa->direccion, $empresa->telefono, $empresa->email]));
            @endphp
            @if ($contacto !== [])
                <div class="muted">{{ implode(' · ', $contacto) }}</div>
            @endif
        </div>
        <div class="right">
            <div class="doc-title">{{ $documento->titulo }}</div>
            <div class="muted" style="margin-top: 4px;">
                Generado el {{ $generado->format('d/m/Y') }} a las {{ $generado->format('H:i') }} (hora de Bolivia)
            </div>
        </div>
    </div>

    @if ($documento->filtros !== [])
        <div class="filtros">
            @foreach ($documento->filtros as $filtro)
                <span><strong>{{ $filtro['etiqueta'] }}:</strong> {{ $filtro['valor'] }}</span>
            @endforeach
        </div>
    @endif

    <h2>Resumen</h2>
    <table class="resumen">
        @foreach (array_chunk($documento->resumen, 4) as $fila)
            <tr>
                @foreach ($fila as $dato)
                    <td>
                        <div class="etiqueta">{{ $dato['etiqueta'] }}</div>
                        <div class="valor">{{ ExportarPdfReporte::formatear($dato['valor'], $dato['tipo']) }}</div>
                    </td>
                @endforeach
                @for ($i = count($fila); $i < 4; $i++)
                    <td></td>
                @endfor
            </tr>
        @endforeach
    </table>

    @foreach ($documento->tablas as $tabla)
        <div class="{{ count($tabla->filas) <= 15 ? 'seccion' : '' }}">
        <h2>{{ $tabla->titulo }}</h2>
        @if ($tabla->filas === [])
            <div class="vacio">{{ $tabla->vacio }}</div>
        @else
            <table class="datos">
                <thead>
                    <tr>
                        @foreach ($tabla->columnas as $columna)
                            <th class="{{ TablaReporte::esNumerica($columna['tipo']) ? 'num' : '' }}">{{ $columna['titulo'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tabla->filas as $fila)
                        <tr>
                            @foreach ($tabla->columnas as $indice => $columna)
                                <td class="{{ TablaReporte::esNumerica($columna['tipo']) || $columna['tipo'] === TablaReporte::FECHA ? 'num' : ($indice === 0 && mb_strlen((string) ($fila[0] ?? '')) <= 20 ? 'clave' : '') }}">{{ ExportarPdfReporte::formatear($fila[$indice] ?? null, $columna['tipo']) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    @if ($tabla->totales !== null)
                        <tr class="totales">
                            @foreach ($tabla->columnas as $indice => $columna)
                                @php $valor = $tabla->totales[$indice] ?? null; @endphp
                                <td class="{{ TablaReporte::esNumerica($columna['tipo']) ? 'num' : ($indice === 0 ? 'clave' : '') }}">{{ $valor === null ? '' : ($indice === 0 ? $valor : ExportarPdfReporte::formatear($valor, $columna['tipo'])) }}</td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        @endif
        </div>
    @endforeach

    @if ($documento->nota)
        <div class="nota">{{ $documento->nota }}</div>
    @endif
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $proforma->numero }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; margin: 0; }
        .header { display: table; width: 100%; margin-bottom: 18px; }
        .header .left, .header .right { display: table-cell; vertical-align: top; }
        .header .right { text-align: right; width: 38%; }
        .empresa { font-size: 18px; font-weight: bold; color: #0f4c81; }
        .muted { color: #64748b; }
        .doc-title { font-size: 20px; font-weight: bold; letter-spacing: 1px; color: #0f4c81; }
        .doc-number { font-size: 13px; margin-top: 4px; }
        .box { border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px 12px; margin-bottom: 14px; }
        .box h3 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #0f4c81; }
        table { width: 100%; border-collapse: collapse; }
        .items th { background: #0f4c81; color: #fff; padding: 7px 6px; text-align: left; font-size: 10px; }
        .items td { padding: 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .items td.num, .items th.num { text-align: right; }
        .items tr.total-row td { border-bottom: none; }
        .totals { width: 45%; margin-left: 55%; margin-top: 10px; }
        .totals td { padding: 4px 6px; }
        .totals td.label { color: #475569; }
        .totals td.value { text-align: right; }
        .totals tr.grand td { border-top: 2px solid #0f4c81; font-size: 13px; font-weight: bold; padding-top: 7px; }
        .footer { margin-top: 22px; font-size: 9px; color: #64748b; border-top: 1px solid #cbd5e1; padding-top: 8px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; background: #e2e8f0; font-size: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="left">
            @if ($empresa->logo_path && file_exists(public_path($empresa->logo_path)))
                <img src="{{ public_path($empresa->logo_path) }}" alt="Logo" style="max-height: 56px; margin-bottom: 6px;">
            @endif
            <div class="empresa">{{ $empresa->nombre }}</div>
            @if ($empresa->nit)
                <div class="muted">NIT: {{ $empresa->nit }}</div>
            @endif
            @if ($empresa->direccion)
                <div class="muted">{{ $empresa->direccion }}</div>
            @endif
            @if ($empresa->telefono || $empresa->email)
                <div class="muted">
                    {{ $empresa->telefono }}
                    @if ($empresa->telefono && $empresa->email) &middot; @endif
                    {{ $empresa->email }}
                </div>
            @endif
        </div>
        <div class="right">
            <div class="doc-title">PROFORMA</div>
            <div class="doc-number">{{ $proforma->numero }}</div>
            <div class="badge">{{ $proforma->estado?->etiqueta() }}</div>
            <div class="muted" style="margin-top:6px;">
                Emisión: {{ $proforma->fecha_emision?->format('d/m/Y') }}
                @if ($proforma->fecha_validez)
                    <br>Válida hasta: {{ $proforma->fecha_validez->format('d/m/Y') }}
                @endif
            </div>
        </div>
    </div>

    <div class="box">
        <h3>Cliente</h3>
        <strong>{{ $cliente?->nombre_razon }}</strong>
        <div class="muted">
            @if ($cliente?->nit_ci) NIT/CI: {{ $cliente->nit_ci }} @endif
            @if ($cliente?->telefono) &middot; Tel: {{ $cliente->telefono }} @endif
            @if ($cliente?->email) &middot; {{ $cliente->email }} @endif
            @if ($cliente?->direccion) <br>{{ $cliente->direccion }} @endif
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th style="width: 42%;">Detalle</th>
                <th style="width: 10%;">Unidad</th>
                <th class="num" style="width: 10%;">Cantidad</th>
                <th class="num" style="width: 14%;">P. unitario</th>
                <th class="num" style="width: 18%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $indice => $item)
                <tr>
                    <td>{{ $indice + 1 }}</td>
                    <td>
                        <strong>{{ $item->nombre ?: $item->descripcion }}</strong>
                        @if ($item->descripcion && $item->descripcion !== $item->nombre)
                            <div class="muted">{{ $item->descripcion }}</div>
                        @endif
                        <div class="muted">{{ $item->tipo?->etiqueta() }}</div>
                    </td>
                    <td>{{ $item->unidad_medida }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->cantidad, 3, '.', ','), '0'), '.') }}</td>
                    <td class="num">{{ $simbolo }} {{ number_format((float) $item->precio_unitario, 2, '.', ',') }}</td>
                    <td class="num">{{ $simbolo }} {{ number_format((float) $item->total_linea, 2, '.', ',') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">{{ $simbolo }} {{ number_format((float) $proforma->subtotal, 2, '.', ',') }}</td>
        </tr>
        <tr>
            <td class="label">Descuento</td>
            <td class="value">- {{ $simbolo }} {{ number_format((float) $proforma->descuento, 2, '.', ',') }}</td>
        </tr>
        <tr>
            <td class="label">Impuesto ({{ number_format((float) $proforma->impuesto_tasa, 2, '.', ',') }}%)</td>
            <td class="value">{{ $simbolo }} {{ number_format((float) $proforma->impuesto, 2, '.', ',') }}</td>
        </tr>
        <tr class="grand">
            <td class="label">Total</td>
            <td class="value">{{ $simbolo }} {{ number_format((float) $proforma->total, 2, '.', ',') }} {{ $moneda }}</td>
        </tr>
    </table>

    @if ($proforma->observaciones)
        <div class="box" style="margin-top:18px;">
            <h3>Observaciones</h3>
            {{ $proforma->observaciones }}
        </div>
    @endif

    @if ($empresa->condiciones)
        <div class="box">
            <h3>Condiciones</h3>
            {!! nl2br(e($empresa->condiciones)) !!}
        </div>
    @endif

    <div class="footer">
        Documento generado electrónicamente. Precios expresados en {{ $moneda }}.
        @if ($proforma->aceptada_at)
            Aceptada el {{ $proforma->aceptada_at->format('d/m/Y H:i') }}
            @if ($proforma->aceptador) por {{ $proforma->aceptador->name }} @endif.
        @endif
    </div>
</body>
</html>

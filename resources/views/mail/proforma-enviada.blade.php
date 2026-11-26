<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Proforma {{ $proforma->numero }}</title>
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 4px;font-size:18px;font-weight:bold;color:#0f4c81;">{{ $empresa->nombre }}</p>
                <p style="margin:0 0 20px;font-size:13px;color:#64748b;">Proforma {{ $proforma->numero }}</p>

                <p style="margin:0 0 12px;font-size:14px;">Hola{{ $cliente?->nombre_razon ? ', '.$cliente->nombre_razon : '' }}:</p>
                <p style="margin:0 0 16px;font-size:14px;">Te enviamos la proforma <strong>{{ $proforma->numero }}</strong>. Va adjunta en PDF.</p>

                <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 20px;font-size:14px;border:1px solid #e2e8f0;border-radius:6px;">
                    <tr>
                        <td style="padding:8px 12px;color:#64748b;">Número</td>
                        <td style="padding:8px 12px;text-align:right;font-weight:bold;">{{ $proforma->numero }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 12px;color:#64748b;border-top:1px solid #e2e8f0;">Total</td>
                        <td style="padding:8px 12px;text-align:right;font-weight:bold;border-top:1px solid #e2e8f0;">{{ $total }}</td>
                    </tr>
                    @if ((float) $proforma->descuento_promocion > 0)
                        <tr>
                            <td style="padding:8px 12px;color:#64748b;border-top:1px solid #e2e8f0;">Descuento por promociones</td>
                            <td style="padding:8px 12px;text-align:right;color:#047857;border-top:1px solid #e2e8f0;">- {{ \App\Support\Monedas::simbolo($proforma->moneda ?: 'BOB') }} {{ number_format((float) $proforma->descuento_promocion, 2, ',', '.') }} (ya incluido en el total)</td>
                        </tr>
                        @foreach ($proforma->items->whereNotNull('promocion_tipo') as $linea)
                            <tr>
                                <td style="padding:4px 12px 8px;color:#64748b;font-size:12px;" colspan="2">{{ $linea->nombre ?: $linea->descripcion }}: {{ $linea->promocion_nombre }} ({{ \App\Support\DescuentoPromocion::etiqueta($linea->promocion_tipo, $linea->promocion_valor) }})</td>
                            </tr>
                        @endforeach
                    @endif
                    @if ($validez)
                        <tr>
                            <td style="padding:8px 12px;color:#64748b;border-top:1px solid #e2e8f0;">Válida hasta</td>
                            <td style="padding:8px 12px;text-align:right;border-top:1px solid #e2e8f0;">{{ $validez }}</td>
                        </tr>
                    @endif
                </table>

                <p style="margin:0 0 20px;">
                    <a href="{{ $enlace }}" style="display:inline-block;padding:10px 18px;background:#0f4c81;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:bold;">Ver la proforma en tu portal</a>
                </p>
                <p style="margin:0 0 4px;font-size:12px;color:#64748b;">En tu portal puedes aceptarla o rechazarla. Si el botón no funciona, copia este enlace:</p>
                <p style="margin:0 0 20px;font-size:12px;word-break:break-all;"><a href="{{ $enlace }}" style="color:#0f4c81;">{{ $enlace }}</a></p>

                @php
                    $contacto = array_values(array_filter([$empresa->telefono, $empresa->email]));
                @endphp
                @if ($contacto !== [])
                    <p style="margin:0;font-size:12px;color:#64748b;">¿Dudas? Escríbenos o llámanos: {{ implode(' · ', $contacto) }}</p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>

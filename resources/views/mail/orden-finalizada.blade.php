<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Servicio {{ $orden->numero }} terminado</title>
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 20px;font-size:18px;font-weight:bold;color:#0f4c81;">{{ $empresa->nombre }}</p>
                <p style="margin:0 0 12px;font-size:14px;">Hola{{ $cliente?->nombre_razon ? ', '.$cliente->nombre_razon : '' }}:</p>
                <p style="margin:0 0 20px;font-size:14px;">Terminamos el servicio de la orden <strong>{{ $orden->numero }}</strong>. Gracias por confiar en nosotros.</p>
                <p style="margin:0 0 20px;">
                    <a href="{{ $enlace }}" style="display:inline-block;padding:10px 18px;background:#0f4c81;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:bold;">Ir a mi portal</a>
                </p>
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

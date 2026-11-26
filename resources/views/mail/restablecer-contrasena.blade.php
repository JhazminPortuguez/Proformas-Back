<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Elige una contraseña nueva</title>
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 20px;font-size:18px;font-weight:bold;color:#0f4c81;">{{ $empresa }}</p>
                <p style="margin:0 0 12px;font-size:14px;">Hola, {{ $nombre }}:</p>
                <p style="margin:0 0 16px;font-size:14px;">Recibimos un pedido para cambiar la contraseña de tu cuenta. Para elegir una nueva, entra a este enlace:</p>
                <p style="margin:0 0 20px;">
                    <a href="{{ $enlace }}" style="display:inline-block;padding:10px 18px;background:#0f4c81;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:bold;">Elegir una contraseña nueva</a>
                </p>
                <p style="margin:0 0 4px;font-size:12px;color:#64748b;">El enlace vence en {{ $minutos }} minutos y sirve una sola vez. Si venció, pide otro en "¿Olvidaste tu contraseña?".</p>
                <p style="margin:0 0 4px;font-size:12px;color:#64748b;">Si el botón no funciona, copia este enlace en tu navegador:</p>
                <p style="margin:0 0 20px;font-size:12px;word-break:break-all;"><a href="{{ $enlace }}" style="color:#0f4c81;">{{ $enlace }}</a></p>
                <p style="margin:0;font-size:12px;color:#64748b;">Si tú no lo pediste, ignora este correo: tu contraseña no cambia.</p>
            </td>
        </tr>
    </table>
</body>
</html>

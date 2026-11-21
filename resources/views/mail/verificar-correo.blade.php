<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Verifica tu correo</title>
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 20px;font-size:18px;font-weight:bold;color:#0f4c81;">{{ $empresa }}</p>
                <p style="margin:0 0 12px;font-size:14px;">Hola, {{ $nombre }}:</p>
                <p style="margin:0 0 16px;font-size:14px;">Gracias por registrarte en nuestro portal. Para enviar solicitudes y responder proformas, confirma que este correo es tuyo:</p>
                <p style="margin:0 0 20px;">
                    <a href="{{ $enlace }}" style="display:inline-block;padding:10px 18px;background:#0f4c81;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:bold;">Verificar mi correo</a>
                </p>
                <p style="margin:0 0 4px;font-size:12px;color:#64748b;">El enlace vence en {{ $minutos }} minutos. Si venció, entra a tu portal y pide uno nuevo con "Reenviar enlace".</p>
                <p style="margin:0 0 4px;font-size:12px;color:#64748b;">Si el botón no funciona, copia este enlace en tu navegador:</p>
                <p style="margin:0 0 20px;font-size:12px;word-break:break-all;"><a href="{{ $enlace }}" style="color:#0f4c81;">{{ $enlace }}</a></p>
                <p style="margin:0;font-size:12px;color:#64748b;">Si tú no creaste esta cuenta, ignora este correo.</p>
            </td>
        </tr>
    </table>
</body>
</html>

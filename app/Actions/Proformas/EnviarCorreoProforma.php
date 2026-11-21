<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\TipoProformaEvento;
use App\Mail\ProformaEnviadaMail;
use App\Models\Empresa;
use App\Models\Proforma;
use App\Models\ProformaEvento;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Manda la proforma por correo al cliente, con el PDF adjunto. Se llama
 * después de enviar (ya confirmada la transacción) y con "Reenviar por
 * correo". Nunca deshace el envío: si no hay correo o falla, la proforma
 * queda Enviada y se devuelve un aviso. Cada intento queda en el historial.
 */
final class EnviarCorreoProforma
{
    public const MENSAJE_SIN_EMAIL = 'El cliente no tiene correo registrado; no se envió el correo.';

    public function __construct(private readonly DescargarPdfProforma $pdf) {}

    /**
     * @return array{enviado: bool, mensaje: string}
     */
    public function ejecutar(Proforma $proforma, User $usuario): array
    {
        $proforma->loadMissing('cliente');
        $email = trim((string) $proforma->cliente?->email);

        if ($email === '') {
            $this->registrar($proforma, $usuario, TipoProformaEvento::CorreoSinEmail, null, self::MENSAJE_SIN_EMAIL);

            return ['enviado' => false, 'mensaje' => self::MENSAJE_SIN_EMAIL];
        }

        try {
            $empresa = Empresa::query()->find(EmpresaActual::id()) ?? new Empresa(['nombre' => config('app.name')]);

            Mail::to($email, $proforma->cliente?->nombre_razon)
                ->send(new ProformaEnviadaMail($proforma, $empresa, $this->pdf->generar($proforma)->output()));
        } catch (Throwable $error) {
            Log::error('No se pudo enviar la proforma por correo.', [
                'proforma_id' => $proforma->id,
                'numero' => $proforma->numero,
                'destinatario' => $email,
                'error' => $error->getMessage(),
            ]);

            $mensaje = "No se pudo enviar el correo a {$email}. La proforma quedó Enviada igual; "
                .'revisa la configuración del correo y usa "Reenviar por correo".';

            $this->registrar($proforma, $usuario, TipoProformaEvento::CorreoFallido, $email, $mensaje);

            return ['enviado' => false, 'mensaje' => $mensaje];
        }

        $this->registrar($proforma, $usuario, TipoProformaEvento::CorreoEnviado, $email, null);

        return ['enviado' => true, 'mensaje' => "Correo enviado a {$email}."];
    }

    private function registrar(
        Proforma $proforma,
        User $usuario,
        TipoProformaEvento $tipo,
        ?string $destinatario,
        ?string $detalle,
    ): void {
        ProformaEvento::query()->create([
            'proforma_id' => $proforma->id,
            'tipo' => $tipo,
            'destinatario' => $destinatario,
            'detalle' => $detalle,
            'user_id' => $usuario->id,
        ]);
    }
}

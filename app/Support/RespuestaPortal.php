<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EstadoCliente;
use App\Enums\EstadoProforma;
use App\Models\Cliente;
use App\Models\Proforma;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Reglas del portal para que el cliente acepte o rechace su proforma
 * (P-11 / U-05). Se revisan con la fila bloqueada, dentro de la transacción.
 */
final class RespuestaPortal
{
    public const MENSAJE_INACTIVO = 'Tu cuenta de cliente está inactiva, así que no puedes responder proformas desde el portal. Comunícate con la empresa.';

    /**
     * Motivo por el que el cliente no puede responder (o null si puede).
     * Lo usan el recurso del portal (para el aviso) y las acciones.
     */
    public static function motivoNoPuede(Proforma $proforma, ?Cliente $cliente): ?string
    {
        if ($proforma->estado !== EstadoProforma::Enviada) {
            return null;
        }

        if ($cliente?->estado === EstadoCliente::Inactivo) {
            return self::MENSAJE_INACTIVO;
        }

        if (self::vencida($proforma)) {
            return self::mensajeVencida($proforma);
        }

        return null;
    }

    public static function vencida(Proforma $proforma): bool
    {
        return $proforma->fecha_validez !== null
            && $proforma->fecha_validez->toDateString() < FechaBolivia::hoyTexto();
    }

    public static function mensajeVencida(Proforma $proforma): string
    {
        $fecha = FechaBolivia::formato($proforma->fecha_validez);

        return "Esta proforma venció el {$fecha}. Comunícate con la empresa para actualizarla.";
    }

    /**
     * Revisa que el cliente pueda responder. Si otra persona ya respondió
     * (el personal, o el mismo cliente en otra pestaña), responde 409 con un
     * mensaje claro.
     */
    public static function verificar(Proforma $actual, User $usuario, string $accion): void
    {
        if ($actual->estado !== EstadoProforma::Enviada) {
            self::fallar(409, self::mensajeYaRespondida($actual, $usuario, $accion));
        }

        $cliente = Cliente::query()->find($actual->cliente_id);

        if ($cliente?->estado === EstadoCliente::Inactivo) {
            self::fallar(403, self::MENSAJE_INACTIVO);
        }

        if (self::vencida($actual)) {
            self::fallar(422, self::mensajeVencida($actual), 'fecha_validez');
        }
    }

    private static function mensajeYaRespondida(Proforma $actual, User $usuario, string $accion): string
    {
        $verbo = $accion === 'aceptar' ? 'aceptar' : 'rechazar';

        if ($actual->estado === EstadoProforma::Aceptada) {
            $cuando = self::cuando($actual->aceptada_at);

            return $actual->aceptada_por === $usuario->id
                ? "Ya aceptaste esta proforma {$cuando}. No hace falta hacer nada más."
                : "La empresa ya registró la aceptación de esta proforma {$cuando}, así que no se puede {$verbo}. Recarga la página para ver su estado.";
        }

        if ($actual->estado === EstadoProforma::Rechazada) {
            $cuando = self::cuando($actual->rechazada_at);

            return $actual->rechazada_por === $usuario->id
                ? "Ya rechazaste esta proforma {$cuando}. Si quieres retomarla, comunícate con la empresa."
                : "La empresa ya registró el rechazo de esta proforma {$cuando}, así que no se puede {$verbo}. Si quieres retomarla, comunícate con la empresa.";
        }

        return 'Esta proforma ya no está esperando tu respuesta. Recarga la página para ver su estado.';
    }

    private static function cuando(?CarbonInterface $momento): string
    {
        if ($momento === null) {
            return '';
        }

        $local = $momento->copy()->setTimezone(FechaBolivia::ZONA_HORARIA);

        return 'el '.$local->format('d/m/Y').' a las '.$local->format('H:i');
    }

    private static function fallar(int $estado, string $mensaje, ?string $campo = null): never
    {
        $cuerpo = ['message' => $mensaje];

        if ($campo !== null) {
            $cuerpo['errors'] = [$campo => [$mensaje]];
        }

        throw new HttpResponseException(response()->json($cuerpo, $estado));
    }
}

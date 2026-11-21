<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\CanalRespuesta;
use App\Enums\EstadoCliente;
use App\Enums\EstadoProforma;
use App\Models\Cliente;
use App\Models\Proforma;
use App\Models\User;
use App\Support\RespuestaPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AceptarProforma
{
    /**
     * El personal registra la aceptación (canal interno, también vencida y
     * con aviso) o el cliente acepta desde el portal (P-11 / U-05), con las
     * reglas de `RespuestaPortal`.
     */
    public function ejecutar(Proforma $proforma, User $usuario, CanalRespuesta $canal = CanalRespuesta::Interno): Proforma
    {
        return DB::transaction(function () use ($proforma, $usuario, $canal) {
            $actual = Proforma::query()
                ->whereKey($proforma->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($canal === CanalRespuesta::Portal) {
                RespuestaPortal::verificar($actual, $usuario, 'aceptar');
            }

            if ($actual->estado !== EstadoProforma::Enviada) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede aceptar una proforma enviada.',
                ]);
            }

            $actual->update([
                'estado' => EstadoProforma::Aceptada,
                'aceptada_at' => now(),
                'aceptada_por' => $usuario->id,
                'aceptada_canal' => $canal->value,
                'updated_by' => $usuario->id,
            ]);

            // Un prospecto que acepta su primera proforma pasa a ser cliente activo.
            // Un cliente inactivo no se reactiva solo.
            Cliente::query()
                ->whereKey($actual->cliente_id)
                ->where('estado', EstadoCliente::Prospecto)
                ->update([
                    'estado' => EstadoCliente::Activo,
                    // C-05: también es un cambio del cliente; un formulario
                    // abierto antes no debe pisarlo.
                    'version' => DB::raw('version + 1'),
                    'updated_by' => $usuario->id,
                    'updated_at' => now(),
                ]);

            return $actual->load('cliente', 'items.producto', 'items.servicio', 'aceptador');
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Mail\OrdenFinalizadaMail;
use App\Models\Empresa;
use App\Models\OrdenServicio;
use App\Support\EmpresaActual;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Opcional de la ronda A: avisa por correo al cliente que su orden pasó a
 * Finalizada. Si no tiene correo no hace nada; si el envío falla, lo deja en
 * el log y la orden queda Finalizada igual.
 */
final class AvisarOrdenFinalizada
{
    public function ejecutar(OrdenServicio $orden): bool
    {
        $orden->loadMissing('cliente');
        $email = trim((string) $orden->cliente?->email);

        if ($email === '') {
            return false;
        }

        try {
            $empresa = Empresa::query()->find(EmpresaActual::id()) ?? new Empresa(['nombre' => config('app.name')]);

            Mail::to($email, $orden->cliente?->nombre_razon)->send(new OrdenFinalizadaMail($orden, $empresa));
        } catch (Throwable $error) {
            Log::error('No se pudo avisar por correo que la orden terminó.', [
                'orden_id' => $orden->id,
                'numero' => $orden->numero,
                'destinatario' => $email,
                'error' => $error->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}

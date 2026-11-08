<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Enums\EstadoProforma;
use App\Enums\EstadoSolicitud;
use App\Models\Empresa;
use App\Models\Proforma;
use App\Models\SolicitudCotizacion;
use App\Support\EmpresaActual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PortalInicioController extends PortalController
{
    /**
     * Cantidades por estado real y lo último que se movió (una solicitud o
     * una proforma visible).
     */
    public function resumen(Request $request): JsonResponse
    {
        $porEstadoSolicitud = $this->solicitudes($request)
            ->toBase()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $porEstadoProforma = $this->proformas($request)
            ->toBase()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $solicitudes = [];
        foreach (EstadoSolicitud::cases() as $estado) {
            $solicitudes[$estado->value] = (int) ($porEstadoSolicitud[$estado->value] ?? 0);
        }

        $proformas = [];
        foreach (EstadoProforma::visiblesParaCliente() as $estado) {
            $proformas[$estado->value] = (int) ($porEstadoProforma[$estado->value] ?? 0);
        }

        return response()->json([
            'solicitudes' => $solicitudes,
            'proformas' => $proformas,
            'ultimo' => $this->ultimo($request),
        ]);
    }

    /** Datos de contacto de la empresa, para que el cliente se comunique. */
    public function empresa(): JsonResponse
    {
        $empresa = Empresa::query()->find(EmpresaActual::id());

        return response()->json([
            'nombre' => $empresa?->nombre,
            'telefono' => $empresa?->telefono,
            'email' => $empresa?->email,
            'direccion' => $empresa?->direccion,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ultimo(Request $request): ?array
    {
        $solicitud = $this->solicitudes($request)
            ->latest('created_at')
            ->latest('id')
            ->first();

        $proforma = $this->proformas($request)
            ->orderByRaw('coalesce(rechazada_at, aceptada_at, enviada_at, created_at) desc')
            ->orderByDesc('id')
            ->first();

        $fechaProforma = $proforma ? $this->fechaProforma($proforma) : null;

        if ($proforma !== null && ($solicitud === null || $fechaProforma->greaterThan($solicitud->created_at))) {
            return [
                'tipo' => 'proforma',
                'id' => $proforma->id,
                'numero' => $proforma->numero,
                'descripcion' => null,
                'estado' => $proforma->estado->value,
                'fecha' => $fechaProforma->toISOString(),
            ];
        }

        if ($solicitud instanceof SolicitudCotizacion) {
            return [
                'tipo' => 'solicitud',
                'id' => $solicitud->id,
                'numero' => null,
                'descripcion' => $solicitud->descripcion,
                'estado' => $solicitud->estado->value,
                'fecha' => $solicitud->created_at?->toISOString(),
            ];
        }

        return null;
    }

    /** Fecha del último cambio de estado que el cliente puede ver. */
    private function fechaProforma(Proforma $proforma): Carbon
    {
        return Carbon::parse($proforma->rechazada_at ?? $proforma->aceptada_at ?? $proforma->enviada_at ?? $proforma->created_at);
    }
}

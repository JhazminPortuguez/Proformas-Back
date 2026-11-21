<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Actions\Proformas\AceptarProforma;
use App\Actions\Proformas\DescargarPdfProforma;
use App\Actions\Proformas\RechazarProforma;
use App\Enums\CanalRespuesta;
use App\Enums\EstadoProforma;
use App\Http\Resources\Portal\PortalProformaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Proformas del cliente: solo Enviadas, Aceptadas y Rechazadas. Desde la
 * ronda A (P-11 / U-05) el cliente acepta o rechaza una Enviada que no esté
 * vencida, si su cuenta está activa. Crear la orden sigue siendo tarea del
 * personal.
 */
class PortalProformaController extends PortalController
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in(array_map(
                fn (EstadoProforma $estado) => $estado->value,
                EstadoProforma::visiblesParaCliente(),
            ))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $proformas = $this->proformas($request)
            ->with('primerItem')
            ->withCount('items')
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            PortalProformaResource::collection($proformas->items()),
            $proformas,
        );
    }

    public function show(Request $request, int $id): PortalProformaResource
    {
        $proforma = $this->proformas($request)
            ->with('items', 'primerItem')
            ->whereKey($id)
            ->first() ?? $this->noEncontrado();

        return new PortalProformaResource($proforma);
    }

    public function aceptar(Request $request, int $id, AceptarProforma $aceptar): PortalProformaResource
    {
        $proforma = $this->proformas($request)->whereKey($id)->first() ?? $this->noEncontrado();

        $aceptar->ejecutar($proforma, $request->user(), CanalRespuesta::Portal);

        return $this->respuesta($request, $id);
    }

    public function rechazar(Request $request, int $id, RechazarProforma $rechazar): PortalProformaResource
    {
        $proforma = $this->proformas($request)->whereKey($id)->first() ?? $this->noEncontrado();

        $datos = $request->validate([
            'motivo' => ['nullable', 'string', 'max:1000'],
        ], [
            'motivo.max' => 'El motivo puede tener hasta 1000 caracteres.',
        ]);

        $rechazar->ejecutar($proforma, $datos['motivo'] ?? null, $request->user(), CanalRespuesta::Portal);

        return $this->respuesta($request, $id);
    }

    private function respuesta(Request $request, int $id): PortalProformaResource
    {
        return new PortalProformaResource(
            $this->proformas($request)->with('items', 'primerItem')->whereKey($id)->firstOrFail()
        );
    }

    public function pdf(Request $request, int $id, DescargarPdfProforma $descargar): Response
    {
        $proforma = $this->proformas($request)->whereKey($id)->first() ?? $this->noEncontrado();

        return $descargar->ejecutar($proforma);
    }
}

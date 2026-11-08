<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Actions\Proformas\DescargarPdfProforma;
use App\Enums\EstadoProforma;
use App\Http\Resources\Portal\PortalProformaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Proformas del cliente: solo Enviadas, Aceptadas y Rechazadas. El portal no
 * acepta ni rechaza; para eso el cliente se comunica con la empresa.
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

    public function pdf(Request $request, int $id, DescargarPdfProforma $descargar): Response
    {
        $proforma = $this->proformas($request)->whereKey($id)->first() ?? $this->noEncontrado();

        return $descargar->ejecutar($proforma);
    }
}

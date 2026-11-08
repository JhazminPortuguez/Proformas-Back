<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Actions\Solicitudes\CrearSolicitud;
use App\Enums\EstadoSolicitud;
use App\Http\Requests\Portal\StorePortalSolicitudRequest;
use App\Http\Resources\Portal\PortalSolicitudResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortalSolicitudController extends PortalController
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoSolicitud::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $solicitudes = $this->solicitudes($request)
            ->with('proforma')
            ->withCount('items')
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            PortalSolicitudResource::collection($solicitudes->items()),
            $solicitudes,
        );
    }

    public function show(Request $request, int $id): PortalSolicitudResource
    {
        $solicitud = $this->solicitudes($request)
            ->with('items', 'proforma')
            ->whereKey($id)
            ->first() ?? $this->noEncontrado();

        return new PortalSolicitudResource($solicitud);
    }

    /**
     * El cliente de la solicitud sale de la sesión: si la petición trae un
     * `cliente_id`, se ignora.
     */
    public function store(StorePortalSolicitudRequest $request, CrearSolicitud $crear): JsonResponse
    {
        $datos = $request->validated();
        $datos['cliente_id'] = $this->clienteId($request);

        $solicitud = $crear->ejecutar($datos, $request->user());

        return (new PortalSolicitudResource($solicitud->load('items')))
            ->response()
            ->setStatusCode(201);
    }
}

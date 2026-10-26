<?php

namespace App\Http\Controllers\Api;

use App\Actions\Solicitudes\ActualizarSolicitud;
use App\Actions\Solicitudes\ConvertirSolicitudAProforma;
use App\Actions\Solicitudes\CrearSolicitud;
use App\Actions\Solicitudes\EliminarSolicitud;
use App\Enums\EstadoSolicitud;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSolicitudRequest;
use App\Http\Requests\UpdateSolicitudRequest;
use App\Http\Resources\ProformaResource;
use App\Http\Resources\SolicitudResource;
use App\Models\SolicitudCotizacion;
use App\Support\Busqueda;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class SolicitudController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', SolicitudCotizacion::class);

        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoSolicitud::class)],
            'cliente_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $solicitudes = SolicitudCotizacion::query()
            ->with('cliente', 'items')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino): void {
                    $query->whereRaw(Busqueda::like("coalesce(descripcion, '')"), [$termino])
                        ->orWhereHas('cliente', fn ($query) => $query->whereRaw(Busqueda::like('nombre_razon'), [$termino]));
                });
            })
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->when($filtros['cliente_id'] ?? null, fn ($query, $clienteId) => $query->where('cliente_id', $clienteId))
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            SolicitudResource::collection($solicitudes->items()),
            $solicitudes
        );
    }

    public function store(StoreSolicitudRequest $request, CrearSolicitud $crear)
    {
        $this->authorize('create', SolicitudCotizacion::class);

        $solicitud = $crear->ejecutar($request->validated(), $request->user());

        return (new SolicitudResource($solicitud))->response()->setStatusCode(201);
    }

    public function show(SolicitudCotizacion $solicitud)
    {
        $this->authorize('view', $solicitud);

        return new SolicitudResource($solicitud->load('cliente', 'items', 'proforma'));
    }

    public function update(
        UpdateSolicitudRequest $request,
        SolicitudCotizacion $solicitud,
        ActualizarSolicitud $actualizar
    ) {
        $this->authorize('update', $solicitud);

        return new SolicitudResource(
            $actualizar->ejecutar($solicitud, $request->validated(), $request->user())
        );
    }

    public function destroy(SolicitudCotizacion $solicitud, EliminarSolicitud $eliminar): Response
    {
        $this->authorize('delete', $solicitud);

        $eliminar->ejecutar($solicitud);

        return response()->noContent();
    }

    public function convertir(
        Request $request,
        SolicitudCotizacion $solicitud,
        ConvertirSolicitudAProforma $convertir
    ) {
        $this->authorize('convertir', $solicitud);

        $proforma = $convertir->ejecutar($solicitud, $request->user());

        return (new ProformaResource($proforma))->response()->setStatusCode(201);
    }
}

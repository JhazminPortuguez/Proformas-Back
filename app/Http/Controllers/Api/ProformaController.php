<?php

namespace App\Http\Controllers\Api;

use App\Actions\Proformas\AceptarProforma;
use App\Actions\Proformas\ActualizarProforma;
use App\Actions\Proformas\CrearProforma;
use App\Actions\Proformas\DescargarPdfProforma;
use App\Actions\Proformas\EliminarProforma;
use App\Actions\Proformas\EnviarCorreoProforma;
use App\Actions\Proformas\EnviarProforma;
use App\Actions\Proformas\RechazarProforma;
use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\RechazarProformaRequest;
use App\Http\Requests\StoreProformaRequest;
use App\Http\Requests\UpdateProformaRequest;
use App\Http\Resources\ProformaResource;
use App\Models\Proforma;
use App\Support\Busqueda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ProformaController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Proforma::class);

        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoProforma::class)],
            'cliente_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'sin_orden' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $proformas = Proforma::query()
            ->with('cliente', 'orden')
            ->withCount('items')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino): void {
                    $query->whereRaw(Busqueda::like('numero'), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(observaciones, '')"), [$termino])
                        ->orWhereHas('cliente', fn ($query) => $query->whereRaw(Busqueda::like('nombre_razon'), [$termino]));
                });
            })
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            // "Aceptadas sin orden": aceptadas sin orden vigente (una orden
            // cancelada no cuenta). Crear la orden es tarea del personal.
            ->when($request->boolean('sin_orden'), fn ($query) => $query
                ->where('estado', EstadoProforma::Aceptada)
                ->whereDoesntHave('ordenes', fn ($ordenes) => $ordenes->where('estado', '!=', EstadoOrden::Cancelada)))
            ->when($filtros['cliente_id'] ?? null, fn ($query, $clienteId) => $query->where('cliente_id', $clienteId))
            ->when($filtros['desde'] ?? null, fn ($query, $desde) => $query->whereDate('fecha_emision', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($query, $hasta) => $query->whereDate('fecha_emision', '<=', $hasta))
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            ProformaResource::collection($proformas->items()),
            $proformas
        );
    }

    public function store(StoreProformaRequest $request, CrearProforma $crear)
    {
        $this->authorize('create', Proforma::class);

        $proforma = $crear->ejecutar($request->validated(), $request->user());

        return (new ProformaResource($proforma))->response()->setStatusCode(201);
    }

    public function show(Proforma $proforma)
    {
        $this->authorize('view', $proforma);

        return new ProformaResource($proforma->load('cliente', 'items.producto', 'items.servicio', 'orden', 'aceptador', 'rechazador', 'eventos.usuario'));
    }

    public function update(UpdateProformaRequest $request, Proforma $proforma, ActualizarProforma $actualizar)
    {
        $this->authorize('update', $proforma);

        $proforma = $actualizar->ejecutar($proforma, $request->validated(), $request->user());

        return new ProformaResource($proforma);
    }

    public function destroy(Proforma $proforma, EliminarProforma $eliminar): Response
    {
        $this->authorize('delete', $proforma);

        $eliminar->ejecutar($proforma);

        return response()->noContent();
    }

    /**
     * Envía la proforma y, ya confirmado el cambio de estado, la manda por
     * correo al cliente. `correo` dice si salió o por qué no; la proforma
     * queda Enviada en cualquier caso.
     */
    public function enviar(Request $request, Proforma $proforma, EnviarProforma $enviar, EnviarCorreoProforma $correo)
    {
        $this->authorize('enviar', $proforma);

        $enviada = $enviar->ejecutar($proforma, $request->user());
        $resultado = $correo->ejecutar($enviada, $request->user());

        return $this->conCorreo(new ProformaResource($enviada->load('eventos.usuario')), $resultado);
    }

    public function reenviarCorreo(Request $request, Proforma $proforma, EnviarCorreoProforma $correo)
    {
        $this->authorize('reenviarCorreo', $proforma);

        if ($proforma->estado !== EstadoProforma::Enviada) {
            throw ValidationException::withMessages([
                'estado' => $proforma->estado === EstadoProforma::Borrador
                    ? 'Un borrador no se envía por correo: primero envía la proforma.'
                    : 'Solo se reenvía por correo una proforma Enviada.',
            ]);
        }

        $resultado = $correo->ejecutar($proforma, $request->user());

        return $this->conCorreo(new ProformaResource(
            $proforma->load('cliente', 'items.producto', 'items.servicio', 'orden', 'aceptador', 'rechazador', 'eventos.usuario')
        ), $resultado);
    }

    /**
     * La proforma (plana, como el resto de la API) más `correo`.
     * `additional()` la envolvería en `data`.
     *
     * @param  array{enviado: bool, mensaje: string}  $correo
     */
    private function conCorreo(ProformaResource $recurso, array $correo): JsonResponse
    {
        return response()->json([...$recurso->resolve(request()), 'correo' => $correo]);
    }

    public function aceptar(Request $request, Proforma $proforma, AceptarProforma $aceptar)
    {
        $this->authorize('aceptar', $proforma);

        return new ProformaResource($aceptar->ejecutar($proforma, $request->user()));
    }

    public function rechazar(RechazarProformaRequest $request, Proforma $proforma, RechazarProforma $rechazar)
    {
        $this->authorize('rechazar', $proforma);

        return new ProformaResource(
            $rechazar->ejecutar($proforma, (string) $request->validated('motivo'), $request->user())
        );
    }

    public function pdf(Proforma $proforma, DescargarPdfProforma $descargar)
    {
        $this->authorize('descargarPdf', $proforma);

        return $descargar->ejecutar($proforma);
    }
}

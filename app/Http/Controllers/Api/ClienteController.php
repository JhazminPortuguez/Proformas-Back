<?php

namespace App\Http\Controllers\Api;

use App\Actions\Clientes\ObtenerResumenCliente;
use App\Enums\EstadoCliente;
use App\Enums\EstadoOrden;
use App\Enums\TipoProformaItem;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Http\Resources\HistorialServicioResource;
use App\Models\Cliente;
use App\Models\OrdenItem;
use App\Support\Busqueda;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Cliente::class);

        $filtros = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoCliente::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $porPagina = min(max($request->integer('per_page', 15), 1), 100);

        $clientes = Cliente::query()
            ->withCount(['proformas', 'ordenes'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino) {
                    $query->whereRaw(Busqueda::like('nombre_razon'), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(nit_ci, '')"), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(email, '')"), [$termino]);
                });
            })
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();

        return $this->respuestaPaginada(
            ClienteResource::collection($clientes->items()),
            $clientes
        );
    }

    public function store(StoreClienteRequest $request)
    {
        $this->authorize('create', Cliente::class);

        $cliente = Cliente::create($request->validated());

        return new ClienteResource($cliente);
    }

    public function show(Cliente $cliente)
    {
        $this->authorize('view', $cliente);

        return new ClienteResource($cliente->loadCount(['proformas', 'ordenes']));
    }

    public function resumen(Cliente $cliente, ObtenerResumenCliente $resumen)
    {
        $this->authorize('view', $cliente);

        $cliente->loadCount(['proformas', 'ordenes']);

        return response()->json([
            'cliente' => (new ClienteResource($cliente))->resolve(),
            ...$resumen->ejecutar($cliente),
        ]);
    }

    public function servicios(Cliente $cliente, Request $request)
    {
        $this->authorize('view', $cliente);

        $filtros = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $servicios = OrdenItem::query()
            ->select('orden_items.*')
            ->join('ordenes_servicio', 'ordenes_servicio.id', '=', 'orden_items.orden_id')
            ->where('ordenes_servicio.empresa_id', $cliente->empresa_id)
            ->where('ordenes_servicio.cliente_id', $cliente->getKey())
            ->where('ordenes_servicio.estado', '!=', EstadoOrden::Cancelada->value)
            ->where('orden_items.tipo', TipoProformaItem::Servicio->value)
            ->with([
                'orden:id,numero,estado,fecha_fin,created_at,operario_id',
                'orden.operario:id,name',
                'servicio:id,nombre',
            ])
            ->orderByRaw('coalesce(ordenes_servicio.fecha_fin, ordenes_servicio.created_at) desc')
            ->orderByDesc('orden_items.id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            HistorialServicioResource::collection($servicios->items()),
            $servicios
        );
    }

    public function update(UpdateClienteRequest $request, Cliente $cliente)
    {
        $this->authorize('update', $cliente);

        $cliente->update($request->validated());

        return new ClienteResource($cliente);
    }

    public function destroy(Cliente $cliente)
    {
        $this->authorize('delete', $cliente);

        if ($cliente->proformas()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el cliente porque tiene proformas asociadas.',
            ], 409);
        }

        $cliente->delete();

        return response()->noContent();
    }
}

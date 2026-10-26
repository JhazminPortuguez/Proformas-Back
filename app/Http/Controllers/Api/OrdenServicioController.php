<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ordenes\AsignarOrden;
use App\Actions\Ordenes\CancelarOrden;
use App\Actions\Ordenes\CerrarOrden;
use App\Actions\Ordenes\ConsumirMaterialesOrden;
use App\Actions\Ordenes\CrearOrdenDesdeProforma;
use App\Actions\Ordenes\FinalizarOrden;
use App\Actions\Ordenes\IniciarOrden;
use App\Enums\EstadoOrden;
use App\Enums\Rol;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\AsignarOrdenRequest;
use App\Http\Requests\CancelarOrdenRequest;
use App\Http\Requests\CerrarOrdenRequest;
use App\Http\Requests\ConsumirMaterialesRequest;
use App\Http\Requests\FinalizarOrdenRequest;
use App\Http\Requests\IniciarOrdenRequest;
use App\Http\Requests\StoreOrdenRequest;
use App\Http\Resources\OrdenResource;
use App\Models\OrdenServicio;
use App\Support\Busqueda;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrdenServicioController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', OrdenServicio::class);

        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoOrden::class)],
            'operario_id' => ['nullable', 'integer'],
            'cliente_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $usuario = $request->user();

        $query = OrdenServicio::query()
            ->with('cliente', 'proforma', 'operario', 'cuentaPorCobrar')
            ->withCount('items');

        // Un operario solo puede ver las órdenes que tiene asignadas.
        if ($usuario->rol !== Rol::Admin) {
            $query->where('operario_id', $usuario->id);
        }

        $ordenes = $query
            ->when($request->filled('search'), function ($query) use ($request): void {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino): void {
                    $query->whereRaw(Busqueda::like('numero'), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(observaciones, '')"), [$termino])
                        ->orWhereHas('cliente', fn ($query) => $query->whereRaw(Busqueda::like('nombre_razon'), [$termino]))
                        ->orWhereHas('proforma', fn ($query) => $query->whereRaw(Busqueda::like('numero'), [$termino]))
                        ->orWhereHas('operario', fn ($query) => $query->whereRaw(Busqueda::like('name'), [$termino]));
                });
            })
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->when($filtros['operario_id'] ?? null, fn ($query, $operarioId) => $query->where('operario_id', $operarioId))
            ->when($filtros['cliente_id'] ?? null, fn ($query, $clienteId) => $query->where('cliente_id', $clienteId))
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            OrdenResource::collection($ordenes->items()),
            $ordenes
        );
    }

    public function store(StoreOrdenRequest $request, CrearOrdenDesdeProforma $crear)
    {
        $this->authorize('create', OrdenServicio::class);

        $orden = $crear->ejecutar($request->validated(), $request->user());

        return (new OrdenResource($orden))->response()->setStatusCode(201);
    }

    public function show(OrdenServicio $orden)
    {
        $this->authorize('view', $orden);

        return new OrdenResource($orden->load(
            'cliente',
            'proforma',
            'operario',
            'items.producto',
            'items.servicio',
            'eventos.usuario',
            'cerrador',
            'creador',
            'editor',
            'cuentaPorCobrar',
        )->loadCount('items'));
    }

    public function asignar(AsignarOrdenRequest $request, OrdenServicio $orden, AsignarOrden $asignar)
    {
        $this->authorize('asignar', $orden);

        return new OrdenResource(
            $asignar->ejecutar($orden, (int) $request->validated('operario_id'), $request->user())
        );
    }

    public function iniciar(IniciarOrdenRequest $request, OrdenServicio $orden, IniciarOrden $iniciar)
    {
        $this->authorize('iniciar', $orden);

        return new OrdenResource(
            $iniciar->ejecutar($orden, $request->user(), $request->validated('observaciones'))
        );
    }

    public function finalizar(FinalizarOrdenRequest $request, OrdenServicio $orden, FinalizarOrden $finalizar)
    {
        $this->authorize('finalizar', $orden);

        return new OrdenResource(
            $finalizar->ejecutar($orden, $request->user(), $request->validated('observaciones'))
        );
    }

    public function cancelar(CancelarOrdenRequest $request, OrdenServicio $orden, CancelarOrden $cancelar)
    {
        $this->authorize('cancelar', $orden);

        return new OrdenResource(
            $cancelar->ejecutar($orden, (string) $request->validated('motivo'), $request->user())
        );
    }

    public function cerrar(CerrarOrdenRequest $request, OrdenServicio $orden, CerrarOrden $cerrar)
    {
        $this->authorize('cerrar', $orden);

        return new OrdenResource($cerrar->ejecutar($orden, $request->user()));
    }

    public function consumir(
        ConsumirMaterialesRequest $request,
        OrdenServicio $orden,
        ConsumirMaterialesOrden $consumir,
    ) {
        return new OrdenResource(
            $consumir->ejecutar(
                $orden,
                $request->validated('items') ?? [],
                $request->user(),
            )
        );
    }
}

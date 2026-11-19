<?php

namespace App\Http\Controllers\Api;

use App\Actions\Clientes\ObtenerResumenCliente;
use App\Enums\EstadoCliente;
use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\TipoProformaItem;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Http\Resources\HistorialServicioResource;
use App\Models\Cliente;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use App\Support\Busqueda;
use App\Support\PeriodoReporte;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Cliente::class);

        $filtros = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoCliente::class)],
            // Para los buscadores de documentos nuevos: prospectos y activos.
            'activos' => ['nullable', 'boolean'],
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
            ->when($request->boolean('activos'), fn ($query) => $query->where('estado', '!=', EstadoCliente::Inactivo->value))
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

    /**
     * Trabajo en curso del cliente: proformas enviadas (esperando respuesta)
     * y órdenes pendientes, asignadas o en proceso. El front lo muestra como
     * aviso antes de desactivarlo; desactivar no las cambia.
     */
    public function enCurso(Cliente $cliente)
    {
        $this->authorize('view', $cliente);

        return response()->json([
            'proformas' => Proforma::query()
                ->where('cliente_id', $cliente->id)
                ->where('estado', EstadoProforma::Enviada->value)
                ->orderBy('numero')
                ->pluck('numero')
                ->values(),
            'ordenes' => OrdenServicio::query()
                ->where('cliente_id', $cliente->id)
                ->activas()
                ->orderBy('numero')
                ->pluck('numero')
                ->values(),
        ]);
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

        $datos = $request->validated();
        $version = (int) $datos['version'];

        // C-05, bloqueo optimista: se guarda solo si nadie cambió el cliente
        // desde que se abrió el formulario. La condición y el +1 van en el
        // mismo UPDATE, así dos guardados simultáneos no pueden ganar ambos.
        $actualizadas = Cliente::query()
            ->whereKey($cliente->id)
            ->where('version', $version)
            ->update([
                ...Arr::except($datos, 'version'),
                'version' => DB::raw('version + 1'),
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);

        $actual = $cliente->fresh(['actualizador:id,name']);

        if ($actualizadas === 0) {
            return response()->json([
                'message' => $this->mensajeConflicto($actual),
                'cliente' => new ClienteResource($actual),
            ], 409);
        }

        return new ClienteResource($actual);
    }

    /**
     * "Otro usuario modificó este cliente (Ana Torres, 17/11/2026 10:32)…".
     */
    private function mensajeConflicto(Cliente $cliente): string
    {
        $cuando = $cliente->updated_at
            ?->setTimezone(PeriodoReporte::ZONA_HORARIA)
            ->format('d/m/Y H:i');
        $quien = $cliente->actualizador?->name;
        $detalle = implode(', ', array_filter([$quien, $cuando]));
        $sujeto = $quien ? 'Otro usuario modificó este cliente' : 'Este cliente cambió';

        return $sujeto.($detalle !== '' ? " ({$detalle})" : '')
            .' mientras lo editabas. Recarga para ver sus cambios; los tuyos no se guardaron.';
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

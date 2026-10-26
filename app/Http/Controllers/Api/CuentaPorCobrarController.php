<?php

namespace App\Http\Controllers\Api;

use App\Actions\CuentasPorCobrar\RegistrarPagoCuenta;
use App\Enums\EstadoCobro;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarPagoRequest;
use App\Http\Resources\CuentaPorCobrarResource;
use App\Models\CuentaPorCobrar;
use App\Models\PagoCuenta;
use App\Support\Busqueda;
use App\Support\Dinero;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CuentaPorCobrarController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', CuentaPorCobrar::class);

        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'estado_cobro' => ['nullable', Rule::enum(EstadoCobro::class)],
            'vencida' => ['nullable', 'boolean'],
            'cliente_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $cuentas = CuentaPorCobrar::query()
            ->with([
                'cliente',
                'orden:id,numero,estado',
                'pagosVigentes:id,cuenta_id,monto',
            ])
            ->withCount('pagos')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino): void {
                    $query->whereRaw(Busqueda::like('numero'), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(observaciones, '')"), [$termino])
                        ->orWhereHas('cliente', fn ($query) => $query->whereRaw(Busqueda::like('nombre_razon'), [$termino]))
                        ->orWhereHas('orden', fn ($query) => $query->whereRaw(Busqueda::like('numero'), [$termino]));
                });
            })
            ->when($filtros['estado_cobro'] ?? null, fn ($query, $estado) => $query->where('estado_cobro', $estado))
            ->when($request->boolean('vencida'), function ($query): void {
                $query->where('saldo', '>', 0)
                    ->whereDate('fecha_vencimiento', '<', today()->toDateString());
            })
            ->when($filtros['cliente_id'] ?? null, fn ($query, $clienteId) => $query->where('cliente_id', $clienteId))
            ->when($filtros['desde'] ?? null, fn ($query, $desde) => $query->whereDate('fecha_emision', '>=', Carbon::parse($desde)->toDateString()))
            ->when($filtros['hasta'] ?? null, fn ($query, $hasta) => $query->whereDate('fecha_emision', '<=', Carbon::parse($hasta)->toDateString()))
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            CuentaPorCobrarResource::collection($cuentas->items()),
            $cuentas
        );
    }

    public function resumen()
    {
        $this->authorize('viewAny', CuentaPorCobrar::class);

        $inicioMes = now()->startOfMonth()->toDateString();
        $finMes = now()->endOfMonth()->toDateString();

        $cobradoMes = PagoCuenta::query()
            ->whereNull('anulado_at')
            ->whereBetween('fecha_pago', [$inicioMes, $finMes])
            ->sum('monto');

        return response()->json([
            'cuentas' => CuentaPorCobrar::query()->count(),
            'pendientes' => CuentaPorCobrar::query()->where('saldo', '>', 0)->count(),
            'vencidas' => CuentaPorCobrar::query()
                ->where('saldo', '>', 0)
                ->whereDate('fecha_vencimiento', '<', today()->toDateString())
                ->count(),
            'saldo' => (float) Dinero::redondear(
                (string) (CuentaPorCobrar::query()->sum('saldo') ?? 0)
            ),
            'cobrado_mes' => (float) Dinero::redondear((string) $cobradoMes),
            'monto_total' => (float) Dinero::redondear(
                (string) (CuentaPorCobrar::query()->sum('monto_total') ?? 0)
            ),
        ]);
    }

    public function show(CuentaPorCobrar $cuenta)
    {
        $this->authorize('view', $cuenta);

        return new CuentaPorCobrarResource($this->cargar($cuenta));
    }

    public function registrarPago(
        RegistrarPagoRequest $request,
        CuentaPorCobrar $cuenta,
        RegistrarPagoCuenta $registrar,
    ) {
        $this->authorize('registrarPago', $cuenta);

        $cuenta = $registrar->ejecutar(
            $cuenta,
            $request->safe()->only(['monto', 'metodo', 'fecha_pago', 'referencia', 'observaciones']),
            $request->user(),
        );

        return (new CuentaPorCobrarResource($cuenta))
            ->response()
            ->setStatusCode(201);
    }

    private function cargar(CuentaPorCobrar $cuenta): CuentaPorCobrar
    {
        return $cuenta->load(
            'cliente',
            'orden',
            'pagos.usuario',
            'pagos.anulador',
            'creador',
            'editor',
        )->loadCount('pagos');
    }
}

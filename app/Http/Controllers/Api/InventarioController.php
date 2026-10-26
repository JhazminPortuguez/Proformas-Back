<?php

namespace App\Http\Controllers\Api;

use App\Actions\Inventario\RegistrarAjusteInventario;
use App\Actions\Inventario\RegistrarEntradaInventario;
use App\Enums\TipoMovimientoInventario;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\AjusteInventarioRequest;
use App\Http\Requests\EntradaInventarioRequest;
use App\Http\Resources\MovimientoInventarioResource;
use App\Http\Resources\ProductoResource;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Support\Busqueda;
use App\Support\Dinero;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class InventarioController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Producto::class);

        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'bajo_stock' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $productos = Producto::query()
            ->withCount('movimientos')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino): void {
                    $query->whereRaw(Busqueda::like('nombre'), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(descripcion, '')"), [$termino]);
                });
            })
            ->when($request->has('bajo_stock') && $request->boolean('bajo_stock'), fn ($query) => $query->whereColumn('stock_actual', '<=', 'stock_minimo'))
            ->when($request->has('activo'), fn ($query) => $query->where('activo', $request->boolean('activo')))
            ->orderBy('nombre')
            ->orderBy('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            ProductoResource::collection($productos->items()),
            $productos
        );
    }

    public function resumen()
    {
        $this->authorize('viewAny', Producto::class);

        $valorInventario = Producto::query()
            ->selectRaw('coalesce(sum(stock_actual * costo_promedio), 0) as valor')
            ->value('valor');

        return response()->json([
            'productos' => Producto::query()->count(),
            'bajo_stock' => Producto::query()->whereColumn('stock_actual', '<=', 'stock_minimo')->count(),
            'valor_inventario' => (float) Dinero::redondear($valorInventario),
            'movimientos_mes' => MovimientoInventario::query()
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ]);
    }

    public function movimientos(Request $request)
    {
        $this->authorize('verMovimientos', Producto::class);

        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'producto_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', Rule::enum(TipoMovimientoInventario::class)],
            'orden_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $movimientos = MovimientoInventario::query()
            ->with(['producto', 'orden:id,numero', 'usuario', 'ordenItem'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino): void {
                    $query->whereRaw(Busqueda::like("coalesce(motivo, '')"), [$termino])
                        ->orWhereHas('producto', function ($query) use ($termino): void {
                            $query->whereRaw(Busqueda::like('nombre'), [$termino])
                                ->orWhereRaw(Busqueda::like("coalesce(descripcion, '')"), [$termino]);
                        })
                        ->orWhereHas('orden', fn ($query) => $query->whereRaw(Busqueda::like('numero'), [$termino]));
                });
            })
            ->when($filtros['producto_id'] ?? null, fn ($query, $productoId) => $query->where('producto_id', $productoId))
            ->when($filtros['tipo'] ?? null, fn ($query, $tipo) => $query->where('tipo', $tipo))
            ->when($filtros['orden_id'] ?? null, fn ($query, $ordenId) => $query->where('orden_id', $ordenId))
            ->when($filtros['desde'] ?? null, fn ($query, $desde) => $query->where('created_at', '>=', Carbon::parse($desde)->startOfDay()))
            ->when($filtros['hasta'] ?? null, fn ($query, $hasta) => $query->where('created_at', '<=', Carbon::parse($hasta)->endOfDay()))
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            MovimientoInventarioResource::collection($movimientos->items()),
            $movimientos
        );
    }

    public function entrada(EntradaInventarioRequest $request, RegistrarEntradaInventario $registrar)
    {
        $producto = Producto::query()->findOrFail($request->validated('producto_id'));

        $movimiento = $registrar->ejecutar(
            $producto,
            $request->safe()->only(['cantidad', 'costo_unitario', 'motivo']),
            $request->user(),
        );

        return (new MovimientoInventarioResource($movimiento))
            ->response()
            ->setStatusCode(201);
    }

    public function ajuste(AjusteInventarioRequest $request, RegistrarAjusteInventario $registrar)
    {
        $producto = Producto::query()->findOrFail($request->validated('producto_id'));

        $movimiento = $registrar->ejecutar(
            $producto,
            $request->safe()->only(['stock_nuevo', 'motivo']),
            $request->user(),
        );

        return (new MovimientoInventarioResource($movimiento))
            ->response()
            ->setStatusCode(201);
    }
}

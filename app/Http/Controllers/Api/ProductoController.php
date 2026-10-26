<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoMovimientoInventario;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductoRequest;
use App\Http\Requests\UpdateProductoRequest;
use App\Http\Resources\ProductoResource;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Support\Busqueda;
use App\Support\Dinero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductoController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Producto::class);

        $porPagina = min(max($request->integer('per_page', 15), 1), 100);

        $productos = Producto::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->whereRaw(Busqueda::like('nombre'), [$termino]);
            })
            ->when($request->has('activo'), fn ($query) => $query->where('activo', $request->boolean('activo')))
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();

        return $this->respuestaPaginada(
            ProductoResource::collection($productos->items()),
            $productos
        );
    }

    public function store(StoreProductoRequest $request)
    {
        $this->authorize('create', Producto::class);

        $producto = DB::transaction(function () use ($request): Producto {
            $datos = $request->validated();
            $producto = Producto::create($datos);
            $stock = Dinero::cantidad($datos['stock_actual'] ?? 0);

            if (Dinero::comparar($stock, 0) > 0) {
                MovimientoInventario::create([
                    'empresa_id' => $producto->empresa_id,
                    'producto_id' => $producto->id,
                    'tipo' => TipoMovimientoInventario::Entrada,
                    'cantidad' => $stock,
                    'costo_unitario' => Dinero::redondear($datos['costo_promedio'] ?? 0),
                    'stock_anterior' => '0.000',
                    'stock_nuevo' => $stock,
                    'motivo' => 'Stock inicial del producto',
                    'created_by' => $request->user()->id,
                ]);
            }

            return $producto;
        });

        return new ProductoResource($producto);
    }

    public function show(Producto $producto)
    {
        $this->authorize('view', $producto);

        return new ProductoResource($producto);
    }

    public function update(UpdateProductoRequest $request, Producto $producto)
    {
        $this->authorize('update', $producto);

        $producto->update($request->validated());

        return new ProductoResource($producto);
    }

    public function destroy(Producto $producto)
    {
        $this->authorize('delete', $producto);

        if ($producto->items()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el producto porque está incluido en proformas.',
            ], 409);
        }

        if ($producto->movimientos()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el producto porque tiene movimientos de inventario.',
            ], 409);
        }

        $producto->delete();

        return response()->noContent();
    }
}

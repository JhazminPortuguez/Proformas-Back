<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductoRequest;
use App\Http\Requests\UpdateProductoRequest;
use App\Http\Resources\ProductoResource;
use App\Models\Producto;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Producto::class);

        $porPagina = min(max($request->integer('per_page', 15), 1), 100);

        $productos = Producto::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $termino = '%'.mb_strtolower((string) $request->input('search')).'%';

                $query->whereRaw('lower(nombre) like ?', [$termino]);
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

        $producto = Producto::create($request->validated());

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

        $producto->delete();

        return response()->noContent();
    }
}

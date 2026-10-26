<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServicioRequest;
use App\Http\Requests\UpdateServicioRequest;
use App\Http\Resources\ServicioResource;
use App\Models\Servicio;
use App\Support\Busqueda;
use Illuminate\Http\Request;

class ServicioController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Servicio::class);

        $porPagina = min(max($request->integer('per_page', 15), 1), 100);

        $servicios = Servicio::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino) {
                    $query->whereRaw(Busqueda::like('nombre'), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(descripcion, '')"), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(categoria, '')"), [$termino]);
                });
            })
            ->when($request->has('activo'), fn ($query) => $query->where('activo', $request->boolean('activo')))
            ->when($request->has('visible_catalogo'), fn ($query) => $query->where('visible_catalogo', $request->boolean('visible_catalogo')))
            ->when($request->filled('categoria'), fn ($query) => $query->where('categoria', $request->input('categoria')))
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();

        return $this->respuestaPaginada(
            ServicioResource::collection($servicios->items()),
            $servicios
        );
    }

    public function store(StoreServicioRequest $request)
    {
        $this->authorize('create', Servicio::class);

        $servicio = Servicio::create($request->validated());

        return (new ServicioResource($servicio))->response()->setStatusCode(201);
    }

    public function show(Servicio $servicio)
    {
        $this->authorize('view', $servicio);

        return new ServicioResource($servicio);
    }

    public function update(UpdateServicioRequest $request, Servicio $servicio)
    {
        $this->authorize('update', $servicio);

        $servicio->update($request->validated());

        return new ServicioResource($servicio->fresh());
    }

    public function destroy(Servicio $servicio)
    {
        $this->authorize('delete', $servicio);

        if ($servicio->items()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el servicio porque está incluido en proformas.',
            ], 409);
        }

        $servicio->delete();

        return response()->noContent();
    }
}

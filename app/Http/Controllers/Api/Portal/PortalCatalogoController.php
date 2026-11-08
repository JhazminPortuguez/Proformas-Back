<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Resources\Portal\PortalServicioResource;
use App\Models\Servicio;
use App\Support\Busqueda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de servicios del portal: solo activos y visibles en el catálogo.
 */
class PortalCatalogoController extends PortalController
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $servicios = $this->catalogo()
            ->when($filtros['search'] ?? null, function (Builder $query, string $search): void {
                $termino = Busqueda::patron($search);

                $query->where(function (Builder $query) use ($termino): void {
                    $query->whereRaw(Busqueda::like('nombre'), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(categoria, '')"), [$termino]);
                });
            })
            ->orderBy('categoria')
            ->orderBy('nombre')
            ->paginate($filtros['per_page'] ?? 12)
            ->withQueryString();

        return $this->respuestaPaginada(
            PortalServicioResource::collection($servicios->items()),
            $servicios,
        );
    }

    public function show(int $id): PortalServicioResource
    {
        $servicio = $this->catalogo()->whereKey($id)->first() ?? $this->noEncontrado();

        return new PortalServicioResource($servicio);
    }

    /**
     * @return Builder<Servicio>
     */
    private function catalogo(): Builder
    {
        return Servicio::query()
            ->where('activo', true)
            ->where('visible_catalogo', true);
    }
}

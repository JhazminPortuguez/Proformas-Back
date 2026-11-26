<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarPromocionRequest;
use App\Http\Resources\PromocionResource;
use App\Models\Promocion;
use App\Support\Busqueda;
use App\Support\FechaBolivia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ronda C, parte 1: catálogo de promociones.
 *
 * `vigentes=1` devuelve solo las que se pueden aplicar hoy (para el
 * selector de la proforma). Una promoción usada no se borra: se desactiva.
 */
class PromocionController extends Controller
{
    use RespondePaginado;

    public const MENSAJE_USADA = 'Esta promoción ya se usó en proformas, así que no se puede eliminar. Desactívala para que no se aplique más.';

    public function index(Request $request)
    {
        $this->authorize('viewAny', Promocion::class);

        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['VIGENTE', 'PROGRAMADA', 'VENCIDA', 'INACTIVA'])],
            'vigentes' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $hoy = FechaBolivia::hoyTexto();

        $promociones = Promocion::query()
            ->with('servicio:id,nombre', 'producto:id,nombre')
            ->withCount('lineasProforma')
            ->when($filtros['search'] ?? null, fn ($query, string $search) => $query
                ->whereRaw(Busqueda::like('nombre'), [Busqueda::patron($search)]))
            ->when($request->boolean('vigentes'), fn ($query) => $query->vigentes($hoy))
            ->when($filtros['estado'] ?? null, fn ($query, string $estado) => match ($estado) {
                'VIGENTE' => $query->vigentes($hoy),
                'PROGRAMADA' => $query->where('activa', true)->whereDate('vigente_desde', '>', $hoy),
                'VENCIDA' => $query->where('activa', true)->whereDate('vigente_hasta', '<', $hoy),
                'INACTIVA' => $query->where('activa', false),
            })
            ->orderByDesc('activa')
            ->orderByDesc('vigente_hasta')
            ->orderBy('nombre')
            ->orderBy('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            PromocionResource::collection($promociones->items()),
            $promociones
        );
    }

    public function store(GuardarPromocionRequest $request)
    {
        $promocion = Promocion::create([
            ...$request->validated(),
            'activa' => $request->boolean('activa', true),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return (new PromocionResource($this->cargar($promocion)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Promocion $promocion)
    {
        $this->authorize('view', $promocion);

        return new PromocionResource($this->cargar($promocion));
    }

    /**
     * Editarla no cambia las proformas ya guardadas: cada línea tiene su
     * copia del nombre, tipo y valor.
     */
    public function update(GuardarPromocionRequest $request, Promocion $promocion)
    {
        $promocion->update([
            ...$request->validated(),
            'updated_by' => $request->user()->id,
        ]);

        return new PromocionResource($this->cargar($promocion->fresh()));
    }

    public function destroy(Promocion $promocion): Response|JsonResponse
    {
        $this->authorize('delete', $promocion);

        if ($promocion->estaUsada()) {
            return response()->json(['message' => self::MENSAJE_USADA], 409);
        }

        $promocion->delete();

        return response()->noContent();
    }

    private function cargar(Promocion $promocion): Promocion
    {
        return $promocion->load('servicio:id,nombre', 'producto:id,nombre')->loadCount('lineasProforma');
    }
}

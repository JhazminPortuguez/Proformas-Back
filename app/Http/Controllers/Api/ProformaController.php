<?php

namespace App\Http\Controllers\Api;

use App\Actions\Proformas\ActualizarProforma;
use App\Actions\Proformas\CrearProforma;
use App\Enums\EstadoProforma;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProformaRequest;
use App\Http\Requests\UpdateProformaRequest;
use App\Http\Resources\ProformaResource;
use App\Models\Proforma;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProformaController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Proforma::class);

        $filtros = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoProforma::class)],
            'cliente_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $proformas = Proforma::query()
            ->with('cliente', 'items.producto')
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->when($filtros['cliente_id'] ?? null, fn ($query, $clienteId) => $query->where('cliente_id', $clienteId))
            ->when($filtros['desde'] ?? null, fn ($query, $desde) => $query->whereDate('fecha_emision', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($query, $hasta) => $query->whereDate('fecha_emision', '<=', $hasta))
            ->orderByDesc('id')
            ->paginate($filtros['per_page'] ?? 15)
            ->withQueryString();

        return $this->respuestaPaginada(
            ProformaResource::collection($proformas->items()),
            $proformas
        );
    }

    public function store(StoreProformaRequest $request, CrearProforma $crear)
    {
        $this->authorize('create', Proforma::class);

        $proforma = $crear->ejecutar($request->validated(), $request->user());

        return (new ProformaResource($proforma))->response()->setStatusCode(201);
    }

    public function show(Proforma $proforma)
    {
        $this->authorize('view', $proforma);

        return new ProformaResource($proforma->load('cliente', 'items.producto'));
    }

    public function update(UpdateProformaRequest $request, Proforma $proforma, ActualizarProforma $actualizar)
    {
        $this->authorize('update', $proforma);

        $proforma = $actualizar->ejecutar($proforma, $request->validated(), $request->user());

        return new ProformaResource($proforma);
    }

    public function destroy(Proforma $proforma)
    {
        $this->authorize('delete', $proforma);

        $proforma->delete();

        return response()->noContent();
    }
}

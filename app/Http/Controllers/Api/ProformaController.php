<?php

namespace App\Http\Controllers\Api;

use App\Actions\Proformas\AceptarProforma;
use App\Actions\Proformas\ActualizarProforma;
use App\Actions\Proformas\CrearProforma;
use App\Actions\Proformas\EliminarProforma;
use App\Actions\Proformas\EnviarProforma;
use App\Actions\Proformas\RechazarProforma;
use App\Enums\EstadoProforma;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\RechazarProformaRequest;
use App\Http\Requests\StoreProformaRequest;
use App\Http\Requests\UpdateProformaRequest;
use App\Http\Resources\ProformaResource;
use App\Models\Empresa;
use App\Models\Proforma;
use App\Support\Busqueda;
use App\Support\EmpresaActual;
use App\Support\Monedas;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ProformaController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Proforma::class);

        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoProforma::class)],
            'cliente_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $proformas = Proforma::query()
            ->with('cliente', 'orden')
            ->withCount('items')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino): void {
                    $query->whereRaw(Busqueda::like('numero'), [$termino])
                        ->orWhereRaw(Busqueda::like("coalesce(observaciones, '')"), [$termino])
                        ->orWhereHas('cliente', fn ($query) => $query->whereRaw(Busqueda::like('nombre_razon'), [$termino]));
                });
            })
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

        return new ProformaResource($proforma->load('cliente', 'items.producto', 'items.servicio', 'orden'));
    }

    public function update(UpdateProformaRequest $request, Proforma $proforma, ActualizarProforma $actualizar)
    {
        $this->authorize('update', $proforma);

        $proforma = $actualizar->ejecutar($proforma, $request->validated(), $request->user());

        return new ProformaResource($proforma);
    }

    public function destroy(Proforma $proforma, EliminarProforma $eliminar): Response
    {
        $this->authorize('delete', $proforma);

        $eliminar->ejecutar($proforma);

        return response()->noContent();
    }

    public function enviar(Request $request, Proforma $proforma, EnviarProforma $enviar)
    {
        $this->authorize('enviar', $proforma);

        return new ProformaResource($enviar->ejecutar($proforma, $request->user()));
    }

    public function aceptar(Request $request, Proforma $proforma, AceptarProforma $aceptar)
    {
        $this->authorize('aceptar', $proforma);

        return new ProformaResource($aceptar->ejecutar($proforma, $request->user()));
    }

    public function rechazar(RechazarProformaRequest $request, Proforma $proforma, RechazarProforma $rechazar)
    {
        $this->authorize('rechazar', $proforma);

        return new ProformaResource(
            $rechazar->ejecutar($proforma, (string) $request->validated('motivo'), $request->user())
        );
    }

    public function pdf(Proforma $proforma)
    {
        $this->authorize('descargarPdf', $proforma);

        $proforma->load('cliente', 'items.producto', 'items.servicio', 'aceptador');

        $empresa = Empresa::query()->find(EmpresaActual::id())
            ?? new Empresa(['nombre' => 'Empresa']);

        $pdf = Pdf::loadView('proformas.pdf', [
            'proforma' => $proforma,
            'cliente' => $proforma->cliente,
            'items' => $proforma->items,
            'empresa' => $empresa,
            'moneda' => $proforma->moneda ?: Monedas::POR_DEFECTO,
            'simbolo' => Monedas::simbolo($proforma->moneda ?: Monedas::POR_DEFECTO),
        ])->setPaper('a4');

        return $pdf->download("{$proforma->numero}.pdf");
    }
}

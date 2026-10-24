<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoCliente;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Cliente::class);

        $filtros = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoCliente::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $porPagina = min(max($request->integer('per_page', 15), 1), 100);

        $clientes = Cliente::query()
            ->withCount('proformas')
            ->when($request->filled('search'), function ($query) use ($request) {
                $termino = '%'.mb_strtolower((string) $request->input('search')).'%';

                $query->where(function ($query) use ($termino) {
                    $query->whereRaw('lower(nombre_razon) like ?', [$termino])
                        ->orWhereRaw("lower(coalesce(nit_ci, '')) like ?", [$termino])
                        ->orWhereRaw("lower(coalesce(email, '')) like ?", [$termino]);
                });
            })
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
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

        return new ClienteResource($cliente->loadCount('proformas'));
    }

    public function update(UpdateClienteRequest $request, Cliente $cliente)
    {
        $this->authorize('update', $cliente);

        $cliente->update($request->validated());

        return new ClienteResource($cliente);
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

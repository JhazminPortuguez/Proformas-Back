<?php

namespace App\Http\Controllers\Api;

use App\Enums\Rol;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use RespondePaginado;

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $filtros = $request->validate([
            'rol' => ['nullable', Rule::enum(Rol::class)],
            'activo' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $porPagina = min(max($request->integer('per_page', 15), 1), 100);

        $usuarios = User::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $termino = '%'.mb_strtolower((string) $request->input('search')).'%';

                $query->where(function ($query) use ($termino) {
                    $query->whereRaw('lower(name) like ?', [$termino])
                        ->orWhereRaw('lower(email) like ?', [$termino]);
                });
            })
            ->when($filtros['rol'] ?? null, fn ($query, $rol) => $query->where('rol', $rol))
            ->when($request->has('activo'), fn ($query) => $query->where('activo', $request->boolean('activo')))
            ->orderBy('id')
            ->paginate($porPagina)
            ->withQueryString();

        return $this->respuestaPaginada(
            UserResource::collection($usuarios->items()),
            $usuarios
        );
    }

    public function store(StoreUserRequest $request)
    {
        $this->authorize('create', User::class);

        $datos = $request->validated();
        $datos['activo'] ??= true;

        if (Rol::from((string) $datos['rol']) !== Rol::Cliente) {
            $datos['cliente_id'] = null;
        } elseif (($datos['cliente_id'] ?? null) === null) {
            return response()->json([
                'message' => 'Un usuario con rol cliente debe estar vinculado a un cliente.',
            ], 422);
        }

        $usuario = User::create($datos);

        return (new UserResource($usuario->load('cliente')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        return new UserResource($user->load('cliente'));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->authorize('update', $user);

        $datos = $request->validated();
        $nuevoRol = isset($datos['rol']) ? Rol::from((string) $datos['rol']) : $user->rol;
        $nuevoActivo = array_key_exists('activo', $datos) ? (bool) $datos['activo'] : $user->activo;

        if ($request->user()->is($user)) {
            if ($user->rol === Rol::Admin && $nuevoRol !== Rol::Admin) {
                return response()->json([
                    'message' => 'No puedes quitarte tu propio rol de administrador.',
                ], 422);
            }

            if (! $nuevoActivo) {
                return response()->json([
                    'message' => 'No puedes desactivar tu propia cuenta.',
                ], 422);
            }
        }

        if (
            $user->rol === Rol::Admin
            && ($nuevoRol !== Rol::Admin || ! $nuevoActivo)
            && User::query()->where('rol', Rol::Admin)->count() <= 1
        ) {
            return response()->json([
                'message' => 'No se puede quitar el rol al último administrador.',
            ], 422);
        }

        if ($nuevoRol !== Rol::Cliente) {
            $datos['cliente_id'] = null;
        } elseif (array_key_exists('rol', $datos) && ($datos['cliente_id'] ?? $user->cliente_id) === null) {
            return response()->json([
                'message' => 'Un usuario con rol cliente debe estar vinculado a un cliente.',
            ], 422);
        }

        $user->update($datos);

        return new UserResource($user->fresh()->load('cliente'));
    }

    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        if ($user->rol === Rol::Admin && User::query()->where('rol', Rol::Admin)->count() <= 1) {
            return response()->json([
                'message' => 'No se puede eliminar al último administrador.',
            ], 422);
        }

        $user->delete();

        return response()->noContent();
    }
}

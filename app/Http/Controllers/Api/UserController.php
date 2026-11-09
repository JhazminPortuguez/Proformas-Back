<?php

namespace App\Http\Controllers\Api;

use App\Actions\Users\EliminarUsuario;
use App\Enums\EstadoOrden;
use App\Enums\Rol;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\OrdenServicio;
use App\Models\User;
use App\Support\Busqueda;
use Illuminate\Http\JsonResponse;
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
            ->with('cliente')
            ->when($request->filled('search'), function ($query) use ($request) {
                $termino = Busqueda::patron((string) $request->input('search'));

                $query->where(function ($query) use ($termino) {
                    $query->whereRaw(Busqueda::like('name'), [$termino])
                        ->orWhereRaw(Busqueda::like('email'), [$termino]);
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
            return $this->rechazar('cliente_id', 'Un usuario con rol cliente debe estar vinculado a un cliente.');
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
                return $this->rechazar('rol', 'No puedes quitarte tu propio rol de administrador.');
            }

            if (! $nuevoActivo) {
                return $this->rechazar('activo', 'No puedes desactivar tu propia cuenta.');
            }
        }

        // Solo cuentan los administradores activos: uno inactivo no puede
        // entrar al sistema para reemplazar al último.
        if (
            $user->rol === Rol::Admin
            && $user->activo
            && ($nuevoRol !== Rol::Admin || ! $nuevoActivo)
            && User::query()->where('rol', Rol::Admin)->where('activo', true)->count() <= 1
        ) {
            return $this->rechazar(
                $nuevoActivo ? 'rol' : 'activo',
                'No se puede quitar el rol ni desactivar al último administrador activo.',
            );
        }

        $cambiaRol = $nuevoRol !== $user->rol;
        $desactiva = $user->activo && ! $nuevoActivo;

        if ($user->rol === Rol::Operario && ($cambiaRol || $desactiva)) {
            $ordenes = OrdenServicio::query()
                ->where('operario_id', $user->id)
                ->whereIn('estado', [EstadoOrden::Asignada, EstadoOrden::EnProceso])
                ->orderBy('numero')
                ->pluck('numero');

            if ($ordenes->isNotEmpty()) {
                $una = $ordenes->count() === 1;

                return $this->rechazar(
                    $desactiva ? 'activo' : 'rol',
                    sprintf(
                        'No se puede %s a %s porque tiene %s: %s. %s',
                        $desactiva ? 'desactivar' : 'cambiar de rol',
                        $user->name,
                        $una ? 'una orden asignada o en proceso' : $ordenes->count().' órdenes asignadas o en proceso',
                        $ordenes->implode(', '),
                        $una ? 'Termínala o cancélala primero.' : 'Termínalas o cancélalas primero.',
                    ),
                    ['ordenes' => $ordenes->values()->all()],
                );
            }
        }

        if ($nuevoRol !== Rol::Cliente) {
            $datos['cliente_id'] = null;
        } elseif (array_key_exists('rol', $datos) && ($datos['cliente_id'] ?? $user->cliente_id) === null) {
            return $this->rechazar('cliente_id', 'Un usuario con rol cliente debe estar vinculado a un cliente.');
        }

        $user->update($datos);

        return new UserResource($user->fresh()->load('cliente'));
    }

    /**
     * 422 con el mensaje también como error del campo: el front muestra los
     * errores por campo de un 422 (no el `message` suelto).
     *
     * @param  array<string, mixed>  $extra
     */
    private function rechazar(string $campo, string $mensaje, array $extra = []): JsonResponse
    {
        return response()->json([
            'message' => $mensaje,
            'errors' => [$campo => [$mensaje]],
            ...$extra,
        ], 422);
    }

    public function destroy(User $user, EliminarUsuario $eliminar)
    {
        $this->authorize('delete', $user);

        $eliminar->ejecutar($user);

        return response()->noContent();
    }
}

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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

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

        // Ronda B: el administrador no ve a los superusuarios y el
        // superusuario no ve a los usuarios cliente (no son de su área).
        $visibles = match ($request->user()->rol) {
            Rol::Superusuario => [Rol::Superusuario, Rol::Admin, Rol::Operario],
            default => [Rol::Admin, Rol::Operario, Rol::Cliente],
        };

        $usuarios = User::query()
            ->with('cliente')
            ->whereIn('rol', array_map(fn (Rol $rol) => $rol->value, $visibles))
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
        $rol = Rol::from((string) $datos['rol']);

        abort_unless(
            $request->user()->rol->asigna($rol),
            403,
            $this->mensajeRolNoPermitido($request->user()->rol, $rol, 'crear'),
        );

        if (Rol::from((string) $datos['rol']) !== Rol::Cliente) {
            $datos['cliente_id'] = null;
        } elseif (($datos['cliente_id'] ?? null) === null) {
            return $this->rechazar('cliente_id', 'Un usuario con rol cliente debe estar vinculado a un cliente.');
        }

        $usuario = User::create($datos);

        // Ronda B, parte 0: el usuario cliente verifica su correo igual que
        // en el registro (le llega el enlace). El personal no verifica.
        if ($usuario->rol === Rol::Cliente) {
            $this->enviarEnlaceDeVerificacion($usuario);
        } else {
            $usuario->forceFill(['email_verified_at' => now()])->save();
        }

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
        $actor = $request->user();

        abort_if(
            $nuevoRol !== $user->rol && ! $actor->rol->asigna($nuevoRol),
            403,
            $this->mensajeRolNoPermitido($actor->rol, $nuevoRol, 'asignar'),
        );

        if ($actor->is($user)) {
            if ($nuevoRol !== $user->rol) {
                return $this->rechazar('rol', sprintf('No puedes quitarte tu propio rol de %s.', mb_strtolower($user->rol->etiqueta())));
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

        // Igual que con el administrador: siempre queda un superusuario activo.
        if (
            $user->rol === Rol::Superusuario
            && $user->activo
            && ($nuevoRol !== Rol::Superusuario || ! $nuevoActivo)
            && User::query()->where('rol', Rol::Superusuario)->where('activo', true)->count() <= 1
        ) {
            return $this->rechazar(
                $nuevoActivo ? 'rol' : 'activo',
                'No se puede quitar el rol ni desactivar al último superusuario activo.',
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

    private function mensajeRolNoPermitido(Rol $actor, Rol $rol, string $accion): string
    {
        if ($rol === Rol::Superusuario) {
            return 'El rol de superusuario solo se da con el comando de instalación (php artisan sistema:crear-superusuario).';
        }

        $roles = array_map(fn (Rol $permitido) => mb_strtolower($permitido->etiqueta()), $actor->rolesQueAsigna());

        return sprintf(
            'No puedes %s usuarios con el rol %s. Como %s solo puedes %s %s.',
            $accion,
            $rol->etiqueta(),
            mb_strtolower($actor->etiqueta()),
            $accion === 'crear' ? 'crear' : 'asignar el rol de',
            implode(' o ', $roles),
        );
    }

    /** Si el correo no sale, el usuario queda creado y puede pedir otro desde el portal. */
    private function enviarEnlaceDeVerificacion(User $usuario): void
    {
        try {
            $usuario->sendEmailVerificationNotification();
        } catch (Throwable $error) {
            Log::error('No se pudo enviar el enlace de verificación al usuario creado por el administrador.', [
                'user_id' => $usuario->id,
                'error' => $error->getMessage(),
            ]);
        }
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

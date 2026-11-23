<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Cliente;
use App\Rules\NitCiUnico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ronda B, parte 2: una cuenta creada con Google completa su teléfono y su
 * NIT/CI una sola vez. Fuera de ese caso el cliente sigue sin editar sus
 * datos desde el portal (los cambia la empresa).
 */
class PortalMisDatosController extends Controller
{
    public const MENSAJE_SIN_PENDIENTES = 'Tus datos ya están completos. Para cambiarlos, comunícate con la empresa.';

    public function update(Request $request): UserResource
    {
        abort_unless($request->user()->datos_pendientes, 403, self::MENSAJE_SIN_PENDIENTES);
        $datos = $request->validate([
            'telefono' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'nit_ci' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Za-z\-\s]{4,30}$/'],
        ], [
            'telefono.required' => 'Escribe tu teléfono.',
            'telefono.regex' => 'Escribe un teléfono válido (solo números, espacios, + y guiones).',
            'nit_ci.required' => 'Escribe tu NIT o CI.',
            'nit_ci.regex' => 'Escribe un NIT o CI válido (números y letras, sin otros símbolos).',
        ]);

        $usuario = $request->user();
        $telefono = trim($datos['telefono']);
        $nitCi = trim($datos['nit_ci']);

        DB::transaction(function () use ($usuario, $telefono, $nitCi): void {
            /** @var Cliente $cliente */
            $cliente = Cliente::query()->lockForUpdate()->findOrFail($usuario->cliente_id);

            // C-02 sin revelar a otro cliente: el mensaje no lo nombra.
            $repetido = NitCiUnico::cuenta($nitCi) && Cliente::query()
                ->whereKeyNot($cliente->id)
                ->whereNotNull('nit_ci')
                ->whereRaw("upper(replace(nit_ci, ' ', '')) = ?", [NitCiUnico::normalizar($nitCi)])
                ->exists();

            if ($repetido) {
                throw ValidationException::withMessages([
                    'nit_ci' => 'Ese NIT/CI ya está registrado con otro cliente. Comunícate con la empresa para vincular tu cuenta.',
                ]);
            }

            $cliente->forceFill([
                'telefono' => $telefono,
                'nit_ci' => $nitCi,
                'version' => (int) $cliente->version + 1,
            ])->save();

            $usuario->forceFill(['datos_pendientes' => false])->save();
        });

        return new UserResource($usuario->fresh()->load('cliente'));
    }
}

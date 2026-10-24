<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstadoCliente;
use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Cliente;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $credentials['email'] = Str::lower($credentials['email']);

        $usuario = User::query()->where('email', $credentials['email'])->first();

        if (! $usuario || ! Hash::check($credentials['password'], $usuario->password)) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        if (! $usuario->activo) {
            return response()->json(['message' => 'Tu cuenta está desactivada.'], 403);
        }

        Auth::login($usuario, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->put('empresa_id', $usuario->empresa_id);

        return new UserResource($usuario->load('cliente'));
    }

    public function register(RegisterRequest $request)
    {
        $datos = $request->validated();
        $empresaId = EmpresaActual::id();

        $usuario = DB::transaction(function () use ($datos, $empresaId): User {
            $cliente = Cliente::query()
                ->whereRaw('lower(email) = ?', [$datos['email']])
                ->first();

            if ($cliente === null) {
                $cliente = Cliente::create([
                    'nombre_razon' => $datos['name'],
                    'email' => $datos['email'],
                    'telefono' => $datos['telefono'] ?? null,
                    'estado' => EstadoCliente::Prospecto,
                    'empresa_id' => $empresaId,
                ]);
            } elseif ($cliente->telefono === null && ! empty($datos['telefono'])) {
                $cliente->update(['telefono' => $datos['telefono']]);
            }

            return User::create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'password' => $datos['password'],
                'rol' => Rol::Cliente,
                'activo' => true,
                'cliente_id' => $cliente->id,
                'empresa_id' => $empresaId,
            ]);
        });

        Auth::login($usuario);
        $request->session()->regenerate();
        $request->session()->put('empresa_id', $usuario->empresa_id);

        return (new UserResource($usuario->load('cliente')))
            ->response()
            ->setStatusCode(201);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function user(Request $request)
    {
        return new UserResource($request->user()->load('cliente'));
    }
}

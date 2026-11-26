<?php

namespace App\Http\Requests;

use App\Models\Cliente;
use App\Support\ReglaPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // Ronda C, parte 3: la misma regla que "Cambiar contraseña" y
            // "Olvidé mi contraseña" (8 caracteres, con letras y números).
            'password' => ReglaPassword::reglas(),
            'telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ReglaPassword::mensajes('password', 'La contraseña');
    }

    /**
     * Un correo que ya pertenece a un cliente no se registra solo: así nadie
     * que conozca ese correo puede entrar al portal y ver sus proformas. El
     * acceso lo crea la empresa desde Usuarios.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('email')) {
                    return;
                }

                $email = (string) $this->input('email');

                if (Cliente::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                    $validator->errors()->add(
                        'email',
                        'Este correo ya pertenece a un cliente; pide a la empresa tu acceso.',
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower((string) $this->input('email'))]);
        }
    }
}

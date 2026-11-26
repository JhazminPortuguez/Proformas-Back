<?php

namespace App\Http\Requests;

use App\Enums\Rol;
use App\Models\User;
use App\Support\EmpresaActual;
use App\Support\ReglaPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ReglaPassword::reglas(confirmar: false),
            'rol' => ['required', Rule::enum(Rol::class)],
            'activo' => ['sometimes', 'boolean'],
            'cliente_id' => [
                'nullable',
                'integer',
                Rule::exists('clientes', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
        ];
    }

    /**
     * Ronda C: la misma regla y los mismos mensajes que el registro.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ReglaPassword::mensajes('password', 'La contraseña');
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower((string) $this->input('email'))]);
        }
    }
}

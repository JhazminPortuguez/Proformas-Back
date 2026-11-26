<?php

namespace App\Http\Requests;

use App\Enums\Rol;
use App\Support\EmpresaActual;
use App\Support\ReglaPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'max:255', Password::min(8)->letters()->numbers()],
            'rol' => ['sometimes', 'required', Rule::enum(Rol::class)],
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

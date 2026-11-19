<?php

namespace App\Http\Requests;

use App\Enums\EstadoCliente;
use App\Models\Cliente;
use App\Rules\NitCiUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('cliente')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre_razon' => ['sometimes', 'required', 'string', 'max:200'],
            'nit_ci' => ['nullable', 'string', 'max:30', ...$this->reglaNitUnico()],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'estado' => ['sometimes', 'required', Rule::enum(EstadoCliente::class)],
            // C-05: la versión con la que se abrió el formulario.
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Falta la versión del cliente. Recarga la página e intenta de nuevo.',
            'version.integer' => 'La versión del cliente no es válida. Recarga la página e intenta de nuevo.',
            'version.min' => 'La versión del cliente no es válida. Recarga la página e intenta de nuevo.',
        ];
    }

    /**
     * Solo si el NIT/CI cambia: un cliente antiguo que ya compartía NIT se
     * puede seguir editando.
     *
     * @return list<NitCiUnico>
     */
    private function reglaNitUnico(): array
    {
        $cliente = $this->route('cliente');

        if (! $cliente instanceof Cliente) {
            return [new NitCiUnico];
        }

        $nuevo = $this->input('nit_ci');

        if (! is_string($nuevo) || NitCiUnico::normalizar($nuevo) === NitCiUnico::normalizar($cliente->nit_ci)) {
            return [];
        }

        return [new NitCiUnico($cliente->id)];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower((string) $this->input('email'))]);
        }
    }
}

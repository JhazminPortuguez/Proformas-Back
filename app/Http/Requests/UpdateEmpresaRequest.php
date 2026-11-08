<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Rol;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de la empresa (tabla `empresas`). Solo el administrador.
 * Las longitudes son las de las columnas reales.
 */
class UpdateEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol === Rol::Admin;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200'],
            'nit' => ['nullable', 'string', 'max:30'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'condiciones' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'nit' => 'NIT',
            'telefono' => 'teléfono',
            'email' => 'correo',
            'direccion' => 'dirección',
            'condiciones' => 'condiciones',
        ];
    }

    protected function prepareForValidation(): void
    {
        $limpio = [];

        foreach (['nombre', 'nit', 'telefono', 'email', 'direccion', 'condiciones'] as $campo) {
            if ($this->has($campo)) {
                $valor = $this->input($campo);
                $valor = is_string($valor) ? trim($valor) : $valor;
                $limpio[$campo] = $valor === '' ? null : $valor;
            }
        }

        $this->merge($limpio);
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Servicio;
use Illuminate\Foundation\Http\FormRequest;

class StoreServicioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Servicio::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'precio_base' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'duracion_estimada_min' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'categoria' => ['nullable', 'string', 'max:80'],
            'activo' => ['sometimes', 'boolean'],
            'visible_catalogo' => ['sometimes', 'boolean'],
        ];
    }
}

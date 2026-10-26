<?php

namespace App\Http\Requests;

use App\Models\SolicitudCotizacion;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SolicitudCotizacion::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cliente_id' => [
                'required',
                'integer',
                Rule::exists('clientes', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'descripcion' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.descripcion' => ['required', 'string', 'max:500'],
            'items.*.cantidad' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:100000'],
            'items.*.unidad_medida' => ['nullable', 'string', 'max:30'],
            'items.*.observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }
}

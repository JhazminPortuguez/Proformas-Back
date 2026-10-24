<?php

namespace App\Http\Requests;

use App\Enums\EstadoProforma;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProformaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('proforma')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fechaValidez = ['nullable', 'date'];

        if ($this->filled('fecha_emision')) {
            $fechaValidez[] = 'after_or_equal:fecha_emision';
        }

        return [
            'cliente_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('clientes', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'fecha_emision' => ['sometimes', 'required', 'date'],
            'fecha_validez' => $fechaValidez,
            'estado' => ['sometimes', 'required', Rule::enum(EstadoProforma::class)],
            'descuento' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'impuesto' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'observaciones' => ['nullable', 'string', 'max:2000'],

            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.producto_id' => [
                'required_with:items',
                'integer',
                'distinct',
                Rule::exists('productos', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'items.*.cantidad' => ['required_with:items', 'integer', 'min:1', 'max:100000'],
            'items.*.descripcion' => ['nullable', 'string', 'max:255'],
        ];
    }
}

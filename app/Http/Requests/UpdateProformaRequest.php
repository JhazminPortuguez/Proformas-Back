<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaItemsProforma;
use App\Support\EmpresaActual;
use App\Support\Monedas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProformaRequest extends FormRequest
{
    use ValidaItemsProforma;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('proforma')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('moneda'))) {
            $this->merge(['moneda' => strtoupper($this->input('moneda'))]);
        }
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
            // El estado se cambia exclusivamente con /enviar, /aceptar y /rechazar.
            'estado' => ['prohibited'],
            // El sistema trabaja solo en bolivianos.
            'moneda' => ['sometimes', 'required', 'string', Rule::in([Monedas::POR_DEFECTO])],
            'impuesto_tasa' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'descuento' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'observaciones' => ['nullable', 'string', 'max:2000'],

            ...$this->reglasItems(false),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items');

            if (is_array($items)) {
                $this->validarItemsProforma($validator, $items);
            }
        });
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaItemsProforma;
use App\Models\Proforma;
use App\Support\EmpresaActual;
use App\Support\Monedas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProformaRequest extends FormRequest
{
    use ValidaItemsProforma;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Proforma::class) ?? false;
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
        return [
            'cliente_id' => [
                'required',
                'integer',
                Rule::exists('clientes', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'fecha_emision' => ['required', 'date'],
            'fecha_validez' => ['nullable', 'date', 'after_or_equal:fecha_emision'],
            'moneda' => ['nullable', 'string', Rule::in(Monedas::SOPORTADAS)],
            'impuesto_tasa' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'descuento' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'observaciones' => ['nullable', 'string', 'max:2000'],

            ...$this->reglasItems(true),
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

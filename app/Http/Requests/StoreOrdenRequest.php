<?php

namespace App\Http\Requests;

use App\Models\OrdenServicio;
use App\Rules\OperarioAsignableRule;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OrdenServicio::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'proforma_id' => [
                'required',
                'integer',
                Rule::exists('proformas', 'id')->where('empresa_id', EmpresaActual::id()),
            ],
            'operario_id' => ['nullable', 'integer', new OperarioAsignableRule],
            'fecha_programada' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

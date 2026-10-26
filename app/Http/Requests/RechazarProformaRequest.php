<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RechazarProformaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rechazar', $this->route('proforma')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinalizarOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finalizar', $this->route('orden')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

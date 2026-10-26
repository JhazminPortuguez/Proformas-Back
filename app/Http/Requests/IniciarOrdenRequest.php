<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IniciarOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('iniciar', $this->route('orden')) ?? false;
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

<?php

namespace App\Http\Requests;

use App\Rules\OperarioAsignableRule;
use Illuminate\Foundation\Http\FormRequest;

class AsignarOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('asignar', $this->route('orden')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operario_id' => ['required', 'integer', new OperarioAsignableRule],
        ];
    }
}

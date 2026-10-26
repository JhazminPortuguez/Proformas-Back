<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CerrarOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cerrar', $this->route('orden')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

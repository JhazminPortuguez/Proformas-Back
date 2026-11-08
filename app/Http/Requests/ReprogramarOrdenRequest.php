<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReprogramarOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reprogramar', $this->route('orden')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fecha_programada' => ['required', 'date_format:Y-m-d'],
        ];
    }
}

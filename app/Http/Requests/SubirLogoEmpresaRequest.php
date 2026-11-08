<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Rol;
use Illuminate\Foundation\Http\FormRequest;

/** Logo de la empresa: PNG o JPG de hasta 1 MB. Solo el administrador. */
class SubirLogoEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol === Rol::Admin;
    }

    public function rules(): array
    {
        return [
            // `mimes` mira la extensión y `mimetypes` el contenido real.
            'logo' => [
                'required',
                'file',
                'mimes:png,jpg,jpeg',
                'mimetypes:image/png,image/jpeg',
                'max:1024',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.required' => 'Elige una imagen para el logo.',
            'logo.file' => 'El logo debe ser un archivo de imagen.',
            'logo.mimes' => 'El logo debe ser una imagen PNG o JPG.',
            'logo.mimetypes' => 'El logo debe ser una imagen PNG o JPG.',
            'logo.max' => 'El logo no puede pesar más de 1 MB.',
            'logo.uploaded' => 'No se pudo subir el logo. Revisa que pese menos de 1 MB.',
        ];
    }
}

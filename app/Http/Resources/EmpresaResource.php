<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Configuración de la empresa para el administrador.
 *
 * `logo_url` apunta al endpoint que sirve el logo (el archivo vive en
 * `public/`, fuera del alcance del front); `v` cambia con cada logo nuevo
 * para que el navegador no muestre uno viejo.
 *
 * @mixin Empresa
 */
class EmpresaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tieneLogo = $this->logo_path !== null && is_file(public_path($this->logo_path));

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'nit' => $this->nit,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'direccion' => $this->direccion,
            'condiciones' => $this->condiciones,
            'logo_url' => $tieneLogo
                ? '/api/v1/empresa/logo?v='.substr(md5((string) $this->logo_path), 0, 10)
                : null,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

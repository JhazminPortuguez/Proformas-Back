<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubirLogoEmpresaRequest;
use App\Http\Requests\UpdateEmpresaRequest;
use App\Http\Resources\EmpresaResource;
use App\Models\Empresa;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Configuración de la empresa (pantalla /configuracion). Solo el
 * administrador; operario y cliente reciben 403.
 *
 * El logo se guarda en `public/logos/` porque el PDF de la proforma lo busca
 * con `public_path(logo_path)`.
 */
class EmpresaController extends Controller
{
    private const CARPETA_LOGOS = 'logos';

    public function show(Request $request): EmpresaResource
    {
        $this->soloAdmin($request);

        return new EmpresaResource($this->empresa());
    }

    public function update(UpdateEmpresaRequest $request): EmpresaResource
    {
        $empresa = $this->empresa();
        $empresa->update($request->validated());

        return new EmpresaResource($empresa->fresh());
    }

    public function subirLogo(SubirLogoEmpresaRequest $request): EmpresaResource
    {
        $empresa = $this->empresa();
        $archivo = $request->file('logo');
        $extension = $archivo->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $nombre = sprintf('empresa-%d-%s.%s', $empresa->id, Str::lower(Str::random(12)), $extension);

        File::ensureDirectoryExists(public_path(self::CARPETA_LOGOS));
        $archivo->move(public_path(self::CARPETA_LOGOS), $nombre);

        $anterior = $empresa->logo_path;
        $empresa->update(['logo_path' => self::CARPETA_LOGOS.'/'.$nombre]);
        $this->borrarArchivo($anterior);

        return new EmpresaResource($empresa->fresh());
    }

    public function eliminarLogo(Request $request): EmpresaResource
    {
        $this->soloAdmin($request);

        $empresa = $this->empresa();
        $anterior = $empresa->logo_path;
        $empresa->update(['logo_path' => null]);
        $this->borrarArchivo($anterior);

        return new EmpresaResource($empresa->fresh());
    }

    /** Sirve el logo para la vista previa de la pantalla de configuración. */
    public function logo(Request $request): BinaryFileResponse
    {
        $this->soloAdmin($request);

        $ruta = $this->empresa()->logo_path;

        abort_if($ruta === null || ! is_file(public_path($ruta)), 404, 'La empresa no tiene logo.');

        return response()->file(public_path($ruta), ['Cache-Control' => 'private, max-age=0']);
    }

    private function soloAdmin(Request $request): void
    {
        abort_unless($request->user()?->rol === Rol::Admin, 403, 'No tienes permisos para realizar esta acción.');
    }

    private function empresa(): Empresa
    {
        return Empresa::query()->findOrFail(EmpresaActual::id());
    }

    /** Solo borra archivos de la carpeta de logos (nunca otra ruta de public). */
    private function borrarArchivo(?string $ruta): void
    {
        if ($ruta === null || ! str_starts_with($ruta, self::CARPETA_LOGOS.'/') || str_contains($ruta, '..')) {
            return;
        }

        File::delete(public_path($ruta));
    }
}

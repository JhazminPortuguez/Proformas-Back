<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Enums\EstadoCliente;
use App\Enums\EstadoProforma;
use App\Http\Controllers\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Proforma;
use App\Models\SolicitudCotizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Base del portal del cliente.
 *
 * Todas las consultas parten del `cliente_id` del usuario de la sesión, nunca
 * de un dato de la petición. El middleware `cliente` garantiza que existe.
 * Un registro de otro cliente responde 404, igual que uno inexistente.
 */
abstract class PortalController extends Controller
{
    use RespondePaginado;

    protected function clienteId(Request $request): int
    {
        return (int) $request->user()->cliente_id;
    }

    /**
     * @return Builder<SolicitudCotizacion>
     */
    protected function solicitudes(Request $request): Builder
    {
        return SolicitudCotizacion::query()->where('cliente_id', $this->clienteId($request));
    }

    /**
     * Proformas del cliente que puede ver: nunca los borradores.
     *
     * @return Builder<Proforma>
     */
    protected function proformas(Request $request): Builder
    {
        return Proforma::query()
            ->where('cliente_id', $this->clienteId($request))
            ->whereIn('estado', array_map(
                fn (EstadoProforma $estado) => $estado->value,
                EstadoProforma::visiblesParaCliente(),
            ));
    }

    public const MENSAJE_CLIENTE_INACTIVO = 'Tu cuenta de cliente está inactiva. Puedes ver tus solicitudes y proformas, pero para enviar una solicitud nueva comunícate con la empresa.';

    /**
     * Un cliente inactivo puede entrar y ver lo suyo, pero no enviar
     * solicitudes nuevas.
     */
    protected function puedeSolicitar(Request $request): bool
    {
        return Cliente::query()->whereKey($this->clienteId($request))->value('estado') !== EstadoCliente::Inactivo;
    }

    protected function noEncontrado(): never
    {
        abort(404, 'No encontramos ese registro.');
    }
}

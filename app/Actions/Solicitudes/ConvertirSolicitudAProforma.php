<?php

declare(strict_types=1);

namespace App\Actions\Solicitudes;

use App\Actions\Proformas\CrearProforma;
use App\Enums\EstadoSolicitud;
use App\Enums\OrigenProforma;
use App\Enums\TipoProformaItem;
use App\Models\Proforma;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConvertirSolicitudAProforma
{
    public function __construct(
        private readonly CrearProforma $crearProforma,
    ) {}

    public function ejecutar(SolicitudCotizacion $solicitud, User $usuario): Proforma
    {
        return DB::transaction(function () use ($solicitud, $usuario) {
            $actual = SolicitudCotizacion::query()
                ->whereKey($solicitud->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== EstadoSolicitud::Pendiente) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede convertir una solicitud pendiente.',
                ]);
            }

            if ($actual->convertida_proforma_id !== null) {
                throw ValidationException::withMessages([
                    'estado' => 'La solicitud ya fue convertida en proforma.',
                ]);
            }

            $items = $actual->items()
                ->orderBy('id')
                ->get()
                ->map(fn ($item) => [
                    'tipo' => TipoProformaItem::Libre->value,
                    'descripcion' => $item->descripcion,
                    'cantidad' => (string) $item->cantidad,
                    'unidad_medida' => $item->unidad_medida,
                    'precio_unitario' => 0,
                ])
                ->all();

            if ($items === []) {
                $items = [[
                    'tipo' => TipoProformaItem::Libre->value,
                    'descripcion' => $actual->descripcion,
                    'cantidad' => 1,
                    'precio_unitario' => 0,
                ]];
            }

            $proforma = $this->crearProforma->ejecutar([
                'cliente_id' => $actual->cliente_id,
                'fecha_emision' => CarbonImmutable::now()->toDateString(),
                'fecha_validez' => CarbonImmutable::now()->addDays(30)->toDateString(),
                'moneda' => 'BOB',
                'impuesto_tasa' => 0,
                'items' => $items,
                'observaciones' => 'Generada desde solicitud #'.$actual->id,
                'origen' => OrigenProforma::Solicitud->value,
                'solicitud_id' => $actual->id,
            ], $usuario);

            $actual->update([
                'estado' => EstadoSolicitud::Atendida,
                'convertida_proforma_id' => $proforma->id,
                'updated_by' => $usuario->id,
            ]);

            return $proforma;
        });
    }
}

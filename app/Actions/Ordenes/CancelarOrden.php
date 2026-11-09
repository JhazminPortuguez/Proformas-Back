<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Actions\CuentasPorCobrar\GenerarNumeroCuenta;
use App\Enums\ConceptoCuenta;
use App\Enums\EstadoCobro;
use App\Enums\EstadoOrden;
use App\Enums\TipoMovimientoInventario;
use App\Enums\TipoOrdenEvento;
use App\Enums\TipoProformaItem;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoInventario;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\User;
use App\Support\Dinero;
use App\Support\FechaBolivia;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancela una orden activa. Si ya se consumieron materiales, el admin indica
 * cuánto de cada uno vuelve al stock (movimiento DEVOLUCION ligado a la
 * orden) y puede cobrarle al cliente lo que no se devolvió: una cuenta por
 * cobrar de concepto CANCELACION, a precio unitario de la línea de la orden,
 * sin el descuento ni el impuesto de la proforma (esos eran del trabajo
 * completo). Todo ocurre en una sola transacción y queda en el historial.
 */
final class CancelarOrden
{
    public const MENSAJE_NADA_QUE_COBRAR = 'No queda nada por cobrar: se devolvieron al stock todos los materiales consumidos.';

    public function __construct(
        private readonly GenerarNumeroCuenta $numeros,
    ) {}

    /**
     * @param  array<int, array{orden_item_id: int|string, cantidad: float|int|string}>  $devoluciones
     */
    public function ejecutar(
        OrdenServicio $orden,
        string $motivo,
        User $usuario,
        array $devoluciones = [],
        bool $cobrarMateriales = false,
    ): OrdenServicio {
        return DB::transaction(function () use ($orden, $motivo, $usuario, $devoluciones, $cobrarMateriales): OrdenServicio {
            $actual = OrdenServicio::query()
                ->whereKey($orden->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $actual->estado?->esActiva()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede cancelar una orden pendiente, asignada o en proceso.',
                ]);
            }

            $motivo = trim($motivo);

            if ($motivo === '') {
                throw ValidationException::withMessages([
                    'motivo' => 'El motivo de cancelación es obligatorio.',
                ]);
            }

            // Mismo orden de bloqueo que el consumo (líneas y luego productos
            // por id ascendente) para no cruzarse con un consumo concurrente.
            $consumidas = OrdenItem::query()
                ->where('orden_id', $actual->id)
                ->where('tipo', TipoProformaItem::Material->value)
                ->whereNotNull('producto_id')
                ->where('cantidad_consumida', '>', 0)
                ->orderBy('producto_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $devueltas = $this->cantidadesDevueltas($consumidas, $devoluciones);

            $productos = Producto::query()
                ->whereIn('id', $consumidas->pluck('producto_id')->unique()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $detalle = [];
            $montoCobro = '0.00';

            foreach ($consumidas as $linea) {
                $consumida = Dinero::cantidad($linea->cantidad_consumida);
                $devuelta = $devueltas[$linea->id] ?? '0.000';
                $noDevuelta = Dinero::cantidad(bcsub($consumida, $devuelta, Dinero::ESCALA_CANTIDAD));
                $precio = Dinero::redondear($linea->precio_unitario);
                $subtotal = Dinero::totalLinea($noDevuelta, $precio);
                $producto = $productos->get((int) $linea->producto_id);

                if (Dinero::comparar($devuelta, 0) > 0 && $producto instanceof Producto) {
                    $this->devolverAlStock($actual, $linea, $producto, $devuelta, $usuario);
                }

                $montoCobro = Dinero::sumar($montoCobro, $subtotal);
                $detalle[] = [
                    'orden_item_id' => $linea->id,
                    'producto_id' => $linea->producto_id,
                    'producto' => $producto?->nombre ?? $linea->descripcion,
                    'unidad_medida' => $linea->unidad_medida,
                    'consumida' => (float) $consumida,
                    'devuelta' => (float) $devuelta,
                    'no_devuelta' => (float) $noDevuelta,
                    'precio_unitario' => (float) $precio,
                    'subtotal' => (float) $subtotal,
                ];
            }

            $cuenta = null;

            if ($cobrarMateriales) {
                if (Dinero::comparar($montoCobro, 0) <= 0) {
                    throw ValidationException::withMessages([
                        'cobrar_materiales' => $consumidas->isEmpty()
                            ? 'La orden no tiene materiales consumidos que cobrar.'
                            : self::MENSAJE_NADA_QUE_COBRAR,
                    ]);
                }

                $cuenta = $this->crearCuentaDeCancelacion($actual, $detalle, $montoCobro, $usuario);
            }

            $estadoAnterior = $actual->estado;

            $actual->update([
                'estado' => EstadoOrden::Cancelada,
                'motivo_cancelacion' => $motivo,
                'cancelada_at' => now(),
                'updated_by' => $usuario->id,
            ]);

            $actual->eventos()->create([
                'tipo' => TipoOrdenEvento::Cancelacion,
                'descripcion' => $this->descripcionEvento($detalle, $cuenta),
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => EstadoOrden::Cancelada,
                'user_id' => $usuario->id,
                'metadata' => [
                    'motivo' => $motivo,
                    'materiales' => $detalle,
                    'cobro' => $cuenta === null ? null : [
                        'cuenta_id' => $cuenta->id,
                        'numero' => $cuenta->numero,
                        'monto' => (float) $cuenta->monto_total,
                    ],
                ],
            ]);

            return $actual->load(
                'cliente',
                'proforma',
                'operario',
                'items.producto',
                'items.servicio',
                'eventos.usuario',
                'cuentaPorCobrar',
            )->loadCount('items');
        });
    }

    /**
     * Valida la devolución pedida por línea: de 0 a lo consumido. Las líneas
     * que no vienen se toman como 0 (nada vuelve al stock).
     *
     * @param  Collection<int, OrdenItem>  $consumidas
     * @param  array<int, array<string, mixed>>  $devoluciones
     * @return array<int, string> Cantidad devuelta por id de línea.
     */
    private function cantidadesDevueltas(Collection $consumidas, array $devoluciones): array
    {
        $resultado = [];

        foreach ($devoluciones as $indice => $devolucion) {
            $itemId = (int) ($devolucion['orden_item_id'] ?? 0);

            if (isset($resultado[$itemId])) {
                throw ValidationException::withMessages([
                    "devoluciones.{$indice}.orden_item_id" => 'La línea está repetida.',
                ]);
            }

            $linea = $consumidas->firstWhere('id', $itemId);

            if (! $linea instanceof OrdenItem) {
                throw ValidationException::withMessages([
                    "devoluciones.{$indice}.orden_item_id" => 'La línea no es un material consumido de esta orden.',
                ]);
            }

            $cantidad = Dinero::cantidad($devolucion['cantidad'] ?? 0);
            $consumida = Dinero::cantidad($linea->cantidad_consumida);

            if (Dinero::comparar($cantidad, 0) < 0) {
                throw ValidationException::withMessages([
                    "devoluciones.{$indice}.cantidad" => 'La cantidad devuelta no puede ser negativa.',
                ]);
            }

            if (Dinero::comparar($cantidad, $consumida) > 0) {
                throw ValidationException::withMessages([
                    "devoluciones.{$indice}.cantidad" => sprintf(
                        'No se puede devolver más de lo consumido de %s (%s).',
                        $linea->descripcion,
                        rtrim(rtrim($consumida, '0'), '.'),
                    ),
                ]);
            }

            $resultado[$itemId] = $cantidad;
        }

        return $resultado;
    }

    private function devolverAlStock(
        OrdenServicio $orden,
        OrdenItem $linea,
        Producto $producto,
        string $cantidad,
        User $usuario,
    ): void {
        $stockAnterior = Dinero::cantidad($producto->stock_actual);
        $stockNuevo = Dinero::cantidad(bcadd($stockAnterior, $cantidad, Dinero::ESCALA_CANTIDAD));

        $producto->update(['stock_actual' => $stockNuevo]);

        MovimientoInventario::create([
            'empresa_id' => $producto->empresa_id,
            'producto_id' => $producto->id,
            'orden_id' => $orden->id,
            'orden_item_id' => $linea->id,
            'tipo' => TipoMovimientoInventario::Devolucion,
            'cantidad' => $cantidad,
            'costo_unitario' => Dinero::redondear($producto->costo_promedio),
            'stock_anterior' => $stockAnterior,
            'stock_nuevo' => $stockNuevo,
            'motivo' => 'Devolución por cancelación de la orden '.$orden->numero,
            'created_by' => $usuario->id,
        ]);

        // La línea queda con lo que de verdad se usó.
        $linea->update([
            'cantidad_consumida' => Dinero::cantidad(
                bcsub(Dinero::cantidad($linea->cantidad_consumida), $cantidad, Dinero::ESCALA_CANTIDAD)
            ),
            'consumido_at' => null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $detalle
     */
    private function crearCuentaDeCancelacion(
        OrdenServicio $orden,
        array $detalle,
        string $monto,
        User $usuario,
    ): CuentaPorCobrar {
        $hoy = FechaBolivia::hoy();
        $moneda = Proforma::query()->whereKey($orden->proforma_id)->value('moneda') ?? 'BOB';

        return CuentaPorCobrar::create([
            'empresa_id' => $orden->empresa_id,
            'orden_id' => $orden->id,
            'cliente_id' => $orden->cliente_id,
            'concepto' => ConceptoCuenta::Cancelacion,
            'numero' => $this->numeros->ejecutar($hoy),
            'fecha_emision' => $hoy->toDateString(),
            'fecha_vencimiento' => $hoy->addDays(30)->toDateString(),
            'moneda' => $moneda,
            'monto_total' => $monto,
            'saldo' => $monto,
            'estado_cobro' => EstadoCobro::Pendiente,
            'observaciones' => $this->observacionesCobro($orden, $detalle, $monto),
            'created_by' => $usuario->id,
            'updated_by' => $usuario->id,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $detalle
     */
    private function observacionesCobro(OrdenServicio $orden, array $detalle, string $monto): string
    {
        $lineas = [
            "Cobro por cancelación de la orden {$orden->numero}: materiales usados que no se devolvieron al stock.",
            'Precio unitario de la línea de la orden × cantidad no devuelta, sin el descuento ni el impuesto de la proforma.',
        ];

        foreach ($detalle as $material) {
            if ($material['no_devuelta'] <= 0) {
                continue;
            }

            $lineas[] = sprintf(
                '- %s: %s %s × Bs %s = Bs %s (consumido %s, devuelto %s)',
                $material['producto'],
                self::cantidad($material['no_devuelta']),
                $material['unidad_medida'] ?: 'unid.',
                self::dinero($material['precio_unitario']),
                self::dinero($material['subtotal']),
                self::cantidad($material['consumida']),
                self::cantidad($material['devuelta']),
            );
        }

        $lineas[] = 'Total: Bs '.self::dinero((float) $monto);

        return implode("\n", $lineas);
    }

    /**
     * @param  list<array<string, mixed>>  $detalle
     */
    private function descripcionEvento(array $detalle, ?CuentaPorCobrar $cuenta): string
    {
        $partes = ['Orden cancelada.'];
        $devueltos = array_filter($detalle, fn (array $material): bool => $material['devuelta'] > 0);

        if ($detalle !== []) {
            $partes[] = $devueltos === []
                ? 'No se devolvieron materiales al stock.'
                : 'Devuelto al stock: '.implode(', ', array_map(
                    fn (array $material): string => self::cantidad($material['devuelta']).' '.$material['producto'],
                    $devueltos,
                )).'.';
        }

        if ($cuenta !== null) {
            $partes[] = sprintf(
                'Cobro por cancelación %s por Bs %s.',
                $cuenta->numero,
                self::dinero((float) $cuenta->monto_total),
            );
        }

        return implode(' ', $partes);
    }

    private static function cantidad(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 3, ',', '.'), '0'), ',');
    }

    private static function dinero(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}

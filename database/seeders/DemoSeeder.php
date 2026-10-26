<?php

namespace Database\Seeders;

use App\Actions\CuentasPorCobrar\RegistrarPagoCuenta;
use App\Actions\Ordenes\CerrarOrden;
use App\Actions\Ordenes\ConsumirMaterialesOrden;
use App\Enums\EstadoCliente;
use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\MetodoPago;
use App\Enums\Rol;
use App\Enums\TipoMovimientoInventario;
use App\Enums\TipoOrdenEvento;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\OrdenEvento;
use App\Models\OrdenServicio;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\Servicio;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    /**
     * Datos de demostración reproducibles para desarrollo y pruebas.
     * No debe ejecutarse en producción.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoSeeder solo puede ejecutarse en local o testing.');
        }

        $this->call([
            EmpresaSeeder::class,
            ServicioSeeder::class,
        ]);

        $empresaId = (int) (Empresa::query()->orderBy('id')->value('id') ?? 1);
        $password = (string) env('ADMIN_PASSWORD', 'password');

        $admin = User::updateOrCreate(
            ['email' => 'pepitotilcayo@example.com'],
            [
                'name' => 'Pepito Tilcayo',
                'password' => $password,
                'rol' => Rol::Admin,
                'activo' => true,
                'cliente_id' => null,
                'empresa_id' => $empresaId,
            ],
        );

        $operario = User::updateOrCreate(
            ['email' => 'operario@example.com'],
            [
                'name' => 'Operario Demo',
                'password' => $password,
                'rol' => Rol::Operario,
                'activo' => true,
                'cliente_id' => null,
                'empresa_id' => $empresaId,
            ],
        );

        $clienteDemo = Cliente::updateOrCreate(
            ['empresa_id' => $empresaId, 'email' => 'cliente.demo@example.com'],
            [
                'nombre_razon' => 'Cliente Demo SRL',
                'nit_ci' => '9001001',
                'telefono' => '77710001',
                'direccion' => 'Av. Demo 123',
                'estado' => EstadoCliente::Activo,
            ],
        );

        Cliente::updateOrCreate(
            ['empresa_id' => $empresaId, 'email' => 'prospecto.demo@example.com'],
            [
                'nombre_razon' => 'Cliente Prospecto SRL',
                'nit_ci' => '9001002',
                'telefono' => '77710002',
                'direccion' => 'Calle Prospecto 456',
                'estado' => EstadoCliente::Prospecto,
            ],
        );

        Cliente::updateOrCreate(
            ['empresa_id' => $empresaId, 'email' => 'inactivo.demo@example.com'],
            [
                'nombre_razon' => 'Cliente Inactivo SRL',
                'nit_ci' => '9001003',
                'telefono' => '77710003',
                'direccion' => 'Pasaje Inactivo 789',
                'estado' => EstadoCliente::Inactivo,
            ],
        );

        User::updateOrCreate(
            ['email' => 'cliente.demo@example.com'],
            [
                'name' => 'Cliente Demo',
                'password' => $password,
                'rol' => Rol::Cliente,
                'activo' => true,
                'cliente_id' => $clienteDemo->id,
                'empresa_id' => $empresaId,
            ],
        );

        $productos = $this->crearProductos($empresaId);
        $this->crearMovimientosIniciales($productos, $admin);
        $servicios = Servicio::query()
            ->where('empresa_id', $empresaId)
            ->get()
            ->keyBy('nombre');
        $anio = Carbon::now()->year;

        $this->crearProforma(
            empresaId: $empresaId,
            numero: sprintf('PR-%d-%06d', $anio, 1),
            cliente: $clienteDemo,
            admin: $admin,
            fechaEmision: Carbon::now()->subDays(10),
            estado: EstadoProforma::Borrador,
            lineas: [
                ['servicio' => $servicios['Mantenimiento preventivo'], 'cantidad' => 1, 'descripcion' => 'Mantenimiento preventivo'],
                ['producto' => $productos['ssd'], 'cantidad' => 2, 'descripcion' => 'SSD para actualización'],
            ],
            descuento: 0,
            impuestoTasa: 0,
        );

        $this->crearProforma(
            empresaId: $empresaId,
            numero: sprintf('PR-%d-%06d', $anio, 2),
            cliente: $clienteDemo,
            admin: $admin,
            fechaEmision: Carbon::now()->subDays(5),
            estado: EstadoProforma::Enviada,
            lineas: [
                ['servicio' => $servicios['Soporte remoto'], 'cantidad' => 1, 'descripcion' => 'Soporte remoto'],
                ['producto' => $productos['fuente'], 'cantidad' => 1, 'descripcion' => 'Fuente de poder'],
                ['tipo' => 'LIBRE', 'descripcion' => 'Diagnóstico adicional', 'cantidad' => 1, 'precio_unitario' => 50, 'unidad_medida' => 'unidad'],
            ],
            descuento: 15,
            impuestoTasa: 0,
        );

        $prospecto = Cliente::query()
            ->where('empresa_id', $empresaId)
            ->where('email', 'prospecto.demo@example.com')
            ->firstOrFail();

        $proformaAceptada = $this->crearProforma(
            empresaId: $empresaId,
            numero: sprintf('PR-%d-%06d', $anio, 3),
            cliente: $prospecto,
            admin: $admin,
            fechaEmision: Carbon::now()->subDay(),
            estado: EstadoProforma::Aceptada,
            lineas: [
                ['servicio' => $servicios['Instalación básica'], 'cantidad' => 1, 'descripcion' => 'Instalación básica'],
                ['producto' => $productos['ventilador'], 'cantidad' => 2, 'descripcion' => 'Ventilador de reemplazo'],
            ],
            descuento: 10,
            impuestoTasa: 13,
        );

        $this->crearOrdenesDemo(
            empresaId: $empresaId,
            anio: $anio,
            proforma: $proformaAceptada,
            admin: $admin,
            operario: $operario,
        );

        $ordenAsignada = OrdenServicio::query()
            ->where('empresa_id', $empresaId)
            ->where('numero', sprintf('OS-%d-%06d', $anio, 2))
            ->firstOrFail();

        $this->crearConsumoDemo($ordenAsignada, $operario);

        $this->actualizarSecuencia($empresaId, $anio);
        $this->actualizarSecuenciaOrdenes($empresaId, $anio);
        $this->actualizarSecuenciaCuentas($empresaId, $anio);
    }

    /**
     * @return array<string, Producto>
     */
    private function crearProductos(int $empresaId): array
    {
        $definiciones = [
            'ssd' => [
                'nombre' => 'Disco SSD 480 GB',
                'descripcion' => 'Unidad de estado sólido para equipos de escritorio y portátiles.',
                'precio_unitario' => 250.00,
                'unidad_medida' => 'unidad',
                'stock_actual' => 25,
                'stock_minimo' => 5,
                'costo_promedio' => 190.00,
            ],
            'cable' => [
                'nombre' => 'Cable HDMI 2 m',
                'descripcion' => 'Cable HDMI de alta velocidad.',
                'precio_unitario' => 30.00,
                'unidad_medida' => 'unidad',
                'stock_actual' => 50,
                'stock_minimo' => 10,
                'costo_promedio' => 18.00,
            ],
            'fuente' => [
                'nombre' => 'Fuente de poder 500 W',
                'descripcion' => 'Fuente certificada para equipos de oficina.',
                'precio_unitario' => 180.00,
                'unidad_medida' => 'unidad',
                'stock_actual' => 12,
                'stock_minimo' => 3,
                'costo_promedio' => 140.00,
            ],
            'pasta' => [
                'nombre' => 'Pasta térmica',
                'descripcion' => 'Compuesto térmico para procesadores.',
                'precio_unitario' => 25.00,
                'unidad_medida' => 'unidad',
                'stock_actual' => 40,
                'stock_minimo' => 10,
                'costo_promedio' => 12.00,
            ],
            'limpieza' => [
                'nombre' => 'Kit de limpieza',
                'descripcion' => 'Paño, alcohol isopropílico y brochas antiestáticas.',
                'precio_unitario' => 40.00,
                'unidad_medida' => 'kit',
                'stock_actual' => 15,
                'stock_minimo' => 4,
                'costo_promedio' => 22.00,
            ],
            'ventilador' => [
                'nombre' => 'Ventilador de reemplazo',
                'descripcion' => 'Ventilador interno para gabinete.',
                'precio_unitario' => 90.00,
                'unidad_medida' => 'unidad',
                'stock_actual' => 8,
                'stock_minimo' => 2,
                'costo_promedio' => 55.00,
            ],
        ];

        $productos = [];

        foreach ($definiciones as $clave => $definicion) {
            $productos[$clave] = Producto::updateOrCreate(
                ['empresa_id' => $empresaId, 'nombre' => $definicion['nombre']],
                [
                    ...$definicion,
                    'empresa_id' => $empresaId,
                    'activo' => true,
                ],
            );
        }

        return $productos;
    }

    /**
     * Movimiento de stock inicial reproducible: la clave lógica
     * producto + tipo + motivo evita duplicados al reejecutar el seeder.
     *
     * @param  array<string, Producto>  $productos
     */
    private function crearMovimientosIniciales(array $productos, User $admin): void
    {
        foreach ($productos as $producto) {
            $stock = Dinero::cantidad($producto->stock_actual);

            MovimientoInventario::query()->updateOrCreate(
                [
                    'empresa_id' => $producto->empresa_id,
                    'producto_id' => $producto->id,
                    'tipo' => TipoMovimientoInventario::Entrada,
                    'motivo' => 'Stock inicial demo',
                ],
                [
                    'cantidad' => $stock,
                    'costo_unitario' => Dinero::redondear($producto->costo_promedio),
                    'stock_anterior' => '0.000',
                    'stock_nuevo' => $stock,
                    'created_by' => $admin->id,
                ],
            );
        }
    }

    /**
     * Consumo demo sobre la orden asignada. Se elimina el consumo previo (si
     * existe) antes de recrearlo para que el seeder sea idempotente.
     */
    private function crearConsumoDemo(OrdenServicio $orden, User $operario): void
    {
        MovimientoInventario::query()
            ->where('orden_id', $orden->id)
            ->where('tipo', TipoMovimientoInventario::Consumo->value)
            ->delete();

        $linea = $orden->items()
            ->where('tipo', 'MATERIAL')
            ->whereNotNull('producto_id')
            ->orderBy('id')
            ->first();

        if ($linea === null) {
            return;
        }

        // Consumo parcial deliberado: deja material pendiente para mostrar el
        // flujo de consumo en la demo y en el E2E.
        $cantidad = Dinero::cantidad(bcdiv($linea->cantidad, '2', 3));

        if (Dinero::comparar($cantidad, 0) <= 0) {
            return;
        }

        app(ConsumirMaterialesOrden::class)->ejecutar(
            $orden,
            [['orden_item_id' => $linea->id, 'cantidad' => $cantidad]],
            $operario,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function crearProforma(
        int $empresaId,
        string $numero,
        Cliente $cliente,
        User $admin,
        Carbon $fechaEmision,
        EstadoProforma $estado,
        array $lineas,
        float $descuento,
        float $impuestoTasa,
    ): Proforma {
        $items = [];
        $totalesLineas = [];

        foreach ($lineas as $linea) {
            $cantidad = $linea['cantidad'];

            if (isset($linea['servicio'])) {
                $servicio = $linea['servicio'];
                $precio = Dinero::redondear($servicio->precio_base);
                $items[] = [
                    'tipo' => 'SERVICIO',
                    'producto_id' => null,
                    'servicio_id' => $servicio->id,
                    'nombre' => $servicio->nombre,
                    'descripcion' => $linea['descripcion'] ?? $servicio->nombre,
                    'unidad_medida' => null,
                    'cantidad' => Dinero::cantidad($cantidad),
                    'precio_unitario' => $precio,
                    'total_linea' => Dinero::totalLinea($cantidad, $precio),
                ];
            } elseif (($linea['tipo'] ?? null) === 'LIBRE') {
                $precio = Dinero::redondear($linea['precio_unitario']);
                $items[] = [
                    'tipo' => 'LIBRE',
                    'producto_id' => null,
                    'servicio_id' => null,
                    'nombre' => $linea['descripcion'],
                    'descripcion' => $linea['descripcion'],
                    'unidad_medida' => $linea['unidad_medida'] ?? 'unidad',
                    'cantidad' => Dinero::cantidad($cantidad),
                    'precio_unitario' => $precio,
                    'total_linea' => Dinero::totalLinea($cantidad, $precio),
                ];
            } else {
                $producto = $linea['producto'];
                $precio = Dinero::redondear($producto->precio_unitario);
                $items[] = [
                    'tipo' => 'MATERIAL',
                    'producto_id' => $producto->id,
                    'servicio_id' => null,
                    'nombre' => $producto->nombre,
                    'descripcion' => $linea['descripcion'] ?? $producto->nombre,
                    'unidad_medida' => $producto->unidad_medida,
                    'cantidad' => Dinero::cantidad($cantidad),
                    'precio_unitario' => $precio,
                    'total_linea' => Dinero::totalLinea($cantidad, $precio),
                ];
            }

            $totalesLineas[] = end($items)['total_linea'];
        }

        $subtotal = Dinero::sumar(...$totalesLineas);
        $base = Dinero::restar($subtotal, $descuento);
        $impuesto = Dinero::porcentaje($base, $impuestoTasa);
        $total = Dinero::sumar($base, $impuesto);

        $proforma = Proforma::updateOrCreate(
            ['empresa_id' => $empresaId, 'numero' => $numero],
            [
                'cliente_id' => $cliente->id,
                'fecha_emision' => $fechaEmision->toDateString(),
                'fecha_validez' => $fechaEmision->copy()->addDays(30)->toDateString(),
                'estado' => $estado,
                'moneda' => 'BOB',
                'impuesto_tasa' => $impuestoTasa,
                'subtotal' => $subtotal,
                'descuento' => Dinero::redondear($descuento),
                'impuesto' => $impuesto,
                'total' => $total,
                'observaciones' => 'DemoSeeder',
                'origen' => 'INTERNA',
                'enviada_at' => $estado === EstadoProforma::Borrador ? null : $fechaEmision,
                'aceptada_at' => $estado === EstadoProforma::Aceptada ? $fechaEmision : null,
                'aceptada_por' => $estado === EstadoProforma::Aceptada ? $admin->id : null,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ],
        );

        $proforma->items()->delete();
        $proforma->items()->createMany($items);

        return $proforma;
    }

    private function crearOrdenesDemo(
        int $empresaId,
        int $anio,
        Proforma $proforma,
        User $admin,
        User $operario,
    ): void {
        $ordenCancelada = $this->crearOrdenDemo(
            empresaId: $empresaId,
            numero: sprintf('OS-%d-%06d', $anio, 1),
            proforma: $proforma,
            admin: $admin,
            operario: null,
            estado: EstadoOrden::Cancelada,
            fechaProgramada: null,
            observaciones: 'Orden demo cancelada antes de asignarse.',
            motivoCancelacion: 'El cliente reprogramó el servicio.',
            canceladaAt: Carbon::now()->subHours(3),
        );

        $ordenCancelada->eventos()->createMany([
            [
                'tipo' => TipoOrdenEvento::Creacion,
                'descripcion' => 'Orden creada desde la proforma '.$proforma->numero.'.',
                'estado_anterior' => null,
                'estado_nuevo' => EstadoOrden::Pendiente,
                'user_id' => $admin->id,
                'metadata' => [
                    'proforma_id' => $proforma->id,
                    'proforma_numero' => $proforma->numero,
                ],
            ],
            [
                'tipo' => TipoOrdenEvento::Cancelacion,
                'descripcion' => 'Orden cancelada.',
                'estado_anterior' => EstadoOrden::Pendiente,
                'estado_nuevo' => EstadoOrden::Cancelada,
                'user_id' => $admin->id,
                'metadata' => ['motivo' => 'El cliente reprogramó el servicio.'],
            ],
        ]);

        $ordenAsignada = $this->crearOrdenDemo(
            empresaId: $empresaId,
            numero: sprintf('OS-%d-%06d', $anio, 2),
            proforma: $proforma,
            admin: $admin,
            operario: $operario,
            estado: EstadoOrden::EnProceso,
            fechaProgramada: Carbon::now()->addDays(2)->toDateString(),
            observaciones: 'Orden demo asignada al operario.',
            motivoCancelacion: null,
            canceladaAt: null,
            fechaInicio: Carbon::now()->subHour(),
        );

        $ordenAsignada->eventos()->createMany([
            [
                'tipo' => TipoOrdenEvento::Creacion,
                'descripcion' => 'Orden creada desde la proforma '.$proforma->numero.'.',
                'estado_anterior' => null,
                'estado_nuevo' => EstadoOrden::Pendiente,
                'user_id' => $admin->id,
                'metadata' => [
                    'proforma_id' => $proforma->id,
                    'proforma_numero' => $proforma->numero,
                ],
            ],
            [
                'tipo' => TipoOrdenEvento::Asignacion,
                'descripcion' => 'Orden asignada a '.$operario->name.'.',
                'estado_anterior' => EstadoOrden::Pendiente,
                'estado_nuevo' => EstadoOrden::Asignada,
                'user_id' => $admin->id,
                'metadata' => [
                    'operario_id' => $operario->id,
                    'operario' => $operario->name,
                ],
            ],
            [
                'tipo' => TipoOrdenEvento::Inicio,
                'descripcion' => 'Orden iniciada.',
                'estado_anterior' => EstadoOrden::Asignada,
                'estado_nuevo' => EstadoOrden::EnProceso,
                'user_id' => $operario->id,
            ],
        ]);

        // Tercera orden: finalizada y cerrada, para mostrar el smart button de
        // cuenta por cobrar y un pago parcial en la demo.
        $ordenCerrada = $this->crearOrdenDemo(
            empresaId: $empresaId,
            numero: sprintf('OS-%d-%06d', $anio, 3),
            proforma: $proforma,
            admin: $admin,
            operario: $operario,
            estado: EstadoOrden::Finalizada,
            fechaProgramada: Carbon::now()->subDay()->toDateString(),
            observaciones: 'Orden demo cerrada con cuenta por cobrar.',
            motivoCancelacion: null,
            canceladaAt: null,
            fechaInicio: Carbon::now()->subDays(2),
            fechaFin: Carbon::now()->subHours(4),
        );

        $ordenCerrada->eventos()->createMany([
            [
                'tipo' => TipoOrdenEvento::Creacion,
                'descripcion' => 'Orden creada desde la proforma '.$proforma->numero.'.',
                'estado_anterior' => null,
                'estado_nuevo' => EstadoOrden::Pendiente,
                'user_id' => $admin->id,
                'metadata' => [
                    'proforma_id' => $proforma->id,
                    'proforma_numero' => $proforma->numero,
                ],
            ],
            [
                'tipo' => TipoOrdenEvento::Finalizacion,
                'descripcion' => 'Orden finalizada.',
                'estado_anterior' => EstadoOrden::EnProceso,
                'estado_nuevo' => EstadoOrden::Finalizada,
                'user_id' => $operario->id,
            ],
        ]);

        app(CerrarOrden::class)->ejecutar($ordenCerrada, $admin);
        $this->crearPagoDemo($ordenCerrada, $admin);
    }

    /**
     * Registra un único pago parcial demo. La comprobación de pagos existentes
     * mantiene el seeder idempotente al reejecutarlo.
     */
    private function crearPagoDemo(OrdenServicio $orden, User $admin): void
    {
        $cuenta = CuentaPorCobrar::query()
            ->where('orden_id', $orden->id)
            ->first();

        if ($cuenta === null || $cuenta->pagos()->exists()) {
            return;
        }

        $monto = Dinero::redondear(bcdiv((string) $cuenta->monto_total, '2', 2));

        if (Dinero::comparar($monto, 0) <= 0) {
            return;
        }

        app(RegistrarPagoCuenta::class)->ejecutar($cuenta, [
            'monto' => $monto,
            'metodo' => MetodoPago::Transferencia->value,
            'fecha_pago' => Carbon::now()->subHours(6)->toDateString(),
            'referencia' => 'DEMO-CXC-001',
            'observaciones' => 'Pago parcial demo.',
        ], $admin);
    }

    private function crearOrdenDemo(
        int $empresaId,
        string $numero,
        Proforma $proforma,
        User $admin,
        ?User $operario,
        EstadoOrden $estado,
        ?string $fechaProgramada,
        ?string $observaciones,
        ?string $motivoCancelacion,
        ?Carbon $canceladaAt,
        ?Carbon $fechaInicio = null,
        ?Carbon $fechaFin = null,
    ): OrdenServicio {
        $orden = OrdenServicio::updateOrCreate(
            ['empresa_id' => $empresaId, 'numero' => $numero],
            [
                'proforma_id' => $proforma->id,
                'cliente_id' => $proforma->cliente_id,
                'operario_id' => $operario?->id,
                'estado' => $estado,
                'fecha_programada' => $fechaProgramada,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'observaciones' => $observaciones,
                'motivo_cancelacion' => $motivoCancelacion,
                'cancelada_at' => $canceladaAt,
                'cerrada_at' => null,
                'cerrada_por' => null,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ],
        );

        $orden->items()->delete();
        OrdenEvento::query()->where('orden_id', $orden->id)->delete();

        $orden->items()->createMany(
            $proforma->items()
                ->orderBy('id')
                ->get()
                ->map(fn ($item): array => [
                    'tipo' => $item->tipo?->value ?? 'MATERIAL',
                    'servicio_id' => $item->servicio_id,
                    'producto_id' => $item->producto_id,
                    'descripcion' => trim((string) ($item->descripcion ?: $item->nombre)),
                    'unidad_medida' => $item->unidad_medida,
                    'cantidad' => $item->cantidad,
                    'precio_unitario' => $item->precio_unitario,
                    'total_linea' => $item->total_linea,
                ])
                ->all()
        );

        return $orden;
    }

    private function actualizarSecuencia(int $empresaId, int $anio): void
    {
        $actual = (int) (DB::table('proforma_sequences')
            ->where('empresa_id', $empresaId)
            ->where('anio', $anio)
            ->value('ultimo_numero') ?? 0);

        DB::table('proforma_sequences')->updateOrInsert(
            ['empresa_id' => $empresaId, 'anio' => $anio],
            [
                'ultimo_numero' => max($actual, 3),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function actualizarSecuenciaOrdenes(int $empresaId, int $anio): void
    {
        $actual = (int) (DB::table('orden_sequences')
            ->where('empresa_id', $empresaId)
            ->where('anio', $anio)
            ->value('ultimo_numero') ?? 0);

        DB::table('orden_sequences')->updateOrInsert(
            ['empresa_id' => $empresaId, 'anio' => $anio],
            [
                'ultimo_numero' => max($actual, 3),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function actualizarSecuenciaCuentas(int $empresaId, int $anio): void
    {
        $actual = (int) (DB::table('cuenta_sequences')
            ->where('empresa_id', $empresaId)
            ->where('anio', $anio)
            ->value('ultimo_numero') ?? 0);

        DB::table('cuenta_sequences')->updateOrInsert(
            ['empresa_id' => $empresaId, 'anio' => $anio],
            [
                'ultimo_numero' => max($actual, 1),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}

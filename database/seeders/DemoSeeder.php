<?php

namespace Database\Seeders;

use App\Enums\EstadoCliente;
use App\Enums\EstadoProforma;
use App\Enums\Rol;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Proforma;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
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

        User::updateOrCreate(
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

        $this->crearProforma(
            empresaId: $empresaId,
            numero: 'PR-2026-900001',
            cliente: $clienteDemo,
            admin: $admin,
            fechaEmision: Carbon::now()->subDays(10),
            estado: EstadoProforma::Borrador,
            lineas: [
                ['producto' => $productos['ssd'], 'cantidad' => 2, 'descripcion' => 'SSD para actualización'],
                ['producto' => $productos['cable'], 'cantidad' => 1, 'descripcion' => 'Cable HDMI'],
            ],
            descuento: 0,
            impuesto: 0,
        );

        $this->crearProforma(
            empresaId: $empresaId,
            numero: 'PR-2026-900002',
            cliente: $clienteDemo,
            admin: $admin,
            fechaEmision: Carbon::now()->subDays(5),
            estado: EstadoProforma::Enviada,
            lineas: [
                ['producto' => $productos['fuente'], 'cantidad' => 1, 'descripcion' => 'Fuente de poder'],
                ['producto' => $productos['pasta'], 'cantidad' => 1, 'descripcion' => 'Pasta térmica'],
                ['producto' => $productos['limpieza'], 'cantidad' => 1, 'descripcion' => 'Kit de limpieza'],
            ],
            descuento: 15,
            impuesto: 0,
        );

        $prospecto = Cliente::query()
            ->where('empresa_id', $empresaId)
            ->where('email', 'prospecto.demo@example.com')
            ->firstOrFail();

        $this->crearProforma(
            empresaId: $empresaId,
            numero: 'PR-2026-900003',
            cliente: $prospecto,
            admin: $admin,
            fechaEmision: Carbon::now()->subDay(),
            estado: EstadoProforma::Aceptada,
            lineas: [
                ['producto' => $productos['ventilador'], 'cantidad' => 2, 'descripcion' => 'Ventilador de reemplazo'],
                ['producto' => $productos['cable'], 'cantidad' => 1, 'descripcion' => 'Cable HDMI'],
            ],
            descuento: 10,
            impuesto: 10.5,
        );
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
            ],
            'cable' => [
                'nombre' => 'Cable HDMI 2 m',
                'descripcion' => 'Cable HDMI de alta velocidad.',
                'precio_unitario' => 30.00,
                'unidad_medida' => 'unidad',
            ],
            'fuente' => [
                'nombre' => 'Fuente de poder 500 W',
                'descripcion' => 'Fuente certificada para equipos de oficina.',
                'precio_unitario' => 180.00,
                'unidad_medida' => 'unidad',
            ],
            'pasta' => [
                'nombre' => 'Pasta térmica',
                'descripcion' => 'Compuesto térmico para procesadores.',
                'precio_unitario' => 25.00,
                'unidad_medida' => 'unidad',
            ],
            'limpieza' => [
                'nombre' => 'Kit de limpieza',
                'descripcion' => 'Paño, alcohol isopropílico y brochas antiestáticas.',
                'precio_unitario' => 40.00,
                'unidad_medida' => 'kit',
            ],
            'ventilador' => [
                'nombre' => 'Ventilador de reemplazo',
                'descripcion' => 'Ventilador interno para gabinete.',
                'precio_unitario' => 90.00,
                'unidad_medida' => 'unidad',
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
     * @param  array<int, array{producto: Producto, cantidad: int, descripcion: string}>  $lineas
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
        float $impuesto,
    ): void {
        $items = [];
        $subtotal = 0.0;

        foreach ($lineas as $linea) {
            $precio = (float) $linea['producto']->precio_unitario;
            $totalLinea = round($linea['cantidad'] * $precio, 2);
            $subtotal += $totalLinea;

            $items[] = [
                'producto_id' => $linea['producto']->id,
                'descripcion' => $linea['descripcion'],
                'cantidad' => $linea['cantidad'],
                'precio_unitario' => $precio,
                'total_linea' => $totalLinea,
            ];
        }

        $subtotal = round($subtotal, 2);
        $total = round($subtotal - $descuento + $impuesto, 2);

        $proforma = Proforma::updateOrCreate(
            ['empresa_id' => $empresaId, 'numero' => $numero],
            [
                'cliente_id' => $cliente->id,
                'fecha_emision' => $fechaEmision->toDateString(),
                'fecha_validez' => $fechaEmision->copy()->addDays(30)->toDateString(),
                'estado' => $estado,
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'impuesto' => $impuesto,
                'total' => $total,
                'observaciones' => 'DemoSeeder',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ],
        );

        $proforma->items()->delete();
        $proforma->items()->createMany($items);
    }
}

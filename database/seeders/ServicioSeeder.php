<?php

namespace Database\Seeders;

use App\Models\Servicio;
use Illuminate\Database\Seeder;

class ServicioSeeder extends Seeder
{
    public function run(): void
    {
        $servicios = [
            [
                'nombre' => 'Instalación básica',
                'descripcion' => 'Instalación y puesta en marcha de equipos en sitio.',
                'precio_base' => 350.00,
                'duracion_estimada_min' => 120,
                'categoria' => 'Instalación',
            ],
            [
                'nombre' => 'Mantenimiento preventivo',
                'descripcion' => 'Revisión, limpieza y ajuste programado de equipos.',
                'precio_base' => 250.00,
                'duracion_estimada_min' => 90,
                'categoria' => 'Mantenimiento',
            ],
            [
                'nombre' => 'Consultoría técnica',
                'descripcion' => 'Asesoría especializada para definir soluciones.',
                'precio_base' => 500.00,
                'duracion_estimada_min' => 60,
                'categoria' => 'Consultoría',
            ],
            [
                'nombre' => 'Soporte remoto',
                'descripcion' => 'Atención y diagnóstico remoto de incidencias.',
                'precio_base' => 150.00,
                'duracion_estimada_min' => 45,
                'categoria' => 'Soporte',
            ],
        ];

        foreach ($servicios as $servicio) {
            Servicio::query()->updateOrCreate(
                ['empresa_id' => 1, 'nombre' => $servicio['nombre']],
                [
                    ...$servicio,
                    'empresa_id' => 1,
                    'activo' => true,
                    'visible_catalogo' => true,
                ],
            );
        }
    }
}

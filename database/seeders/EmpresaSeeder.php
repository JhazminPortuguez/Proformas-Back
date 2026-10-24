<?php

namespace Database\Seeders;

use App\Models\Empresa;
use Illuminate\Database\Seeder;

class EmpresaSeeder extends Seeder
{
    public function run(): void
    {
        Empresa::query()->firstOrCreate(
            ['id' => 1],
            [
                'nombre' => 'Empresa Demo',
                'nit' => null,
                'telefono' => null,
                'email' => null,
                'direccion' => null,
            ],
        );
    }
}

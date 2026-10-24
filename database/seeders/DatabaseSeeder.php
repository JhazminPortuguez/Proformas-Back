<?php

namespace Database\Seeders;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            EmpresaSeeder::class,
            ServicioSeeder::class,
        ]);

        $password = (string) env('ADMIN_PASSWORD', 'password');

        $admin = User::firstOrCreate(
            ['email' => 'pepitotilcayo@example.com'],
            [
                'name' => 'Pepito Tilcayo',
                'password' => $password,
                'rol' => Rol::Admin,
                'activo' => true,
                'empresa_id' => 1,
            ],
        );

        $admin->forceFill([
            'name' => 'Pepito Tilcayo',
            'rol' => Rol::Admin,
            'activo' => true,
            'empresa_id' => 1,
        ])->save();

        // Usuario de ejemplo solo para desarrollo local.
        if (app()->environment('local')) {
            User::firstOrCreate(
                ['email' => 'operario@example.com'],
                [
                    'name' => 'Operario Demo',
                    'password' => $password,
                    'rol' => Rol::Operario,
                    'activo' => true,
                    'empresa_id' => 1,
                ],
            );

            $this->call(DemoSeeder::class);
        }
    }
}

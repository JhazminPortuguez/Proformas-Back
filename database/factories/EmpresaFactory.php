<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Empresa>
 */
class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->company(),
            'nit' => fake()->numerify('#########'),
            'telefono' => fake()->numerify('7#######'),
            'email' => fake()->unique()->safeEmail(),
            'direccion' => fake()->address(),
        ];
    }
}

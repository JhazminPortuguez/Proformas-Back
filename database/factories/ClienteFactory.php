<?php

namespace Database\Factories;

use App\Enums\EstadoCliente;
use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre_razon' => fake()->company(),
            'nit_ci' => fake()->numerify('#######'),
            'telefono' => fake()->numerify('7#######'),
            'email' => fake()->unique()->safeEmail(),
            'direccion' => fake()->address(),
            'estado' => EstadoCliente::Activo,
        ];
    }

    public function prospecto(): static
    {
        return $this->state(fn () => ['estado' => EstadoCliente::Prospecto]);
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['estado' => EstadoCliente::Inactivo]);
    }
}

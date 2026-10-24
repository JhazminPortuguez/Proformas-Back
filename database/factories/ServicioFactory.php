<?php

namespace Database\Factories;

use App\Models\Servicio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Servicio>
 */
class ServicioFactory extends Factory
{
    protected $model = Servicio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(3, true),
            'descripcion' => fake()->sentence(),
            'precio_base' => fake()->randomFloat(2, 50, 5000),
            'duracion_estimada_min' => fake()->randomElement([30, 60, 90, 120, 240]),
            'categoria' => fake()->randomElement(['Instalación', 'Mantenimiento', 'Consultoría', 'Soporte']),
            'activo' => true,
            'visible_catalogo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }

    public function oculto(): static
    {
        return $this->state(fn () => ['visible_catalogo' => false]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\EstadoSolicitud;
use App\Models\Cliente;
use App\Models\SolicitudCotizacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SolicitudCotizacion>
 */
class SolicitudCotizacionFactory extends Factory
{
    protected $model = SolicitudCotizacion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'descripcion' => fake()->sentence(10),
            'estado' => EstadoSolicitud::Pendiente,
        ];
    }

    public function atendida(): static
    {
        return $this->state(fn () => ['estado' => EstadoSolicitud::Atendida]);
    }

    public function rechazada(): static
    {
        return $this->state(fn () => ['estado' => EstadoSolicitud::Rechazada]);
    }
}

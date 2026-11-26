<?php

namespace Database\Factories;

use App\Enums\AlcancePromocion;
use App\Enums\TipoPromocion;
use App\Models\Promocion;
use App\Support\FechaBolivia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promocion>
 */
class PromocionFactory extends Factory
{
    protected $model = Promocion::class;

    public function definition(): array
    {
        $hoy = FechaBolivia::hoy();

        return [
            'nombre' => 'Promo '.$this->faker->unique()->words(2, true),
            'tipo' => TipoPromocion::Porcentaje,
            'valor' => 10,
            'aplica_a' => AlcancePromocion::Cualquiera,
            'servicio_id' => null,
            'producto_id' => null,
            'vigente_desde' => $hoy->subDays(5)->toDateString(),
            'vigente_hasta' => $hoy->addDays(30)->toDateString(),
            'activa' => true,
        ];
    }

    public function porcentaje(float $valor): static
    {
        return $this->state(['tipo' => TipoPromocion::Porcentaje, 'valor' => $valor]);
    }

    public function montoFijo(float $valor): static
    {
        return $this->state(['tipo' => TipoPromocion::MontoFijo, 'valor' => $valor]);
    }

    public function dosPorUno(): static
    {
        return $this->state(['tipo' => TipoPromocion::DosPorUno, 'valor' => null]);
    }

    public function vencida(): static
    {
        $hoy = FechaBolivia::hoy();

        return $this->state([
            'vigente_desde' => $hoy->subDays(30)->toDateString(),
            'vigente_hasta' => $hoy->subDay()->toDateString(),
        ]);
    }

    public function inactiva(): static
    {
        return $this->state(['activa' => false]);
    }

    public function paraServicio(int $servicioId): static
    {
        return $this->state(['aplica_a' => AlcancePromocion::Servicio, 'servicio_id' => $servicioId, 'producto_id' => null]);
    }

    public function paraProducto(int $productoId): static
    {
        return $this->state(['aplica_a' => AlcancePromocion::Producto, 'producto_id' => $productoId, 'servicio_id' => null]);
    }
}

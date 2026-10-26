<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'productos';

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio_unitario',
        'unidad_medida',
        'stock_actual',
        'stock_minimo',
        'costo_promedio',
        'activo',
        'empresa_id',
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
            'stock_actual' => 'decimal:3',
            'stock_minimo' => 'decimal:3',
            'costo_promedio' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProformaItem::class, 'producto_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id');
    }

    public function stockBajo(): bool
    {
        return Dinero::comparar(
            $this->stock_actual ?? 0,
            $this->stock_minimo ?? 0
        ) <= 0;
    }

    /**
     * Valor del stock al costo promedio, redondeado a centavos.
     */
    public function valorStock(): string
    {
        return Dinero::redondear(
            bcmul(
                Dinero::cantidad($this->stock_actual),
                Dinero::redondear($this->costo_promedio),
                6,
            )
        );
    }
}

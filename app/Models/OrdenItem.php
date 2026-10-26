<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoProformaItem;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenItem extends Model
{
    use HasFactory;

    protected $table = 'orden_items';

    protected $fillable = [
        'orden_id',
        'tipo',
        'servicio_id',
        'producto_id',
        'descripcion',
        'unidad_medida',
        'cantidad',
        'cantidad_consumida',
        'consumido_at',
        'precio_unitario',
        'total_linea',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoProformaItem::class,
            'cantidad' => 'decimal:3',
            'cantidad_consumida' => 'decimal:3',
            'consumido_at' => 'datetime',
            'precio_unitario' => 'decimal:2',
            'total_linea' => 'decimal:2',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'orden_id');
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'orden_item_id');
    }

    /**
     * Cantidad aún pendiente de consumir, con precisión de cantidades.
     */
    public function cantidadPendiente(): string
    {
        return Dinero::cantidad(
            bcsub(
                Dinero::cantidad($this->cantidad),
                Dinero::cantidad($this->cantidad_consumida),
                3,
            )
        );
    }

    public function estaConsumido(): bool
    {
        return Dinero::comparar($this->cantidadPendiente(), 0) <= 0
            || $this->consumido_at !== null;
    }
}

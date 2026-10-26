<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoMovimientoInventario;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoInventario extends Model
{
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'orden_id',
        'orden_item_id',
        'tipo',
        'cantidad',
        'costo_unitario',
        'stock_anterior',
        'stock_nuevo',
        'motivo',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoInventario::class,
            'cantidad' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
            'stock_anterior' => 'decimal:3',
            'stock_nuevo' => 'decimal:3',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'orden_id');
    }

    public function ordenItem(): BelongsTo
    {
        return $this->belongsTo(OrdenItem::class, 'orden_item_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

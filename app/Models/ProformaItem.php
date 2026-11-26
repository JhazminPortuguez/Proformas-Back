<?php

namespace App\Models;

use App\Enums\TipoProformaItem;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProformaItem extends Model
{
    use HasFactory;

    protected $table = 'proforma_items';

    protected $fillable = [
        'proforma_id',
        'tipo',
        'producto_id',
        'servicio_id',
        'nombre',
        'descripcion',
        'unidad_medida',
        'cantidad',
        'precio_unitario',
        'total_linea',
        'promocion_id',
        'promocion_nombre',
        'promocion_tipo',
        'promocion_valor',
        'descuento_promocion',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoProformaItem::class,
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'total_linea' => 'decimal:2',
            'promocion_valor' => 'decimal:2',
            'descuento_promocion' => 'decimal:2',
        ];
    }

    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Proforma::class, 'proforma_id');
    }

    public function promocion(): BelongsTo
    {
        return $this->belongsTo(Promocion::class, 'promocion_id');
    }

    /** Importe antes de la promoción: cantidad × precio unitario. */
    public function importeBruto(): string
    {
        return Dinero::sumar($this->total_linea, $this->descuento_promocion);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }
}

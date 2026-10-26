<?php

namespace App\Models;

use App\Enums\TipoProformaItem;
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
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoProformaItem::class,
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'total_linea' => 'decimal:2',
        ];
    }

    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Proforma::class, 'proforma_id');
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

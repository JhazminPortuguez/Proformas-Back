<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProformaItem extends Model
{
    protected $table = 'proforma_items';

    protected $fillable = [
        'proforma_id',
        'producto_id',
        'descripcion',
        'cantidad',
        'precio_unitario',
        'total_linea',
    ];

    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Proforma::class, 'proforma_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}

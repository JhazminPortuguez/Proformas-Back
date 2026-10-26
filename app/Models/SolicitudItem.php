<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudItem extends Model
{
    use HasFactory;

    protected $table = 'solicitud_items';

    protected $fillable = [
        'solicitud_id',
        'descripcion',
        'cantidad',
        'unidad_medida',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCotizacion::class, 'solicitud_id');
    }
}

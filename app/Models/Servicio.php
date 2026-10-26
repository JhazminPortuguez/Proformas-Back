<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Database\Factories\ServicioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Servicio extends Model
{
    /** @use HasFactory<ServicioFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'servicios';

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio_base',
        'duracion_estimada_min',
        'categoria',
        'activo',
        'visible_catalogo',
        'empresa_id',
    ];

    protected function casts(): array
    {
        return [
            'precio_base' => 'decimal:2',
            'duracion_estimada_min' => 'integer',
            'activo' => 'boolean',
            'visible_catalogo' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProformaItem::class, 'servicio_id');
    }
}

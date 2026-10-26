<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoOrden;
use App\Enums\TipoOrdenEvento;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenEvento extends Model
{
    use HasFactory;

    protected $table = 'orden_eventos';

    protected $fillable = [
        'orden_id',
        'tipo',
        'descripcion',
        'estado_anterior',
        'estado_nuevo',
        'user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoOrdenEvento::class,
            'estado_anterior' => EstadoOrden::class,
            'estado_nuevo' => EstadoOrden::class,
            'metadata' => 'array',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'orden_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

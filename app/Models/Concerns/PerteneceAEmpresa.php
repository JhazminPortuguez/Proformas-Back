<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Empresa;
use App\Support\EmpresaActual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait PerteneceAEmpresa
{
    protected static function bootPerteneceAEmpresa(): void
    {
        static::addGlobalScope('empresa', function (Builder $builder): void {
            $modelo = $builder->getModel();

            $builder->where(
                $modelo->qualifyColumn('empresa_id'),
                EmpresaActual::id()
            );
        });

        static::creating(function (Model $modelo): void {
            if ($modelo->getAttribute('empresa_id') === null) {
                $modelo->setAttribute('empresa_id', EmpresaActual::id());
            }
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}

<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Cycle extends Model
{
    protected $table = 'cycles_electoraux';

    protected $fillable = [
        'libelle', 'type', 'date_scrutin', 'statut',
        'majoration_distribuee', 'reserve_centrale', 'circuit_defaut', 'clos_le',
    ];

    protected function casts(): array
    {
        return [
            'date_scrutin'          => 'date',
            'clos_le'               => 'datetime',
            'majoration_distribuee' => 'decimal:2',
            'reserve_centrale'      => 'decimal:2',
        ];
    }

    public function estOuvert(): bool
    {
        return $this->statut === 'ouvert';
    }

    public function scopeOuverts(Builder $query): Builder
    {
        return $query->where('statut', 'ouvert');
    }
}

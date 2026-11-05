<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestitutionLigne extends Model
{
    protected $table = 'restitution_lignes';

    // Les quantités déclarées, reçues et contrôlées s'écrivent par les services de restitution.
    protected $fillable = ['restitution_id', 'materiel_id', 'attendu'];

    protected $attributes = [
        'attendu' => 0,
        'declare_restitue' => 0,
        'recu_central' => 0,
        'bon' => 0,
        'a_reparer' => 0,
        'reforme' => 0,
    ];

    protected function casts(): array
    {
        return [
            'restitution_id' => 'integer',
            'materiel_id' => 'integer',
            'attendu' => 'integer',
            'declare_restitue' => 'integer',
            'recu_central' => 'integer',
            'bon' => 'integer',
            'a_reparer' => 'integer',
            'reforme' => 'integer',
            'reforme_decidee_par' => 'integer',
        ];
    }

    public function restitution(): BelongsTo
    {
        return $this->belongsTo(Restitution::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }
}

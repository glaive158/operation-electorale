<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstantaneLigne extends Model
{
    protected $table = 'instantane_lignes';

    protected $fillable = [
        'instantane_id', 'commune_id', 'pays', 'ville', 'departement_id',
        'contexte_region', 'contexte_departement', 'libelle_commune',
        'electeurs', 'bureaux_vote', 'lieux_vote', 'commissions', 'rapprochee', 'destinataire_id',
    ];

    protected function casts(): array
    {
        return [
            'commune_id'     => 'integer',
            'departement_id' => 'integer',
            'electeurs'      => 'integer',
            'bureaux_vote'   => 'integer',
            'lieux_vote'     => 'integer',
            'commissions'    => 'integer',
            'rapprochee'     => 'boolean',
        ];
    }

    public function instantane(): BelongsTo
    {
        return $this->belongsTo(InstantaneEffectifs::class, 'instantane_id');
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class);
    }
}

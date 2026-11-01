<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrespondanceGeo extends Model
{
    protected $table = 'correspondances_geo';

    protected $fillable = [
        'contexte_region', 'contexte_departement', 'libelle_commune', 'commune_id',
        'statut', 'methode', 'valide_par', 'valide_le', 'motif',
    ];

    protected function casts(): array
    {
        return ['valide_le' => 'datetime', 'commune_id' => 'integer', 'valide_par' => 'integer'];
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }
}

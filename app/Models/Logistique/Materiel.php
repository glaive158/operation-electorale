<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Materiel extends Model
{
    protected $table = 'materiels';

    public const CATEGORIES = [
        'revision'       => 'Révision des listes',
        'materiel_lourd' => 'Matériel lourd',
        'imprime'        => 'Imprimés',
    ];

    protected $fillable = [
        'libelle', 'categorie', 'regroupement', 'ordre', 'unite_stock',
        'unite_distribution', 'facteur_conditionnement',
        'indivisible', 'reutilisable', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'ordre'                   => 'integer',
            'facteur_conditionnement' => 'integer',
            'indivisible'             => 'boolean',
            'reutilisable'            => 'boolean',
            'actif'                   => 'boolean',
        ];
    }

    public function scopeActifs(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /** Présente si cet article est le bulletin de vote d'une entité (candidat, liste ou option). */
    public function entiteBulletin(): HasOne
    {
        return $this->hasOne(EntiteBulletin::class);
    }
}

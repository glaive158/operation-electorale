<?php

namespace App\Models\Logistique;

use App\Services\Logistique\NomGeo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PosteEtranger extends Model
{
    protected $table = 'postes_etranger';

    protected $fillable = ['pays', 'ville', 'type', 'nom', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function scopeActifs(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /**
     * Poste qui reçoit une ville étrangère : celui de la ville s'il existe, sinon le consulat
     * du pays (le plus ancien), sinon l'ambassade, sinon aucun.
     */
    public static function posteParDefaut(string $pays, string $ville): ?self
    {
        // Pays et ville comparés sous forme normalisée : le fichier écrit « PARIS », le poste « Paris ».
        $clePays = NomGeo::clePays($pays);
        $cleVille = NomGeo::normaliser($ville);
        $postes = static::actifs()->orderBy('id')->get()
            ->filter(fn (self $poste) => NomGeo::clePays($poste->pays) === $clePays)->values();

        return $postes->first(fn (self $poste) => NomGeo::normaliser($poste->ville) === $cleVille)
            ?? $postes->firstWhere('type', 'consulat')
            ?? $postes->firstWhere('type', 'ambassade');
    }
}

<?php

namespace App\Models\Logistique;

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
        $postes = static::actifs()->where('pays', $pays)->orderBy('id')->get();

        return $postes->firstWhere('ville', $ville)
            ?? $postes->firstWhere('type', 'consulat')
            ?? $postes->firstWhere('type', 'ambassade');
    }
}

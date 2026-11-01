<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Clé de répartition d'un article pour un cycle : sur quoi, à quel ratio, avec quels minimum et arrondi. */
class CleRepartition extends Model
{
    public const BASES = ['electeur', 'bureau_vote', 'lieu_vote', 'ca', 'circonscription', 'forfait'];

    public const ARRONDIS = ['unite', 'dizaine', 'cinquantaine', 'centaine'];

    protected $table = 'cles_repartition';

    protected $fillable = [
        'cycle_id', 'materiel_id', 'base', 'par_entite', 'ratio', 'cle_affichee',
        'majoration_distribuee', 'reserve_centrale', 'minimum', 'minimum_par_commune',
        'arrondi', 'arrondi_conditionnement',
    ];

    protected function casts(): array
    {
        return [
            'par_entite'              => 'boolean',
            'ratio'                   => 'decimal:4',
            'majoration_distribuee'   => 'decimal:2',
            'reserve_centrale'        => 'decimal:2',
            'minimum'                 => 'integer',
            'minimum_par_commune'     => 'boolean',
            'arrondi_conditionnement' => 'boolean',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }

    /** Majoration distribuée (%) : la dérogation de la clé, sinon la valeur du cycle. Chaîne décimale à 2 décimales. */
    public function majorationEffective(): string
    {
        return $this->valeurEffective('majoration_distribuee');
    }

    /** Réserve centrale (%) : la dérogation de la clé, sinon la valeur du cycle. Chaîne décimale à 2 décimales. */
    public function reserveEffective(): string
    {
        return $this->valeurEffective('reserve_centrale');
    }

    private function valeurEffective(string $champ): string
    {
        $valeur = $this->{$champ};
        if ($valeur === null) {
            // Cycle déjà chargé (calcul en masse) : pas de requête par clé. Sinon on relit la base,
            // le cycle pouvant avoir été créé sans ses colonnes à défaut.
            $valeur = $this->relationLoaded('cycle')
                ? $this->cycle?->{$champ}
                : Cycle::whereKey($this->cycle_id)->value($champ);
        }

        return bcadd((string) ($valeur ?? '0'), '0', 2);
    }
}

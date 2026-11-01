<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Ligne du grand livre de stock : jamais modifiée ni supprimée.
 * Toute correction passe par un mouvement compensatoire.
 */
class MouvementStock extends Model
{
    protected $table = 'mouvements_stock';

    public const TYPES = [
        'inventaire_initial', 'entree_fournisseur', 'sortie_expedition',
        'reintegration', 'reclassement', 'reforme', 'compensation',
    ];

    public const ETATS = ['bon', 'a_reparer'];

    protected $fillable = [
        'materiel_id', 'type', 'etat', 'quantite', 'reference_type', 'reference_id',
        'mouvement_compense_id', 'motif', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantite'              => 'integer',
            'reference_id'          => 'integer',
            'mouvement_compense_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $refus = static function (): never {
            throw new LogicException('Un mouvement de stock ne se modifie pas : passer un mouvement compensatoire.');
        };

        static::updating($refus);
        static::deleting($refus);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }
}

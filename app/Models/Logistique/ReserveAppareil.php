<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantité du solde du préfet réservée à l'appareil pour un article d'une décharge. `quantite` est ce qui reste
 * disponible à l'appareil tant que la ligne est active ; le statut ne change que par les services.
 */
class ReserveAppareil extends Model
{
    protected $table = 'reserves_appareil';

    public const STATUTS = [
        'active' => 'Active',
        'consommee' => 'Consommée',
        'liberee' => 'Libérée',
    ];

    protected $fillable = ['appareil_id', 'decharge_id', 'materiel_id', 'quantite_initiale', 'quantite'];

    protected $attributes = [
        'statut' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'appareil_id' => 'integer',
            'decharge_id' => 'integer',
            'materiel_id' => 'integer',
            'quantite_initiale' => 'integer',
            'quantite' => 'integer',
        ];
    }

    public function appareil(): BelongsTo
    {
        return $this->belongsTo(Appareil::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }
}

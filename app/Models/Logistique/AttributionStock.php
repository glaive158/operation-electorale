<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantité de stock libre mise de côté pour un cycle : aucun autre cycle ne
 * peut la réserver. Se modifie uniquement par StockService.
 */
class AttributionStock extends Model
{
    protected $table = 'attributions_stock';

    protected $fillable = ['cycle_id', 'materiel_id', 'quantite'];

    protected function casts(): array
    {
        return [
            'cycle_id'    => 'integer',
            'materiel_id' => 'integer',
            'quantite'    => 'integer',
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
}

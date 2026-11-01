<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock physique promis à une expédition d'un cycle. Une réservation active
 * diminue le stock libre ; elle est consommée par la sortie ou libérée.
 */
class ReservationStock extends Model
{
    protected $table = 'reservations_stock';

    public const STATUTS = ['active', 'consommee', 'liberee'];

    protected $fillable = [
        'cycle_id', 'materiel_id', 'quantite', 'quantite_sur_attribution', 'statut',
        'reference_type', 'reference_id', 'user_id',
    ];

    protected $attributes = [
        'statut' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'cycle_id'                 => 'integer',
            'materiel_id'              => 'integer',
            'quantite'                 => 'integer',
            'quantite_sur_attribution' => 'integer',
            'reference_id'             => 'integer',
            'user_id'                  => 'integer',
        ];
    }

    public function estActive(): bool
    {
        return $this->statut === 'active';
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

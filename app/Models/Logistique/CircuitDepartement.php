<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dérogation au circuit de livraison du cycle pour un département. */
class CircuitDepartement extends Model
{
    protected $table = 'circuits_departement';

    public const CIRCUITS = ['via_prefet', 'direct'];

    protected $fillable = ['cycle_id', 'departement_id', 'circuit', 'user_id'];

    protected function casts(): array
    {
        return [
            'cycle_id'       => 'integer',
            'departement_id' => 'integer',
            'user_id'        => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

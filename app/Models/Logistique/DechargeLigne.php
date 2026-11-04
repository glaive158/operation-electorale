<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DechargeLigne extends Model
{
    protected $table = 'decharge_lignes';

    // deja_engage et reservation_id sont posés à la validation, par DechargeService.
    protected $fillable = ['decharge_id', 'materiel_id', 'dotation_approuvee', 'quantite', 'cle_affichee', 'observations'];

    protected $attributes = [
        'deja_engage' => 0,
    ];

    protected function casts(): array
    {
        return [
            'decharge_id' => 'integer',
            'materiel_id' => 'integer',
            'dotation_approuvee' => 'integer',
            'deja_engage' => 'integer',
            'quantite' => 'integer',
            'reservation_id' => 'integer',
        ];
    }

    public function decharge(): BelongsTo
    {
        return $this->belongsTo(Decharge::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(ReservationStock::class, 'reservation_id');
    }
}

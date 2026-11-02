<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantité à remplacer pour un destinataire (matériel perdu, détérioré ou non conforme) : elle
 * s'ajoute à la dotation du plan approuvé dans le besoin d'expédition.
 */
class BesoinRemplacement extends Model
{
    protected $table = 'besoins_remplacement';

    protected $fillable = ['cycle_id', 'destinataire_id', 'materiel_id', 'quantite', 'motif', 'autorise_par'];

    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'destinataire_id' => 'integer',
            'materiel_id' => 'integer',
            'quantite' => 'integer',
            'autorise_par' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }

    public function autorisePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorise_par');
    }
}

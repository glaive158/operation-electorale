<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandeLigne extends Model
{
    protected $table = 'commande_lignes';

    protected $fillable = [
        'commande_id', 'materiel_id', 'cycle_id', 'quantite_commandee',
        'quantite_acceptee', 'quantite_rejetee', 'quantite_abandonnee',
    ];

    protected $attributes = [
        'quantite_acceptee'   => 0,
        'quantite_rejetee'    => 0,
        'quantite_abandonnee' => 0,
    ];

    protected function casts(): array
    {
        return [
            'commande_id'         => 'integer',
            'materiel_id'         => 'integer',
            'cycle_id'            => 'integer',
            'quantite_commandee'  => 'integer',
            'quantite_acceptee'   => 'integer',
            'quantite_rejetee'    => 'integer',
            'quantite_abandonnee' => 'integer',
        ];
    }

    /**
     * Ce qui reste dû par le fournisseur : le rejeté reste dû tant qu'il n'est pas abandonné.
     */
    public function resteARecevoir(): int
    {
        return $this->quantite_commandee - $this->quantite_acceptee - $this->quantite_abandonnee;
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }
}

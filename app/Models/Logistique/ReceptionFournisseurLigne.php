<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceptionFournisseurLigne extends Model
{
    protected $table = 'reception_fournisseur_lignes';

    protected $fillable = ['reception_fournisseur_id', 'commande_ligne_id', 'acceptee', 'rejetee'];

    protected function casts(): array
    {
        return [
            'reception_fournisseur_id' => 'integer',
            'commande_ligne_id'        => 'integer',
            'acceptee'                 => 'integer',
            'rejetee'                  => 'integer',
        ];
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(ReceptionFournisseur::class, 'reception_fournisseur_id');
    }

    public function ligneCommande(): BelongsTo
    {
        return $this->belongsTo(CommandeLigne::class, 'commande_ligne_id');
    }
}

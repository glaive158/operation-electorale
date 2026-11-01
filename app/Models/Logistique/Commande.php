<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commande à un fournisseur, livrable en plusieurs parties.
 */
class Commande extends Model
{
    protected $table = 'commandes';

    public const STATUTS = ['brouillon', 'confirmee', 'partiellement_recue', 'recue', 'annulee'];

    public const LIBELLES_STATUT = [
        'brouillon'           => 'Brouillon',
        'confirmee'           => 'Confirmée',
        'partiellement_recue' => 'Partiellement reçue',
        'recue'               => 'Reçue',
        'annulee'             => 'Annulée',
    ];

    protected $fillable = ['reference', 'fournisseur', 'date_commande', 'statut', 'user_id'];

    protected $attributes = [
        'statut' => 'brouillon',
    ];

    protected function casts(): array
    {
        return [
            'date_commande' => 'date',
            'user_id'       => 'integer',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(CommandeLigne::class)->orderBy('id');
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(ReceptionFournisseur::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

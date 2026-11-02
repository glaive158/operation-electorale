<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Remise : passage du matériel d'une décharge d'une main à l'autre (chargement au magasin, livraison
 * au destinataire, redistribution). Le statut ne change que par les services de remise et de déclaration.
 */
class Remise extends Model
{
    protected $table = 'remises';

    public const ETAPES = [
        'chargement' => 'Chargement',
        'livraison' => 'Livraison',
        'redistribution' => 'Redistribution',
    ];

    public const STATUTS = [
        'en_attente' => 'En attente',
        'recue' => 'Reçue',
        'a_verifier' => 'À vérifier',
        'annulee' => 'Annulée',
    ];

    // Le statut et l'annulation ne sont pas assignables en masse.
    protected $fillable = [
        'decharge_id', 'etape', 'de_type', 'de_id', 'a_type', 'a_id',
        'transporteur_nom', 'transporteur_qualite', 'transporteur_societe', 'bordereau_numero', 'preparee_par',
    ];

    protected $attributes = [
        'statut' => 'en_attente',
    ];

    protected function casts(): array
    {
        return [
            'decharge_id' => 'integer',
            'de_id' => 'integer',
            'a_id' => 'integer',
            'preparee_par' => 'integer',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(RemiseLigne::class)->orderBy('materiel_id');
    }

    public function decharge(): BelongsTo
    {
        return $this->belongsTo(Decharge::class);
    }

    public function preparePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparee_par');
    }

    public function declarations(): HasMany
    {
        return $this->hasMany(Declaration::class)->orderBy('id');
    }
}

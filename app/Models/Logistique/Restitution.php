<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Restitution du matériel réutilisable d'un détenteur (agent ou destinataire) à l'issue d'un cycle.
 * Le statut ne change que par RestitutionService.
 */
class Restitution extends Model
{
    protected $table = 'restitutions';

    public const DETENTEURS = [
        'agent' => 'Agent',
        'destinataire' => 'Destinataire',
    ];

    public const STATUTS = [
        'a_preparer' => 'À préparer',
        'en_retour' => 'En retour',
        'recue_central' => 'Reçue au central',
        'controlee' => 'Contrôlée',
    ];

    // Le statut, l'agent de retour et l'auteur de l'ouverture ne sont pas assignables en masse.
    protected $fillable = ['cycle_id', 'detenteur_type', 'detenteur_id'];

    protected $attributes = [
        'statut' => 'a_preparer',
    ];

    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'detenteur_id' => 'integer',
            'agent_id' => 'integer',
            'ouverte_par' => 'integer',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(RestitutionLigne::class)->orderBy('materiel_id');
    }

    public function declarations(): HasMany
    {
        return $this->hasMany(RestitutionDeclaration::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RestitutionDocument::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function ouvertePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ouverte_par');
    }

    public function estOuverte(): bool
    {
        return $this->statut !== 'controlee';
    }
}

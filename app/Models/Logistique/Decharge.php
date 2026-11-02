<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Décharge : document de remise du matériel d'une catégorie à un destinataire, préparé en brouillon
 * d'après le plan de dotation approuvé, puis validé, acheminé et réclamé indépendamment.
 */
class Decharge extends Model
{
    protected $table = 'decharges';

    public const CATEGORIES = [
        'revision' => 'Révision',
        'materiel_lourd' => 'Matériel lourd',
        'imprime' => 'Imprimés',
    ];

    public const NATURES = [
        'initiale' => 'Initiale',
        'reliquat' => 'Reliquat',
        'remplacement' => 'Remplacement',
    ];

    // Les statuts, le numéro, le circuit et la validation ne sont pas assignables en masse : ils passent par DechargeService.
    protected $fillable = [
        'cycle_id', 'destinataire_id', 'categorie', 'nature', 'decharge_remplacee_id', 'plan_dotation_id', 'prepare_par',
        'besoin_remplacement_id',
    ];

    protected $attributes = [
        'statut_document' => 'brouillon',
        'statut_acheminement' => 'a_preparer',
        'statut_reclamation' => 'aucune',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $decharge) {
            $decharge->identifiant ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'donnees_gelees' => 'array',
            'valide_le' => 'datetime',
            'annule_le' => 'datetime',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(DechargeLigne::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DechargeDocument::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanDotation::class, 'plan_dotation_id');
    }

    public function remisA(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class, 'remis_a_id');
    }

    public function remplacee(): BelongsTo
    {
        return $this->belongsTo(self::class, 'decharge_remplacee_id');
    }

    public function remplacante(): HasOne
    {
        return $this->hasOne(self::class, 'decharge_remplacee_id')->latestOfMany();
    }

    public function besoinRemplacement(): BelongsTo
    {
        return $this->belongsTo(BesoinRemplacement::class, 'besoin_remplacement_id');
    }

    public function reclamations(): HasMany
    {
        return $this->hasMany(Reclamation::class);
    }

    public function preparePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepare_par');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function annulePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annule_par');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function estBrouillon(): bool
    {
        return $this->statut_document === 'brouillon';
    }

    /** Vrai dès que le matériel a commencé son acheminement (la décharge ne se corrige plus à la main). */
    public function estPartie(): bool
    {
        return $this->statut_acheminement !== 'a_preparer';
    }
}

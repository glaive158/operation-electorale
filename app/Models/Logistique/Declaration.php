<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Déclaration de réception d'une remise, par la tablette de l'agent ou par le compte de l'autorité
 * destinataire. Jamais modifiée ni supprimée dans ses quantités : seul son statut évolue, par le
 * rapprochement (DeclarationService) ou l'arbitrage.
 */
class Declaration extends Model
{
    protected $table = 'declarations';

    public const MODES = [
        'tablette' => 'tablette',
        'compte' => 'compte',
    ];

    public const STATUTS = [
        'valide' => 'Valide',
        'retenue' => 'Retenue',
        'concordante' => 'Concordante',
        'a_verifier' => 'À vérifier',
        'ecartee' => 'Écartée',
        'remplacee' => 'Remplacée',
    ];

    public const ANOMALIES = [
        'excedent' => 'Quantité reçue supérieure à la remise',
        'version_annulee' => 'Décharge annulée depuis',
        'affectation_retiree' => 'Agent n\'étant plus affecté',
        'divergence' => 'Divergence avec l\'autre déclaration',
    ];

    // Le statut et les anomalies ne sont pas assignables en masse.
    protected $fillable = [
        'remise_id', 'mode', 'signataire_nom', 'signataire_qualite', 'user_id', 'decharge_id',
        'horodatage_terminal', 'cle_idempotence', 'empreinte_contenu',
    ];

    protected $attributes = [
        'statut' => 'valide',
    ];

    protected function casts(): array
    {
        return [
            'remise_id' => 'integer',
            'user_id' => 'integer',
            'decharge_id' => 'integer',
            'horodatage_terminal' => 'datetime',
            'anomalies' => 'array',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(DeclarationLigne::class)->orderBy('materiel_id');
    }

    public function remise(): BelongsTo
    {
        return $this->belongsTo(Remise::class);
    }

    public function decharge(): BelongsTo
    {
        return $this->belongsTo(Decharge::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Vrai tant que la déclaration compte dans le rapprochement (ni écartée ni remplacée). */
    public function estActive(): bool
    {
        return ! in_array($this->statut, ['ecartee', 'remplacee'], true);
    }
}

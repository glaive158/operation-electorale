<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Déclaration de ce que remet un détenteur lors de la restitution, sur le modèle de Declaration :
 * jamais modifiée dans ses quantités, seul son statut évolue.
 */
class RestitutionDeclaration extends Model
{
    protected $table = 'restitution_declarations';

    // Le statut, les anomalies, la vérification d'identité et la contestation ne sont pas assignables en masse.
    protected $fillable = [
        'restitution_id', 'mode', 'signataire_nom', 'signataire_qualite', 'user_id',
        'horodatage_terminal', 'cle_idempotence', 'empreinte_contenu',
    ];

    protected $attributes = [
        'statut' => 'valide',
    ];

    protected function casts(): array
    {
        return [
            'restitution_id' => 'integer',
            'user_id' => 'integer',
            'signataire_user_id' => 'integer',
            'delegation_id' => 'integer',
            'gps_precision' => 'integer',
            'horodatage_terminal' => 'datetime',
            'anomalies' => 'array',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(RestitutionDeclarationLigne::class)->orderBy('materiel_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(RestitutionDeclarationPiece::class)->orderBy('id');
    }

    public function restitution(): BelongsTo
    {
        return $this->belongsTo(Restitution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signataireUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signataire_user_id');
    }

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(Delegation::class);
    }

    /** Libellé de la vérification d'identité : les mêmes que pour les déclarations de remise (Declaration::VERIFICATIONS). */
    public function libelleVerification(): string
    {
        return Declaration::VERIFICATIONS[$this->verification_identite ?? 'non_verifiee'] ?? Declaration::VERIFICATIONS['non_verifiee'];
    }

    /** Vrai seulement si l'identité du signataire est établie (code, appareil ou connexion au compte). */
    public function identiteEtablie(): bool
    {
        return in_array($this->verification_identite, Declaration::VERIFICATIONS_ETABLIES, true);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Operation extends Model
{
    protected $fillable = [
        'type','statut',
        'nin_demandeur','nom_demandeur','prenom_demandeur',
        'datenaiss_demandeur','lieunaiss_demandeur',
        'tel_demandeur','adresse_demandeur',
        'militaire','handicap',
        'nin_electeur_radie','nom_electeur_radie','prenom_electeur_radie',
        'numelec_electeur_radie','motif_radiation',
        'commune_id','commune_nom','departement_nom','adresse_electorale',
        'statut_changement','avec_modification',
        'user_id','region_id','departement_id','arrondissement_id','commune_commission_id',
        'commentaire','documents_complets',
    ];

    protected function casts(): array
    {
        return [
            'militaire'           => 'boolean',
            'handicap'            => 'boolean',
            'avec_modification'   => 'boolean',
            'documents_complets'  => 'boolean',
            'datenaiss_demandeur' => 'date',
        ];
    }

    public static array $typeLabels = [
        'inscription'  => 'Inscription',
        'modification' => 'Modification',
        'changement'   => 'Changement de statut',
        'radiation'    => 'Radiation',
    ];

    public static array $statutLabels = [
        'en_attente' => 'En attente',
        'validee'    => 'Validée',
        'rejetee'    => 'Rejetée',
    ];

    public static array $typeColors = [
        'inscription'  => 'green',
        'modification' => 'yellow',
        'changement'   => 'blue',
        'radiation'    => 'red',
    ];

    public static array $statutColors = [
        'en_attente' => 'yellow',
        'validee'    => 'green',
        'rejetee'    => 'red',
    ];

    public function getTypeLabelAttribute(): string
    {
        return self::$typeLabels[$this->type] ?? $this->type;
    }

    public function getStatutLabelAttribute(): string
    {
        return self::$statutLabels[$this->statut] ?? $this->statut;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OperationDocument::class);
    }
}

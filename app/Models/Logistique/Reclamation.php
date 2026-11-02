<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réclamation d'une autorité de terrain sur une décharge partie : article manquant ou défectueux.
 * Le statut et l'issue ne changent que par ReclamationService.
 */
class Reclamation extends Model
{
    protected $table = 'reclamations';

    public const MOTIFS = [
        'manquant' => 'Manquant',
        'defectueux' => 'Défectueux',
    ];

    public const STATUTS = [
        'ouverte' => 'Ouverte',
        'en_traitement' => 'En traitement',
        'resolue' => 'Résolue',
    ];

    public const ISSUES = [
        'remplacement_autorise' => 'Remplacement autorisé',
        'article_retrouve' => 'Article retrouvé',
        'erreur_declaration' => 'Erreur de déclaration',
        'cloture_sans_remplacement' => 'Clôture sans remplacement',
    ];

    protected $fillable = ['decharge_id', 'materiel_id', 'motif', 'quantite', 'message', 'ouverte_par'];

    protected $attributes = [
        'statut' => 'ouverte',
    ];

    protected function casts(): array
    {
        return [
            'decharge_id' => 'integer',
            'materiel_id' => 'integer',
            'quantite' => 'integer',
            'ouverte_par' => 'integer',
            'traitee_par' => 'integer',
            'resolue_le' => 'datetime',
        ];
    }

    public function decharge(): BelongsTo
    {
        return $this->belongsTo(Decharge::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }

    public function ouvertePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ouverte_par');
    }

    public function traiteePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traitee_par');
    }
}

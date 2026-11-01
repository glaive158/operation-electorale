<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Copie versionnée des effectifs du fichier électoral pour un cycle : tous les calculs de
 * dotation lisent cet instantané, jamais la base du recensement.
 */
class InstantaneEffectifs extends Model
{
    protected $table = 'instantanes_effectifs';

    // statut, fige_* , bilan et erreur ne sont pas assignables en masse : ils passent par InstantaneService.
    protected $fillable = ['cycle_id', 'version', 'source', 'user_id'];

    protected $attributes = [
        'statut' => 'en_cours',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'bilan'   => 'array',
            'fige_le' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(InstantaneLigne::class, 'instantane_id');
    }

    /** Seul un instantané figé alimente les calculs. */
    public function estUtilisable(): bool
    {
        return $this->statut === 'fige';
    }
}

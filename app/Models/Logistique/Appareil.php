<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Appareil (tablette) désigné par le central pour préparer, hors ligne, les redistributions d'une préfecture.
 * Il n'y en a qu'un d'actif par préfecture ; un appareil révoqué reste en base. Écrit par AppareilService seulement.
 */
class Appareil extends Model
{
    protected $table = 'appareils';

    protected $fillable = ['destinataire_id', 'identifiant', 'nom', 'designe_par'];

    protected $attributes = [
        'actif' => true,
    ];

    protected function casts(): array
    {
        return [
            'destinataire_id' => 'integer',
            'designe_par' => 'integer',
            'jeton_id' => 'integer',
            'actif' => 'boolean',
            'revoque_le' => 'datetime',
        ];
    }

    public function prefecture(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class, 'destinataire_id');
    }

    /** Le jeton de la tablette épinglé à la désignation (null : supprimé, l'appareil n'est plus reconnu). */
    public function jeton(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'jeton_id');
    }

    /** Le jeton épinglé existe et n'a pas expiré : l'appareil peut être reconnu. */
    public function estReconnaissable(): bool
    {
        $jeton = $this->jeton;

        return $jeton !== null && ($jeton->expires_at === null || $jeton->expires_at->isFuture());
    }

    public function designePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designe_par');
    }

    public function reserves(): HasMany
    {
        return $this->hasMany(ReserveAppareil::class);
    }
}

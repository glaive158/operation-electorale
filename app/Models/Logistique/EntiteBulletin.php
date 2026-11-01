<?php

namespace App\Models\Logistique;

use App\Services\Logistique\NomGeo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Candidat, liste ou option d'un cycle, portée par son propre article « bulletin de vote ».
 * L'entité est active tant que son article l'est.
 */
class EntiteBulletin extends Model
{
    public const TYPES = ['candidat', 'liste', 'option'];

    protected $table = 'entites_bulletin';

    protected $fillable = ['cycle_id', 'type', 'nom', 'ordre', 'national', 'materiel_id'];

    protected function casts(): array
    {
        return [
            'ordre'    => 'integer',
            'national' => 'boolean',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }

    public function perimetres(): HasMany
    {
        return $this->hasMany(EntiteBulletinPerimetre::class);
    }

    /** Vrai si l'entité est nationale, ou si la commune, le département ou le pays figure à son périmètre. */
    public function couvre(?int $departementId, ?int $communeId, ?string $pays): bool
    {
        if ($this->national) {
            return true;
        }

        $clePays = $pays === null ? null : NomGeo::clePays($pays);

        return $this->perimetres->contains(fn (EntiteBulletinPerimetre $p) => ($departementId !== null && $p->departement_id === $departementId)
            || ($communeId !== null && $p->commune_id === $communeId)
            || ($clePays !== null && $p->pays !== null && NomGeo::clePays($p->pays) === $clePays));
    }
}

<?php

namespace App\Models\Logistique;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Délégation de signature : l'autorité d'un destinataire confie à un adjoint la réception de ce qui lui est
 * remis. Valable de `du` (inclus) à la première des dates `au` et `revoquee_le` (exclue). Écrite par
 * DelegationService seulement ; ni modifiée ni supprimée : la révocation date la fin.
 */
class Delegation extends Model
{
    protected $table = 'delegations';

    /** Qualité affichée d'un délégué, d'après son rôle. */
    public const QUALITES = [
        'prefet' => 'Préfet',
        'sous_prefet' => 'Sous-préfet',
        'chef_poste' => 'Chef de poste',
    ];

    protected $fillable = [
        'destinataire_id', 'autorite_user_id', 'delegue_user_id', 'du', 'au', 'motif', 'cree_par',
    ];

    protected function casts(): array
    {
        return [
            'destinataire_id' => 'integer',
            'autorite_user_id' => 'integer',
            'delegue_user_id' => 'integer',
            'cree_par' => 'integer',
            'du' => 'datetime',
            'au' => 'datetime',
            'revoquee_le' => 'datetime',
        ];
    }

    /** Délégations en vigueur à l'instant : du <= instant < min(au, revoquee_le). */
    public function scopeActivesA(Builder $query, CarbonInterface $instant): Builder
    {
        return $query->where('du', '<=', $instant)
            ->where(fn (Builder $q) => $q->whereNull('au')->orWhere('au', '>', $instant))
            ->where(fn (Builder $q) => $q->whereNull('revoquee_le')->orWhere('revoquee_le', '>', $instant));
    }

    /** Fin effective : la première des dates de fin et de révocation (null : sans fin). */
    public function fin(): ?CarbonInterface
    {
        return collect([$this->au, $this->revoquee_le])->filter()->sort()->first();
    }

    public function estActiveA(CarbonInterface $instant): bool
    {
        $fin = $this->fin();

        return $this->du <= $instant && ($fin === null || $fin > $instant);
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class);
    }

    public function autorite(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorite_user_id');
    }

    public function delegue(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegue_user_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}

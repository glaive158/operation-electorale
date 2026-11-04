<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décision du responsable sur une déclaration contestée ou sur la clôture d'un écart. Une clôture
 * peut réduire le besoin d'expédition (quantite_expedition_annulee).
 */
class Arbitrage extends Model
{
    public const OBJETS = ['declaration', 'cloture_ecart'];

    protected $table = 'arbitrages';

    protected $fillable = [
        'cycle_id', 'objet', 'remise_id', 'declaration_id', 'destinataire_id', 'materiel_id', 'decision',
        'quantite_abandon_reception', 'quantite_expedition_annulee', 'motif', 'user_id',
    ];

    protected $attributes = [
        'quantite_abandon_reception' => 0,
        'quantite_expedition_annulee' => 0,
    ];

    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'remise_id' => 'integer',
            'declaration_id' => 'integer',
            'destinataire_id' => 'integer',
            'materiel_id' => 'integer',
            'quantite_abandon_reception' => 'integer',
            'quantite_expedition_annulee' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

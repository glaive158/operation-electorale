<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Version d'un plan de dotation d'un cycle, calculée sur un instantané figé :
 * brouillon, puis approuvée, puis remplacée par une version plus récente.
 */
class PlanDotation extends Model
{
    protected $table = 'plans_dotation';

    // statut, approuve_par et approuve_le ne sont pas assignables en masse : ils passent par PlanDotationService.
    protected $fillable = ['cycle_id', 'version', 'instantane_id', 'parametres', 'calcule_par', 'anomalies'];

    protected $attributes = [
        'statut' => 'brouillon',
    ];

    protected function casts(): array
    {
        return [
            'version'     => 'integer',
            'parametres'  => 'array',
            'anomalies'   => 'array',
            'approuve_le' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function instantane(): BelongsTo
    {
        return $this->belongsTo(InstantaneEffectifs::class, 'instantane_id');
    }

    public function calculePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calcule_par');
    }

    public function approuvePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(PlanDotationLigne::class);
    }

    /** Le plan approuvé en vigueur pour le cycle : celui que suivent les besoins. */
    public static function courantApprouve(Cycle $cycle): ?self
    {
        return self::where('cycle_id', $cycle->id)->where('statut', 'approuve')->first();
    }
}

<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanDotationLigne extends Model
{
    protected $table = 'plan_dotation_lignes';

    // La correction et l'approbation passent par PlanDotationService.
    protected $fillable = [
        'plan_dotation_id', 'destinataire_id', 'materiel_id', 'effectif', 'exact', 'majoration',
        'minimum', 'arrondi', 'dotation_calculee', 'anomalie',
    ];

    protected function casts(): array
    {
        return [
            'effectif'           => 'integer',
            'exact'              => 'decimal:4',
            'majoration'         => 'decimal:2',
            'minimum'            => 'integer',
            'dotation_calculee'  => 'integer',
            'correction'         => 'integer',
            'dotation_approuvee' => 'integer',
            'corrige_le'         => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanDotation::class, 'plan_dotation_id');
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }

    public function correcteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrige_par');
    }

    /** Quantité retenue à l'approbation du plan ; null tant que le plan n'est pas approuvé. */
    public function dotationApprouvee(): ?int
    {
        return $this->dotation_approuvee;
    }
}

<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerimetreDestinataire extends Model
{
    protected $table = 'perimetres_destinataires';

    protected $fillable = ['cycle_id', 'destinataire_id', 'commune_id', 'pays', 'ville'];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class);
    }

    /** Clé de la localité : « commune:{id} » ou « {pays}|{ville} ». */
    public function localite(): string
    {
        return $this->commune_id !== null
            ? "commune:{$this->commune_id}"
            : "{$this->pays}|{$this->ville}";
    }
}

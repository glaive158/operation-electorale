<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exemplaire officiel d'une fiche de restitution, archivé au moment de son émission avec son empreinte SHA-256.
 * Écrit uniquement par RestitutionPdfService.
 */
class RestitutionDocument extends Model
{
    public const EXEMPLAIRES = ['declaration', 'controle'];

    protected $table = 'restitution_documents';

    public $timestamps = false;

    protected $fillable = ['restitution_id', 'exemplaire', 'chemin', 'empreinte', 'taille', 'emis_par', 'emis_le'];

    protected function casts(): array
    {
        return [
            'restitution_id' => 'integer',
            'taille' => 'integer',
            'emis_par' => 'integer',
            'emis_le' => 'datetime',
        ];
    }

    public function restitution(): BelongsTo
    {
        return $this->belongsTo(Restitution::class);
    }

    public function emisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emis_par');
    }
}

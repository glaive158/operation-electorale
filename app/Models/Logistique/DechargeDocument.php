<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exemplaire officiel d'une décharge, archivé au moment de son émission avec son empreinte SHA-256.
 * Écrit uniquement par DechargePdfService.
 */
class DechargeDocument extends Model
{
    public const EXEMPLAIRES = ['initial', 'annulation', 'reception'];

    protected $table = 'decharge_documents';

    public $timestamps = false;

    protected $fillable = ['decharge_id', 'exemplaire', 'chemin', 'empreinte', 'taille', 'emis_par', 'emis_le'];

    protected function casts(): array
    {
        return [
            'decharge_id' => 'integer',
            'taille' => 'integer',
            'emis_par' => 'integer',
            'emis_le' => 'datetime',
        ];
    }

    public function decharge(): BelongsTo
    {
        return $this->belongsTo(Decharge::class);
    }

    public function emisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emis_par');
    }
}

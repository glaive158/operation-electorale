<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée de l'historique des codes personnels de réception : le haché valide du début à la fin de la période.
 * Écrite par CodeReceptionService seulement ; le code lui-même n'est jamais conservé.
 */
class CodeReception extends Model
{
    protected $table = 'codes_reception';

    protected $fillable = ['user_id', 'hash', 'valide_du', 'valide_au', 'cree_par'];

    protected $hidden = ['hash'];

    protected function casts(): array
    {
        return ['valide_du' => 'datetime', 'valide_au' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

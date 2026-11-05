<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Circuit de livraison propre à une sous-préfecture, prioritaire sur celui de son département. */
class CircuitSousPrefecture extends Model
{
    protected $table = 'circuits_sous_prefecture';

    protected $fillable = ['cycle_id', 'destinataire_id', 'circuit', 'user_id'];

    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'destinataire_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function sousPrefecture(): BelongsTo
    {
        return $this->belongsTo(Destinataire::class, 'destinataire_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

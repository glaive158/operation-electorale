<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemiseLigne extends Model
{
    protected $table = 'remise_lignes';

    protected $fillable = ['remise_id', 'materiel_id', 'quantite'];

    protected function casts(): array
    {
        return [
            'remise_id' => 'integer',
            'materiel_id' => 'integer',
            'quantite' => 'integer',
        ];
    }

    public function remise(): BelongsTo
    {
        return $this->belongsTo(Remise::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }
}

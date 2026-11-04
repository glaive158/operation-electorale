<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeclarationLigne extends Model
{
    protected $table = 'declaration_lignes';

    protected $fillable = ['declaration_id', 'materiel_id', 'recu', 'defectueux', 'observation'];

    protected function casts(): array
    {
        return [
            'declaration_id' => 'integer',
            'materiel_id' => 'integer',
            'recu' => 'integer',
            'defectueux' => 'integer',
        ];
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(Declaration::class);
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }
}

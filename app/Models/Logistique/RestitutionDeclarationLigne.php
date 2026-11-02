<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestitutionDeclarationLigne extends Model
{
    protected $table = 'restitution_declaration_lignes';

    protected $fillable = ['restitution_declaration_id', 'materiel_id', 'restitue', 'observation'];

    protected function casts(): array
    {
        return [
            'restitution_declaration_id' => 'integer',
            'materiel_id' => 'integer',
            'restitue' => 'integer',
        ];
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(RestitutionDeclaration::class, 'restitution_declaration_id');
    }

    public function materiel(): BelongsTo
    {
        return $this->belongsTo(Materiel::class);
    }
}

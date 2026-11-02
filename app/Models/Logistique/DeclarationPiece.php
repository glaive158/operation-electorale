<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pièce annoncée par une déclaration ; sans fichier (piece_jointe_id nul), elle reste « attendue ». */
class DeclarationPiece extends Model
{
    protected $table = 'declaration_pieces';

    public const ROLES = [
        'signature' => 'Signature',
        'photo' => 'Photo',
    ];

    protected $fillable = ['declaration_id', 'empreinte', 'role', 'piece_jointe_id'];

    protected function casts(): array
    {
        return ['declaration_id' => 'integer', 'piece_jointe_id' => 'integer'];
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(Declaration::class);
    }

    public function pieceJointe(): BelongsTo
    {
        return $this->belongsTo(PieceJointe::class);
    }
}

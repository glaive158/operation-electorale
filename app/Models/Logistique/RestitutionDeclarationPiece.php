<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pièce annoncée par une déclaration de restitution ; sans fichier (piece_jointe_id nul), elle reste « attendue ». */
class RestitutionDeclarationPiece extends Model
{
    protected $table = 'restitution_declaration_pieces';

    public const ROLES = [
        'signature' => 'Signature',
        'photo' => 'Photo',
    ];

    protected $fillable = ['restitution_declaration_id', 'empreinte', 'role', 'piece_jointe_id'];

    protected function casts(): array
    {
        return ['restitution_declaration_id' => 'integer', 'piece_jointe_id' => 'integer'];
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(RestitutionDeclaration::class, 'restitution_declaration_id');
    }

    public function pieceJointe(): BelongsTo
    {
        return $this->belongsTo(PieceJointe::class);
    }
}

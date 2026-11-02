<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Image envoyée par la tablette (signature, photo), adressée par l'empreinte SHA-256 de son contenu.
 * Jamais modifiée ni supprimée ; jamais servie autrement que par les contrôleurs de pièces.
 */
class PieceJointe extends Model
{
    protected $table = 'pieces_jointes';

    protected $fillable = ['empreinte', 'chemin', 'taille', 'type_mime', 'user_id'];

    protected function casts(): array
    {
        return ['taille' => 'integer', 'user_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

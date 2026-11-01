<?php

namespace App\Models\Logistique;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceptionFournisseur extends Model
{
    protected $table = 'receptions_fournisseur';

    protected $fillable = ['commande_id', 'date_reception', 'bon_livraison', 'user_id'];

    protected function casts(): array
    {
        return [
            'commande_id'    => 'integer',
            'date_reception' => 'date',
            'user_id'        => 'integer',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ReceptionFournisseurLigne::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

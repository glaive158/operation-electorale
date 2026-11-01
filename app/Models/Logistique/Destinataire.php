<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destinataire extends Model
{
    protected $table = 'destinataires';

    protected $fillable = [
        'type', 'libelle', 'departement_id', 'arrondissement_id', 'poste_etranger_id',
        'prefecture_id', 'signataire_nom', 'signataire_qualite', 'autorite_user_id', 'actif',
    ];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function scopeActifs(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    public function prefecture(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prefecture_id');
    }

    public function sousPrefectures(): HasMany
    {
        return $this->hasMany(self::class, 'prefecture_id');
    }

    public function posteEtranger(): BelongsTo
    {
        return $this->belongsTo(PosteEtranger::class, 'poste_etranger_id');
    }

    public function perimetres(): HasMany
    {
        return $this->hasMany(PerimetreDestinataire::class);
    }
}

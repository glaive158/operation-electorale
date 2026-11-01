<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une ligne du périmètre d'une entité non nationale : un département, une commune ou un pays. */
class EntiteBulletinPerimetre extends Model
{
    protected $table = 'entite_bulletin_perimetres';

    protected $fillable = ['entite_bulletin_id', 'departement_id', 'commune_id', 'pays'];

    protected function casts(): array
    {
        return [
            'departement_id' => 'integer',
            'commune_id'     => 'integer',
        ];
    }

    public function entite(): BelongsTo
    {
        return $this->belongsTo(EntiteBulletin::class, 'entite_bulletin_id');
    }
}

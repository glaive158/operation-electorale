<?php

namespace App\Models\Logistique;

use Illuminate\Database\Eloquent\Model;

class JournalEntree extends Model
{
    protected $table = 'journal_logistique';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'objet_type', 'objet_id', 'action', 'avant', 'apres', 'motif',
    ];

    protected function casts(): array
    {
        return [
            'avant' => 'array',
            'apres' => 'array',
        ];
    }
}

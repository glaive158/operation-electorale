<?php

namespace App\Services\Logistique;

use App\Models\Logistique\JournalEntree;

class Journal
{
    public static function noter(
        string $objetType,
        int $objetId,
        string $action,
        ?array $avant = null,
        ?array $apres = null,
        ?string $motif = null,
    ): JournalEntree {
        return JournalEntree::create([
            'user_id'    => auth()->id(),
            'objet_type' => $objetType,
            'objet_id'   => $objetId,
            'action'     => $action,
            'avant'      => $avant,
            'apres'      => $apres,
            'motif'      => $motif,
        ]);
    }
}

<?php

namespace App\Services\Logistique;

use Collator;
use Illuminate\Support\Str;

/**
 * Comparaison des libellés selon l'ordre alphabétique français : « Étiquettes » précède « Ziploc ».
 * Une comparaison d'octets (strcmp, <=>) range les lettres accentuées après toutes les autres.
 */
final class TriLibelles
{
    private static ?Collator $collator = null;

    public static function comparer(string $a, string $b): int
    {
        if (class_exists(Collator::class)) {
            self::$collator ??= new Collator('fr_FR');
            $resultat = self::$collator->compare($a, $b);
            if ($resultat !== false) {
                return $resultat;
            }
        }

        return Str::ascii(mb_strtolower($a)) <=> Str::ascii(mb_strtolower($b));
    }
}

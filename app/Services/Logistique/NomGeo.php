<?php

namespace App\Services\Logistique;

use Normalizer;

/** Forme normalisée d'un nom géographique, pour comparer le fichier électoral et le recensement. */
class NomGeo
{
    public static function normaliser(string $nom): string
    {
        $decompose = Normalizer::normalize($nom, Normalizer::FORM_D);
        $sansAccent = preg_replace('/\p{Mn}+/u', '', $decompose === false ? $nom : $decompose) ?? $nom;
        $majuscules = mb_strtoupper($sansAccent, 'UTF-8');
        $espaces = str_replace(['-', "'", '’', '_', '.'], ' ', $majuscules);

        return trim(preg_replace('/\s+/u', ' ', $espaces) ?? $espaces);
    }
}

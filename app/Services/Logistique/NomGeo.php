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

    /** Identité d'un pays du fichier ou d'un paramètre : insensible à la casse, aux accents et aux espaces. */
    public static function clePays(string $pays): string
    {
        return self::normaliser($pays);
    }

    /**
     * Identité d'une localité étrangère (pays, ville). Le texte reste stocké tel qu'écrit ; seules
     * les comparaisons et les clés passent par cette forme.
     */
    public static function cleEtranger(string $pays, string $ville): string
    {
        return self::normaliser($pays).'|'.self::normaliser($ville);
    }
}

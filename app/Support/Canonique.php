<?php

namespace App\Support;

/**
 * Forme canonique d'un contenu et son empreinte : l'unique implémentation PHP, partagée par les
 * déclarations et les affectations de terrain. La tablette recalcule la même chose en JavaScript ;
 * les deux côtés sont testés sur tests/fixtures/canonique.json.
 *
 * Règles : clés d'objet triées récursivement comme des chaînes (octets), ordre des listes conservé,
 * JSON sans espace, UTF-8 et barres obliques non échappés. Un tableau PHP vide est une liste : il
 * s'encode « [] », jamais « {} ».
 */
final class Canonique
{
    /** @param  array<int|string, mixed>  $contenu */
    public static function json(array $contenu): string
    {
        return json_encode(self::trier($contenu), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * SHA-256 hexadécimal minuscule du JSON canonique.
     *
     * @param  array<int|string, mixed>  $contenu
     */
    public static function empreinte(array $contenu): string
    {
        return hash('sha256', self::json($contenu));
    }

    private static function trier(mixed $valeur): mixed
    {
        if (! is_array($valeur)) {
            return $valeur;
        }

        $valeur = array_map(fn ($element) => self::trier($element), $valeur);
        if (! array_is_list($valeur)) {
            ksort($valeur, SORT_STRING);
        }

        return $valeur;
    }
}

<?php

namespace App\Services\Logistique\Moteur;

use App\Models\Logistique\CleRepartition;
use InvalidArgumentException;

final readonly class ParametresCle
{
    /**
     * @param  string  $ratio  décimal strictement positif (chaîne)
     * @param  string  $majoration  pourcentage décimal de 0 à 100 (chaîne)
     */
    public function __construct(
        public string $base,
        public string $ratio,
        public string $majoration,
        public int $minimum,
        public bool $minimumParCommune,
        public string $arrondi,
        public ?int $conditionnement,
        public bool $parEntite,
        public bool $bulletin,
    ) {
        if (! in_array($base, CleRepartition::BASES, true)) {
            throw new InvalidArgumentException("Base de répartition inconnue : {$base}.");
        }
        if (! in_array($arrondi, CleRepartition::ARRONDIS, true)) {
            throw new InvalidArgumentException("Arrondi inconnu : {$arrondi}.");
        }
        if (! self::estDecimal($ratio) || bccomp($ratio, '0', 6) <= 0) {
            throw new InvalidArgumentException('Le ratio doit être un nombre décimal strictement positif.');
        }
        if (! self::estDecimal($majoration) || bccomp($majoration, '100', 6) > 0) {
            throw new InvalidArgumentException('La majoration doit être un pourcentage compris entre 0 et 100.');
        }
        if ($minimum < 0) {
            throw new InvalidArgumentException('Le minimum ne peut pas être négatif.');
        }
        if ($conditionnement !== null && $conditionnement <= 0) {
            throw new InvalidArgumentException('Le conditionnement doit être strictement positif.');
        }
    }

    private static function estDecimal(string $valeur): bool
    {
        return preg_match('/^\d+(\.\d+)?$/', $valeur) === 1;
    }
}

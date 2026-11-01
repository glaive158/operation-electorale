<?php

namespace App\Services\Logistique\Moteur;

use InvalidArgumentException;

final readonly class LocaliteCalcul
{
    /**
     * @param  int  $effectif  valeur de la base pour cette localité
     * @param  int  $entites  nombre d'entités qui couvrent la localité
     * @param  bool  $couverte  pour un bulletin : la localité est dans le périmètre de l'entité
     */
    public function __construct(
        public int $effectif,
        public int $entites,
        public bool $couverte,
    ) {
        if ($effectif < 0) {
            throw new InvalidArgumentException("L'effectif d'une localité ne peut pas être négatif.");
        }
        if ($entites < 0) {
            throw new InvalidArgumentException("Le nombre d'entités d'une localité ne peut pas être négatif.");
        }
    }
}

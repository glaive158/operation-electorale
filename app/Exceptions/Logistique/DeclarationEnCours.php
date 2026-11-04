<?php

namespace App\Exceptions\Logistique;

use RuntimeException;

/** Un autre envoi de la même remise est en cours de traitement : à réessayer (pas une règle métier). */
class DeclarationEnCours extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Déclaration en cours de traitement, réessayez.');
    }
}

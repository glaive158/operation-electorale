<?php

return [

    /*
    | Connexion de file de la capture des effectifs. La capture lit tout le fichier électoral :
    | par défaut, elle est dédiée à la connexion « database_long » dont retry_after dépasse le
    | délai maximal du job (le serveur doit faire tourner un worker sur cette file).
    | Une valeur vide (tests) revient à la connexion par défaut.
    */
    'queue_capture' => env('LOGISTIQUE_QUEUE_CAPTURE', 'database_long') ?: null,

];

<?php

return [

    /*
    | Connexion de file de la capture des effectifs. La capture lit tout le fichier électoral :
    | par défaut, elle est dédiée à la connexion « database_long » dont retry_after dépasse le
    | délai maximal du job (le serveur doit faire tourner un worker sur cette file).
    | Une valeur vide (tests) revient à la connexion par défaut.
    */
    'queue_capture' => env('LOGISTIQUE_QUEUE_CAPTURE', 'database_long') ?: null,

    /*
    | Mise en forme des décharges de matériel (document remis au destinataire).
    */
    'entete' => [
        'RÉPUBLIQUE DU SÉNÉGAL',
        'Un Peuple – Un But – Une Foi',
        'MINISTÈRE DE L\'INTÉRIEUR',
        'DIRECTION GÉNÉRALE DES ÉLECTIONS',
        'Direction des Opérations Électorales',
        'Division Logistique et de la Planification',
    ],

    'lieu_document' => 'Dakar',

    'nb_decharge' => 'NB : la présente décharge doit être retournée signée à la Division Logistique et de la Planification.',

];

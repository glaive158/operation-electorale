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

    /*
    | Disque où sont archivés les PDF officiels des décharges (document opposable, jamais régénéré).
    */
    'disque_decharges' => env('LOGISTIQUE_DISQUE_DECHARGES', 'local'),

    /*
    | Disque où sont rangées les signatures et photos envoyées par les tablettes. Jamais un disque public :
    | les pièces ne sont servies que par les contrôleurs, après contrôle des droits.
    */
    'disque_pieces' => env('LOGISTIQUE_DISQUE_PIECES', 'local'),

    /*
    | Attente maximale (secondes) du verrou qui sérialise les déclarations de tablette d'une même remise :
    | passé ce délai, l'envoi reçoit 409 et la tablette réessaie.
    */
    'declaration_attente_verrou' => (int) env('LOGISTIQUE_DECLARATION_ATTENTE_VERROU', 10),

    'lieu_document' => 'Dakar',

    /*
    | Durée de validité (en jours) d'un jeton d'accès de l'application de terrain.
    */
    'jeton_jours' => (int) env('LOGISTIQUE_JETON_JOURS', 30),

    /*
    | Clé privée RSA (PEM) qui déchiffre le bloc de preuve des tablettes. Fichier hors dépôt, hors de public/,
    | créé par « php artisan logistique:cle-preuve ». La clé publique en est déduite et publiée aux tablettes.
    */
    'cle_preuve_privee' => env('LOGISTIQUE_CLE_PREUVE_PRIVEE', storage_path('app/private/preuve.pem')),

    'nb_decharge' => 'NB : la présente décharge doit être retournée signée à la Division Logistique et de la Planification.',

];

<?php

return [

    /*
    | Connexion de file de la capture des effectifs. Null = connexion par défaut (sync en test).
    | La capture lit tout le fichier électoral : en production, la dédier à la connexion
    | « database_long » dont retry_after dépasse le délai maximal du job.
    */
    'queue_capture' => env('LOGISTIQUE_QUEUE_CAPTURE') ?: null,

];

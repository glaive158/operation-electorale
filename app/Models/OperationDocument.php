<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationDocument extends Model
{
    protected $fillable = ['operation_id','type_document','chemin_fichier','nom_original','uploaded_by'];

    public static array $typeLabels = [
        'formulaire_signe'      => 'Formulaire signé',
        'copie_cni'             => 'Copie CNI',
        'certificat_deces'      => 'Certificat de décès',
        'certificat_residence'  => 'Certificat de résidence',
        'decision_justice'      => 'Décision de justice',
        'attestation_corps'     => 'Attestation du corps',
    ];
}

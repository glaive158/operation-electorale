<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $op = $operation;
        $typeTitles = [
            'inscription'  => "DEMANDE D'INSCRIPTION SUR LES LISTES ÉLECTORALES",
            'modification' => "DEMANDE DE MODIFICATION DE L'INSCRIPTION SUR LES LISTES ÉLECTORALES",
            'changement'   => "DEMANDE DE CHANGEMENT DE STATUT — ÉLECTEUR CIVIL / MILITAIRE OU PARAMILITAIRE",
            'radiation'    => "DEMANDE DE RADIATION D'UN ÉLECTEUR DES LISTES ÉLECTORALES",
        ];
        $typeTitle = $typeTitles[$op->type] ?? '';
        $tc = ['inscription'=>'#009A44','modification'=>'#ca8a04','changement'=>'#0284c7','radiation'=>'#EE1C25'][$op->type] ?? '#000';
        $num = str_pad($op->id, 8, '0', STR_PAD_LEFT);
        $dateStr = $op->created_at->format('d/m/Y');
        $naissance = $op->datenaiss_demandeur?->format('d/m/Y') ?? '';
        $adresseTelParts = array_filter([$op->adresse_demandeur, $op->tel_demandeur]);
        $adresseTel = implode(' — ', $adresseTelParts);
        $adresseTelLabel = $op->adresse_demandeur ? 'Adresse / Tél.' : 'Tél.';

        $changementArrow = [
            'civil_vers_militaire' => 'civil → militaire/paramilitaire',
            'militaire_vers_civil' => 'militaire/paramilitaire → civil',
        ][$op->statut_changement ?? ''] ?? '';

        if ($op->type === 'radiation') {
            $motifLabels = ['deces'=>'Décès','incapacite_juridique'=>'Incapacité juridique','demande_interessee'=>"Sur demande de l'intéressé"];
            $motifStr = $motifLabels[$op->motif_radiation ?? ''] ?? '';
            $motifSuffix = in_array($op->motif_radiation, ['deces','incapacite_juridique'])
                ? " (joindre obligatoirement le certificat de décès et la photocopie de la carte d'identité CEDEAO)"
                : '';
            $recText = "a sollicité la <strong>radiation</strong> de (motif : <strong>{$motifStr}</strong>{$motifSuffix})";
        } elseif ($op->type === 'changement') {
            $recText = "a sollicité un <strong>changement de statut</strong> (<strong>{$changementArrow}</strong>)";
        } elseif ($op->type === 'modification') {
            $commune = strtoupper($op->commune_nom ?? '');
            $recText = "a sollicité une demande de <strong>modification</strong> de son inscription électorale (nouvelle commune : <strong>{$commune}</strong>)";
        } else {
            $commune = strtoupper($op->commune_nom ?? '');
            $recText = "a sollicité une demande d'<strong>inscription</strong> sur la liste électorale de <strong>{$commune}</strong>";
        }
        $destLabel2 = $op->type === 'radiation' ? 'RÉCÉPISSÉ DESTINÉ AU DEMANDEUR' : "RÉCÉPISSÉ DESTINÉ À L'ÉLECTEUR";
    @endphp
    <title>{{ $typeTitle }} N° {{ $num }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+39&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10.5px; color: #000; background: #e5e7eb; margin: 0; }
        .page { width: 210mm; background: #fff; margin: 0 auto; padding: 10mm 12mm; }
        .ft { border-collapse: collapse; width: 100%; margin-bottom: 4px; }
        .ft td { border: 1px solid #000; padding: 4px 7px; vertical-align: middle; }
        .sl { text-align: center; font-size: 9px; font-weight: 700; text-transform: uppercase;
              letter-spacing: 0.5px; background: #f2f2f2; padding: 3px 6px; }
        .barcode { font-family: 'Libre Barcode 39', monospace; font-size: 42px; line-height: 1; }
        .dashed { border: none; border-top: 2px dashed #444; margin: 10px 0; }
        .cb { display: inline-block; width: 11px; height: 11px; border: 1px solid #000;
              text-align: center; line-height: 10px; font-size: 8px; vertical-align: middle; margin: 0 2px; }
        .sig-td { height: 52px; vertical-align: bottom; text-align: center; font-size: 9px; }
        .rec-box { border: 1px solid #000; padding: 6px 8px; margin-bottom: 6px; }
        .rec-title { background: #000; color: #fff; text-align: center; font-weight: 700;
                     font-size: 11px; padding: 4px 6px; letter-spacing: 0.5px; margin-bottom: 6px; }
        @media screen { .page { margin: 16px auto; } }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .page { margin: 0; padding: 7mm 9mm; width: 100%; }
        }
    </style>
</head>
<body>

{{-- Action bar --}}
<div class="no-print" style="background:#1e293b; padding:9px 16px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50;">
    <a href="{{ route('operations.index') }}"
       style="color:#94a3b8; font-size:12px; text-decoration:none;">← Liste des opérations</a>
    <div style="display:flex; gap:8px;">
        <button onclick="window.print()"
                style="background:#334155; color:#fff; padding:6px 14px; border-radius:6px; border:none; cursor:pointer; font-size:12px;">
            🖨 Imprimer
        </button>
        @if(!$op->documents_complets)
        <a href="{{ route('operations.scanner', $op) }}"
           style="background:#009A44; color:#fff; padding:6px 14px; border-radius:6px; text-decoration:none; font-size:12px; font-weight:600;">
            📎 Scanner les documents →
        </a>
        @endif
    </div>
</div>

<div class="page">

    {{-- ════ EN-TÊTE ════ --}}
    <div style="text-align:center; margin-bottom:8px; line-height:1.5;">
        <div style="font-weight:bold; font-size:14px;">REPUBLIQUE DU SENEGAL</div>
        <div style="font-size:10px; font-style:italic;">Un Peuple – un But – une Foi</div>
        <div style="font-weight:bold; font-size:12px;">MINISTERE DE L'INTÉRIEUR ET DE LA SÉCURITÉ PUBLIQUE</div>
    </div>

    {{-- Titre de la demande --}}
    <table class="ft" style="margin-bottom:6px;">
        <tr>
            <td style="text-align:center; font-weight:bold; font-size:11.5px;
                       color:{{ $tc }}; border:2px solid {{ $tc }}; padding:7px;">
                {{ $typeTitle }}
            </td>
        </tr>
    </table>

    {{-- N° + Barcode --}}
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
        <strong style="font-size:11px;">N° de la demande : {{ $num }}</strong>
        <span class="barcode">*{{ $num }}*</span>
    </div>

    {{-- ════ COMMISSION ════ --}}
    <table class="ft">
        <tr><td colspan="4" class="sl">IDENTIFICATION DE LA COMMISSION ADMINISTRATIVE</td></tr>
        <tr>
            <td>Région : <strong>{{ strtoupper($op->user->region_nom ?? '') }}</strong></td>
            <td>Département : <strong>{{ strtoupper($op->user->departement_nom ?? '') }}</strong></td>
            <td>Arrondissement : <strong>{{ strtoupper($op->user->arrondissement_nom ?? '') }}</strong></td>
            <td>Commune : <strong>{{ strtoupper($op->user->commune_nom ?? '') }}</strong></td>
        </tr>
    </table>

    {{-- ════ DEMANDEUR ════ --}}
    <table class="ft">
        <tr><td colspan="2" class="sl">IDENTIFICATION DU DEMANDEUR</td></tr>
        <tr><td colspan="2"><strong>NIN :</strong> {{ $op->nin_demandeur }}</td></tr>
        <tr>
            <td>Prénoms : <strong>{{ strtoupper($op->prenom_demandeur ?? '') }}</strong></td>
            <td>Nom : <strong>{{ strtoupper($op->nom_demandeur) }}</strong></td>
        </tr>
        <tr>
            <td>Né(e) le : <strong>{{ $naissance }}</strong></td>
            <td>Lieu de naissance : <strong>{{ strtoupper($op->lieunaiss_demandeur ?? '') }}</strong></td>
        </tr>
        <tr><td colspan="2">Adresse / Tél. : <strong>{{ $adresseTel ?: '—' }}</strong></td></tr>
        @if($op->type !== 'radiation')
        <tr>
            <td>
                Corps militaire/paramilitaire ?
                <span class="cb">{{ $op->militaire ? '✓' : '' }}</span> Oui
                <span class="cb">{{ !$op->militaire ? '✓' : '' }}</span> Non
            </td>
            <td>
                Handicap réduisant la mobilité ?
                <span class="cb">{{ $op->handicap ? '✓' : '' }}</span> Oui
                <span class="cb">{{ !$op->handicap ? '✓' : '' }}</span> Non
            </td>
        </tr>
        @endif
    </table>

    {{-- ════ SECTION SELON TYPE ════ --}}

    @if(in_array($op->type, ['inscription','modification']))
    <table class="ft">
        <tr>
            <td colspan="2" class="sl">
                {{ $op->type === 'modification' ? 'NOUVELLES INFORMATIONS ÉLECTORALES' : 'INFORMATIONS ÉLECTORALES' }}
            </td>
        </tr>
        <tr>
            <td>Département : <strong>{{ strtoupper($op->departement_nom ?? '') }}</strong></td>
            <td>Commune : <strong>{{ strtoupper($op->commune_nom ?? '') }}</strong></td>
        </tr>
        @if($op->type === 'modification')
        <tr>
            <td colspan="2">Adresse électorale : <strong>{{ $op->adresse_electorale ?? '' }}</strong></td>
        </tr>
        @endif
    </table>
    @endif

    @if($op->type === 'radiation')
    <table class="ft">
        <tr><td colspan="2" class="sl">IDENTIFICATION DE L'ÉLECTEUR À RADIER</td></tr>
        <tr><td colspan="2"><strong>NIN de l'électeur à radier :</strong> {{ $op->nin_electeur_radie ?? '' }}</td></tr>
        <tr>
            <td>Prénoms : <strong>{{ $op->prenom_electeur_radie ?? '—' }}</strong></td>
            <td>Nom : <strong>{{ $op->nom_electeur_radie ?? '—' }}</strong></td>
        </tr>
        <tr><td colspan="2">N° d'électeur : <strong>{{ $op->numelec_electeur_radie ?? '—' }}</strong></td></tr>
        <tr>
            <td colspan="2">
                <strong>Motif :</strong>&nbsp;
                @foreach(['deces'=>'1. Décès','incapacite_juridique'=>'2. Incapacité juridique','demande_interessee'=>"3. Sur demande de l'intéressé"] as $key => $lbl)
                    <span style="margin-right:14px;"><span class="cb">{{ ($op->motif_radiation ?? '') === $key ? '✓' : '' }}</span> {{ $lbl }}</span>
                @endforeach
            </td>
        </tr>
        <tr>
            <td colspan="2" style="color:#cc0000; font-size:9px; font-weight:600; font-style:italic;">
                Attention : Pour les motifs décès et incapacité juridique, joindre obligatoirement la photocopie de la CIN CEDEAO de l'électeur demandant la radiation.
            </td>
        </tr>
    </table>
    @endif

    @if($op->type === 'changement')
    <table class="ft">
        <tr><td class="sl">CHANGEMENT DE STATUT (Art. L.27 et R.37 du Code électoral)</td></tr>
        <tr>
            <td style="font-style:italic; font-size:9.5px; padding:4px 8px; border-bottom:none;">
                Le statut de l'électeur (civil ou militaire) est pris en compte dans la perspective de l'organisation du vote lors des élections territoriales.
            </td>
        </tr>
        <tr>
            <td style="border-top:none; padding:6px 8px;">
                <div style="margin-bottom:5px;">
                    <span class="cb">{{ ($op->statut_changement ?? '') === 'civil_vers_militaire' ? '✓' : '' }}</span>
                    Électeur civil passant dans un corps militaire ou paramilitaire
                </div>
                <div>
                    <span class="cb">{{ ($op->statut_changement ?? '') === 'militaire_vers_civil' ? '✓' : '' }}</span>
                    Électeur militaire ou paramilitaire redevenu civil
                </div>
            </td>
        </tr>
    </table>
    @endif

    {{-- ════ AUTHENTIFICATION ════ --}}
    <table class="ft" style="margin-bottom:8px;">
        <tr><td colspan="3" class="sl">AUTHENTIFICATION DU FORMULAIRE</td></tr>
        <tr><td colspan="3" style="padding:4px 7px;">Date de la demande : <strong>{{ $dateStr }}</strong></td></tr>
        <tr>
            <td class="sig-td" style="width:34%;">Signature du demandeur</td>
            <td class="sig-td" style="width:33%;">
                Visa du représentant CENA<br><em>(Signature et cachet)</em>
            </td>
            <td class="sig-td" style="width:33%;">
                Prénoms et nom du Président :<br>
                <span style="display:block; border-bottom:1px dotted #999; height:22px; margin:4px 0;"></span>
                <em>(Signature et cachet)</em>
            </td>
        </tr>
    </table>

    {{-- ════ RÉCÉPISSÉ CENA ════ --}}
    <hr class="dashed">
    <div class="rec-box">
        <div class="rec-title">RÉCÉPISSÉ DESTINÉ À LA CENA</div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
            <strong>N° : {{ $num }}</strong>
            <span class="barcode">*{{ $num }}*</span>
        </div>
        <div style="margin-bottom:2px;">
            Prénoms : <strong>{{ strtoupper($op->prenom_demandeur ?? '') }}</strong>
            &nbsp;&nbsp;&nbsp;
            Nom : <strong>{{ strtoupper($op->nom_demandeur) }}</strong>
        </div>
        <div style="margin-bottom:2px;">
            né(e) le <strong>{{ $naissance }}</strong> à <strong>{{ strtoupper($op->lieunaiss_demandeur ?? '') }}</strong>
        </div>
        <div style="margin-bottom:4px;">{!! $recText !!}</div>
        <div style="margin-bottom:6px;">
            {{ $adresseTelLabel }} : <strong>{{ $adresseTel ?: '—' }}</strong>
            &nbsp;|&nbsp;
            Date : <strong>{{ $dateStr }}</strong>
        </div>
        <table class="ft" style="margin-bottom:0;">
            <tr>
                <td class="sig-td" style="width:50%;">Le Président de la commission<br><em>(cachet et signature)</em></td>
                <td class="sig-td" style="width:50%;">Visa de la CENA<br><em>(cachet et signature)</em></td>
            </tr>
        </table>
    </div>

    {{-- ════ RÉCÉPISSÉ ÉLECTEUR / DEMANDEUR ════ --}}
    <hr class="dashed">
    <div class="rec-box">
        <div class="rec-title">{{ $destLabel2 }}</div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
            <strong>N° : {{ $num }}</strong>
            <span class="barcode">*{{ $num }}*</span>
        </div>
        <div style="margin-bottom:2px;">
            Prénoms : <strong>{{ strtoupper($op->prenom_demandeur ?? '') }}</strong>
            &nbsp;&nbsp;&nbsp;
            Nom : <strong>{{ strtoupper($op->nom_demandeur) }}</strong>
        </div>
        <div style="margin-bottom:2px;">
            né(e) le <strong>{{ $naissance }}</strong> à <strong>{{ strtoupper($op->lieunaiss_demandeur ?? '') }}</strong>
        </div>
        <div style="margin-bottom:4px;">{!! $recText !!}</div>
        <div style="margin-bottom:6px;">
            {{ $adresseTelLabel }} : <strong>{{ $adresseTel ?: '—' }}</strong>
            &nbsp;|&nbsp;
            Date : <strong>{{ $dateStr }}</strong>
        </div>
        <table class="ft" style="margin-bottom:0;">
            <tr>
                <td class="sig-td" style="width:50%;">Le Président de la commission<br><em>(cachet et signature)</em></td>
                <td class="sig-td" style="width:50%;">Visa de la CENA<br><em>(cachet et signature)</em></td>
            </tr>
        </table>
    </div>

</div>
</body>
</html>

@php
    $date = fn (?string $iso) => $iso ? \Carbon\Carbon::parse($iso)->format('d/m/Y') : '';
    $nombre = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $circuits = ['via_prefet' => 'Via le préfet', 'direct' => 'Remise directe', 'etranger' => 'Poste à l\'étranger'];
    $arrondis = \App\Models\Logistique\CleRepartition::LIBELLES_ARRONDI;
    $effectifs = $d['effectifs'];
    $lignes = $d['lignes'];
    $reception = $d['reception'] ?? null;
    $horodatage = fn (?string $t) => $t ? \Carbon\Carbon::parse($t)->format('d/m/Y H:i') : '—';
    $remisDifferent = ($d['remis_a']['libelle'] ?? null) !== ($d['destinataire']['libelle'] ?? null);
    $signataireRemis = trim(($d['remis_a']['signataire_qualite'] ?? '').' '.($d['remis_a']['signataire_nom'] ?? ''));
    if ($signataireRemis === '') {
        $signataireRemis = $d['remis_a']['libelle'] ?? '';
    }
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $d['titre'] }} — {{ $d['numero'] }}</title>
<style>
    @page { margin: 22mm 16mm 20mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111; }
    .entete { text-align: center; font-size: 8.5pt; line-height: 1.35; }
    .entete .pays { font-weight: bold; font-size: 9.5pt; }
    h1 { font-size: 12.5pt; text-align: center; margin: 14px 0 10px 0; text-transform: uppercase; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    .meta td { padding: 2px 0; vertical-align: top; }
    .meta td.k { width: 26%; font-weight: bold; }
    .remplace { text-align: center; margin: -4px 0 8px 0; font-style: italic; }
    .annulation { text-align: center; margin: -4px 0 8px 0; font-weight: bold; }
    .effectifs { margin: 8px 0; font-weight: bold; text-align: center; }
    table.nomenclature, table.annexe { width: 100%; border-collapse: collapse; }
    table.nomenclature th, table.nomenclature td, table.annexe th, table.annexe td { border: 0.6pt solid #333; padding: 4px 5px; vertical-align: top; }
    table.nomenclature th, table.annexe th { background: #e8e8e8; text-align: center; }
    td.num { text-align: right; }
    tr.regroupement td { background: #f2f2f2; font-weight: bold; }
    .nb { margin-top: 10px; font-size: 8.5pt; font-style: italic; }
    .fait { margin-top: 10px; text-align: right; }
    table.signatures { width: 100%; margin-top: 14px; border-collapse: collapse; }
    table.signatures td { width: 50%; text-align: center; vertical-align: top; height: 70px; font-weight: bold; }
    table.pied { width: 100%; margin-top: 16px; border-top: 0.6pt solid #888; border-collapse: collapse; }
    table.pied td { padding-top: 6px; font-size: 8pt; color: #333; vertical-align: middle; }
    table.pied td.qr { width: 70px; }
    .filigrane { position: fixed; top: 38%; left: 0; width: 100%; text-align: center; font-size: 96pt; font-weight: bold; color: #000; opacity: 0.1; transform: rotate(-30deg); }
    .annexe-page { page-break-before: always; }
    h2 { font-size: 11pt; text-align: center; margin: 0 0 10px 0; }
    h3 { font-size: 10pt; margin: 14px 0 4px 0; }
    table.liste { width: 100%; border-collapse: collapse; }
    table.liste th, table.liste td { border: 0.6pt solid #333; padding: 3px 5px; vertical-align: top; }
    table.liste th { background: #e8e8e8; text-align: center; }
    .declaration { margin: 4px 0; font-size: 8.5pt; }
</style>
</head>
<body>
@if($filigrane)
    <div class="filigrane">{{ $filigrane }}</div>
@endif

<div class="entete">
    @foreach($d['entete'] as $i => $ligne)
        <div @class(['pays' => $i === 0])>{{ $ligne }}</div>
    @endforeach
</div>

<h1>{{ $d['titre'] }}</h1>
@if(! empty($d['remplace']['numero']))
    <p class="remplace">Remplace la décharge n° {{ $d['remplace']['numero'] }}</p>
@endif
@if(! empty($annulation))
    <p class="annulation">Annulée le {{ $annulation['le'] }} — motif : {{ $annulation['motif'] }}</p>
@endif

<table class="meta">
    <tr><td class="k">N°</td><td>{{ $d['numero'] ?? '—' }}</td></tr>
    <tr><td class="k">Élection</td><td>{{ $d['cycle']['libelle'] }}@if(! empty($d['cycle']['date_scrutin'])) — scrutin du {{ $date($d['cycle']['date_scrutin']) }}@endif</td></tr>
    <tr><td class="k">Destinataire</td><td>{{ $d['destinataire']['libelle'] }}</td></tr>
    @if($remisDifferent)
        <tr><td class="k">Remis à</td><td>{{ $d['remis_a']['libelle'] }}</td></tr>
    @endif
    <tr><td class="k">Circuit</td><td>{{ $circuits[$d['circuit']] ?? $d['circuit'] }}</td></tr>
</table>

<div class="effectifs">
    L.V : {{ $effectifs['lieux_vote'] }} — B.V : {{ $effectifs['bureaux_vote'] }} — Électeurs : {{ $effectifs['electeurs'] }}@if(($d['cycle']['type'] ?? null) === 'revision') — C.A : {{ $effectifs['commissions'] }}@endif
</div>

@if($reception)
<table class="nomenclature">
    <thead>
        <tr>
            <th style="width:30%;">Nomenclature</th>
            <th style="width:12%;">Annoncé</th>
            <th style="width:12%;">Reçu</th>
            <th style="width:14%;">Défectueux</th>
            <th style="width:13%;">Utilisable</th>
            <th style="width:19%;">Observations</th>
        </tr>
    </thead>
    <tbody>
        @php $groupe = null; @endphp
        @foreach($reception['lignes'] as $ligne)
            @if(filled($ligne['regroupement']) && $ligne['regroupement'] !== $groupe)
                <tr class="regroupement"><td colspan="6">{{ $ligne['regroupement'] }}</td></tr>
            @endif
            @php $groupe = filled($ligne['regroupement']) ? $ligne['regroupement'] : null; @endphp
            <tr>
                <td>{{ $ligne['libelle'] }}</td>
                <td class="num">{{ $nombre($ligne['annonce']) }}</td>
                <td class="num">{{ $nombre($ligne['recu']) }}</td>
                <td class="num">{{ $nombre($ligne['defectueux']) }}</td>
                <td class="num">{{ $nombre($ligne['utilisable']) }}</td>
                <td>{{ $ligne['observations'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<h3>Remises</h3>
<table class="liste">
    <thead>
        <tr><th>Étape</th><th>De</th><th>À</th><th>Date</th><th>Statut</th></tr>
    </thead>
    <tbody>
        @foreach($reception['remises'] as $remise)
            <tr>
                <td>{{ $remise['etape_libelle'] }}</td>
                <td>{{ $remise['de'] }}</td>
                <td>{{ $remise['a'] }}</td>
                <td>{{ $date($remise['date']) }}</td>
                <td>{{ $remise['statut_libelle'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<h3>Déclarations</h3>
@foreach($reception['declarations'] as $declaration)
    <div class="declaration">
        <strong>{{ $declaration['statut_libelle'] }}</strong> — {{ \App\Models\Logistique\Remise::ETAPES[$declaration['etape']] }}, mode {{ $declaration['mode'] }} —
        {{ $declaration['signataire_nom'] }}, {{ $declaration['signataire_qualite'] }} —
        terminal : {{ $horodatage($declaration['horodatage_terminal']) }} ; serveur : {{ $horodatage($declaration['horodatage_serveur']) }}<br>
        @foreach($declaration['lignes'] as $l)
            {{ $l['libelle'] }} : reçu {{ $nombre($l['recu']) }}, défectueux {{ $nombre($l['defectueux']) }}@if(filled($l['observation'])) ({{ $l['observation'] }})@endif{{ $loop->last ? '' : ' ;' }}
        @endforeach
    </div>
@endforeach
@else
<table class="nomenclature">
    <thead>
        <tr>
            <th style="width:36%;">Nomenclature</th>
            <th style="width:28%;">Clé de répartition</th>
            <th style="width:14%;">Dotation</th>
            <th style="width:22%;">Observations</th>
        </tr>
    </thead>
    <tbody>
        @php $groupe = null; @endphp
        @foreach($lignes as $ligne)
            @if(filled($ligne['regroupement']) && $ligne['regroupement'] !== $groupe)
                <tr class="regroupement"><td colspan="4">{{ $ligne['regroupement'] }}</td></tr>
            @endif
            @php $groupe = filled($ligne['regroupement']) ? $ligne['regroupement'] : null; @endphp
            <tr>
                <td>{{ $ligne['libelle'] }}@if(filled($ligne['entite']) && ! str_contains($ligne['libelle'], $ligne['entite'])) — {{ $ligne['entite'] }}@endif</td>
                <td>{{ $ligne['cle_affichee'] }}</td>
                <td class="num">{{ $nombre($ligne['quantite']) }}</td>
                <td>{{ $ligne['observations'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="nb">{{ $d['nb'] }}</div>
<div class="fait">Fait à {{ $d['lieu'] }}, le {{ $date($d['date']) }}</div>

<table class="signatures">
    <tr>
        <td>L'agent de la DGE (prise en charge)</td>
        <td>{{ $signataireRemis }} (réception)</td>
    </tr>
</table>

@endif

<table class="pied">
    <tr>
        <td class="qr"><img src="{{ $qr }}" width="62" height="62" alt="QR"></td>
        <td>
            Vérification : {{ $identifiant }}<br>
            Décharge n° {{ $d['numero'] ?? '—' }}
        </td>
    </tr>
</table>

@unless($reception)
<div class="annexe-page">
    <h2>Annexe de calcul — décharge n° {{ $d['numero'] ?? '—' }}</h2>
    <p style="font-size:8.5pt;">Plan de dotation, version {{ $d['plan']['version'] }}.</p>
    <table class="annexe">
        <thead>
            <tr>
                <th>Article</th><th>Effectif</th><th>Exact</th><th>Majoration</th><th>Arrondi</th>
                <th>Dotation approuvée</th><th>Déjà expédié</th><th>Cette expédition</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lignes as $ligne)
                @php $a = $ligne['annexe']; @endphp
                <tr>
                    <td>{{ $ligne['libelle'] }}</td>
                    <td class="num">{{ $a === null ? '—' : $nombre($a['effectif']) }}</td>
                    <td class="num">{{ $a === null ? '—' : $a['exact'] }}</td>
                    <td class="num">{{ $a === null ? '—' : $a['majoration'] }}</td>
                    <td>{{ $a === null ? '—' : ($arrondis[$a['arrondi']] ?? $a['arrondi']) }}</td>
                    <td class="num">{{ $nombre($ligne['dotation_approuvee']) }}</td>
                    <td class="num">{{ $nombre($ligne['deja_engage']) }}</td>
                    <td class="num">{{ $nombre($ligne['quantite']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endunless
</body>
</html>

@php
    $nombre = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $controle = $exemplaire === 'controle';
    $d = $f['declaration'];
    $agent = $f['agent'];
    $c = $f['controle'];
    $colonnes = $controle ? 7 : 3;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titre }} — restitution n° {{ $f['restitution_id'] }}</title>
<style>
    @page { margin: 22mm 16mm 20mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #111; }
    .entete { text-align: center; font-size: 8.5pt; line-height: 1.35; }
    .entete .pays { font-weight: bold; font-size: 9.5pt; }
    h1 { font-size: 12.5pt; text-align: center; margin: 14px 0 4px 0; }
    h2 { font-size: 10.5pt; text-align: center; margin: 0 0 10px 0; font-weight: normal; }
    h3 { font-size: 10pt; margin: 14px 0 4px 0; }
    table.meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.meta td { padding: 2px 0; vertical-align: top; }
    table.meta td.k { width: 26%; font-weight: bold; }
    table.lignes { width: 100%; border-collapse: collapse; }
    table.lignes th, table.lignes td { border: 0.6pt solid #333; padding: 4px 5px; vertical-align: top; }
    table.lignes th { background: #e8e8e8; text-align: center; }
    td.num { text-align: right; }
    .bloc { margin: 4px 0; font-size: 9pt; }
    .fait { margin-top: 10px; text-align: right; }
    table.pied { width: 100%; margin-top: 16px; border-top: 0.6pt solid #888; border-collapse: collapse; }
    table.pied td { padding-top: 6px; font-size: 8pt; color: #333; vertical-align: middle; }
    table.pied td.qr { width: 70px; }
</style>
</head>
<body>
<div class="entete">
    @foreach($entete as $i => $ligne)
        <div @class(['pays' => $i === 0])>{{ $ligne }}</div>
    @endforeach
</div>

<h1>{{ $titre }}</h1>
<h2>{{ $controle ? 'Exemplaire de contrôle' : 'Exemplaire de déclaration' }}</h2>

<table class="meta">
    <tr><td class="k">Restitution n°</td><td>{{ $f['restitution_id'] }}</td></tr>
    <tr><td class="k">{{ $f['detenteur_type_libelle'] }}</td><td>{{ $f['detenteur'] }}</td></tr>
    <tr><td class="k">Élection</td><td>{{ $f['cycle'] }}@if($f['date_scrutin']) — scrutin du {{ $f['date_scrutin'] }}@endif</td></tr>
</table>

<table class="lignes">
    <thead>
        <tr>
            <th>Article</th>
            <th>Attendu</th>
            <th>Restitué</th>
            @if($controle)
                <th>Reçu au central</th>
                <th>Bon</th>
                <th>À réparer</th>
                <th>Réformé</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach($f['lignes'] as $ligne)
            <tr>
                <td>{{ $ligne['libelle'] }}</td>
                <td class="num">{{ $nombre($ligne['attendu']) }}</td>
                <td class="num">{{ $nombre($ligne['declare']) }}</td>
                @if($controle)
                    <td class="num">{{ $nombre($ligne['recu']) }}</td>
                    <td class="num">{{ $nombre($ligne['bon']) }}</td>
                    <td class="num">{{ $nombre($ligne['a_reparer']) }}</td>
                    <td class="num">{{ $nombre($ligne['reforme']) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>

<h3>Déclaration du retour</h3>
<div class="bloc">
    {{ $d['signataire_nom'] }}@if(filled($d['signataire_qualite'])), {{ $d['signataire_qualite'] }}@endif —
    mode {{ $d['mode'] }} —
    @if(filled($d['signataire_compte']))compte : {{ $d['signataire_compte'] }}@if(filled($d['delegant'])), par délégation de {{ $d['delegant'] }}@endif —
    @endif
    identité : <strong>{{ $d['verification_libelle'] }}</strong>@unless($d['identite_etablie']) (identité non établie)@endunless —
    terminal : {{ $d['terminal'] ?? '—' }} ; serveur : {{ $d['serveur'] }}
</div>

<h3>Prise en charge du retour</h3>
<div class="bloc">
    Agent : {{ $agent['nom'] ?? '—' }}@if($agent['prise_en_charge']) — le {{ $agent['prise_en_charge']['le'] }}@endif
</div>

@if($controle)
    <h3>Réception et contrôle au central</h3>
    <div class="bloc">
        Réception : {{ $c['reception']['par'] ?? '—' }}@if($c['reception']) — le {{ $c['reception']['le'] }}@endif<br>
        Contrôle : {{ $c['controle']['par'] ?? '—' }}@if($c['controle']) — le {{ $c['controle']['le'] }}@endif
    </div>
    @if(count($c['reformes']) > 0)
        <h3>Décisions de réforme</h3>
        @foreach($c['reformes'] as $reforme)
            <div class="bloc">
                {{ $reforme['libelle'] }} : {{ $nombre($reforme['quantite']) }} réformé(s) — {{ $reforme['par'] ?? '—' }}@if($reforme['le']), le {{ $reforme['le'] }}@endif — motif : {{ $reforme['motif'] }}
            </div>
        @endforeach
    @endif
@endif

<table class="pied">
    <tr>
        <td class="qr"><img src="{{ $qr }}" width="62" height="62" alt="QR"></td>
        <td>
            Restitution n° {{ $f['restitution_id'] }}<br>
            {{ $f['detenteur'] }}
        </td>
    </tr>
</table>
</body>
</html>

@extends('layouts.app')
@section('title','Audit Révisions Électorales 2025')
@section('page-title','Audit Révisions 2025')

@section('content')
<div class="space-y-5">

    <div>
        <h2 class="text-lg font-bold text-slate-800">Audit des opérations de révision (2025)</h2>
        <p class="text-xs text-slate-400 mt-0.5">Vérification: les demandes des PDF ont-elles été prises en compte dans le nouveau fichier?</p>
    </div>

    {{-- Stats globales --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        @php
            $cards = [
                ['key' => 'inscription', 'label' => 'Inscriptions', 'color' => '#16a34a', 'bg' => '#dcfce7', 'expect' => 'present'],
                ['key' => 'modification', 'label' => 'Modifications', 'color' => '#0284c7', 'bg' => '#dbeafe', 'expect' => 'present'],
                ['key' => 'radiation', 'label' => 'Radiations', 'color' => '#dc2626', 'bg' => '#fee2e2', 'expect' => 'absent'],
                ['key' => 'changement', 'label' => 'Changement statut', 'color' => '#7c3aed', 'bg' => '#ede9fe', 'expect' => 'present'],
            ];
        @endphp
        @foreach($cards as $c)
            @php
                $s = $stats[$c['key']];
                $ok = $c['expect'] === 'present' ? $s['present'] : $s['absent'];
                $ko = $c['expect'] === 'present' ? $s['absent'] : $s['present'];
                $pct = $s['total'] > 0 ? round($ok / $s['total'] * 100, 2) : 0;
            @endphp
            <div class="rounded-xl p-4 border-2" style="background:{{ $c['bg'] }}; border-color:{{ $c['color'] }};">
                <p class="text-xs uppercase font-bold tracking-wider" style="color:{{ $c['color'] }};">{{ $c['label'] }}</p>
                <p class="text-2xl font-bold mt-1" style="color:{{ $c['color'] }};">{{ number_format($s['total']) }}</p>
                <p class="text-xs text-slate-600 mt-1">✅ {{ number_format($ok) }} ({{ $pct }}%)</p>
                <p class="text-xs text-slate-600">❌ {{ number_format($ko) }}</p>
            </div>
        @endforeach
    </div>

    {{-- Recherche globale électeur --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4">
        <form method="GET" action="{{ route('audit-revisions') }}" class="flex gap-2 items-center">
            <input type="hidden" name="type" value="recherche">
            <span class="text-sm font-semibold text-slate-600 whitespace-nowrap">🔍 Recherche électeur :</span>
            <input type="text" name="q" value="{{ $type === 'recherche' ? $search : '' }}"
                   placeholder="NIN (ex: 1870199001822) ou N° Électeur (ex: 105282205)"
                   class="flex-1 px-3 py-2 rounded-lg border border-slate-300 text-sm font-mono">
            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white whitespace-nowrap"
                    style="background:#7c3aed;">Rechercher</button>
        </form>

        @if($type === 'recherche' && $search)
            @if(!$ficheElecteur)
                <div class="mt-3 p-3 rounded-lg text-sm text-red-700" style="background:#fee2e2;">
                    ❌ Aucun électeur trouvé pour <strong class="font-mono">{{ $search }}</strong>
                </div>
            @else
                @php
                    $csv      = $ficheElecteur['csv'];
                    $ancien   = $ficheElecteur['ancien'];
                    $demandes = $ficheElecteur['demandes'];
                    $numcni   = $ficheElecteur['numcni'];
                @endphp
                <div class="mt-4 space-y-3">

                    {{-- 3 fiches côte à côte --}}
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

                        {{-- Ancien fichier FIC --}}
                        <div class="rounded-xl p-3 border-2" style="background:#fff7ed; border-color:#ea580c;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-2" style="color:#ea580c;">📂 Ancien fichier (FIC)</p>
                            @if($ancien)
                            <div class="text-xs space-y-0.5">
                                <p><span class="text-slate-500">N° Électeur :</span> <span class="font-mono font-bold">{{ $ancien->numelec }}</span></p>
                                <p><span class="text-slate-500">NIN :</span> <span class="font-mono">{{ $ancien->numcni }}</span></p>
                                <p><span class="text-slate-500">Nom :</span> <strong>{{ $ancien->nom }} {{ $ancien->prenom }}</strong></p>
                                <p><span class="text-slate-500">Né(e) :</span> {{ $ancien->datenaiss }} — {{ $ancien->lieunaiss }}</p>
                                <p><span class="text-slate-500">Commune :</span> {{ $ancien->commune }}</p>
                                <p><span class="text-slate-500">Lieu vote :</span> {{ $ancien->lieu_vote }}</p>
                                <p><span class="text-slate-500">Bureau :</span> <span class="font-mono">{{ $ancien->code_bureau }}</span></p>
                            </div>
                            @else
                                <p class="text-xs text-slate-500 italic">Absent de l'ancien fichier</p>
                            @endif
                        </div>

                        {{-- Révision demandée --}}
                        <div class="rounded-xl p-3 border-2" style="background:#fef9c3; border-color:#ca8a04;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-2" style="color:#ca8a04;">📋 Dernière révision demandée</p>
                            @if($demandes->isNotEmpty())
                            @php $last = $demandes->last(); @endphp
                            <div class="text-xs space-y-0.5">
                                <p><span class="text-slate-500">N° Demande :</span> <span class="font-mono font-bold">{{ $last->num_demande }}</span></p>
                                <p><span class="text-slate-500">Type :</span>
                                    <span class="px-1.5 py-0.5 rounded text-white font-medium"
                                          style="background:{{ match($last->type_demande){
                                              'Inscription'=>'#16a34a','Modification'=>'#0284c7',
                                              'Radiation'=>'#dc2626',default=>'#7c3aed'} }}; font-size:0.65rem;">
                                        {{ $last->type_demande }}
                                    </span>
                                </p>
                                <p><span class="text-slate-500">Nom :</span> <strong>{{ $last->nom }} {{ $last->prenom }}</strong></p>
                                <p><span class="text-slate-500">Commune demandée :</span> {{ $last->commune }}</p>
                                <p><span class="text-slate-500">Lieu demandé :</span> {{ $last->lieu_vote }}</p>
                                <p><span class="text-slate-500">Bureau :</span> b{{ $last->bureau }}</p>
                                <p><span class="text-slate-500">Région :</span> {{ $last->region }}</p>
                            </div>
                            @else
                                <p class="text-xs text-slate-500 italic">Aucune révision enregistrée</p>
                            @endif
                        </div>

                        {{-- Nouveau fichier CSV --}}
                        <div class="rounded-xl p-3 border-2" style="background:#f0fdf4; border-color:#16a34a;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-2" style="color:#16a34a;">📄 Nouveau fichier (CSV)</p>
                            @if($csv)
                            <div class="text-xs space-y-0.5">
                                <p><span class="text-slate-500">N° Électeur :</span> <span class="font-mono font-bold">{{ $csv->numelec }}</span></p>
                                <p><span class="text-slate-500">NIN :</span> <span class="font-mono">{{ $csv->numcni }}</span></p>
                                <p><span class="text-slate-500">Nom :</span> <strong>{{ $csv->nom }} {{ $csv->prenom }}</strong></p>
                                <p><span class="text-slate-500">Né(e) :</span> {{ $csv->datenaiss }} — {{ $csv->lieunaiss }}</p>
                                <p><span class="text-slate-500">Commune :</span> {{ $csv->commune }}</p>
                                <p><span class="text-slate-500">Lieu vote :</span> {{ $csv->lieu_vote }}</p>
                                <p><span class="text-slate-500">Bureau :</span> <span class="font-mono">b{{ $csv->bureau }}</span></p>
                            </div>
                            @else
                                <p class="text-xs text-red-600 font-semibold">⚠️ Absent du nouveau fichier CSV</p>
                            @endif
                        </div>

                    </div>

                    {{-- Toutes les demandes --}}
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-600 mb-2">
                            📋 Demandes de révision ({{ $demandes->count() }})
                        </p>
                        @if($demandes->isEmpty())
                            <p class="text-xs text-slate-400 italic">Aucune demande enregistrée pour ce NIN.</p>
                        @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs border border-slate-200 rounded-lg overflow-hidden">
                                <thead style="background:#f1f5f9;">
                                    <tr>
                                        <th class="px-2 py-2 text-left font-semibold">N° Demande</th>
                                        <th class="px-2 py-2 text-left font-semibold">Type</th>
                                        <th class="px-2 py-2 text-left font-semibold">Nom Prénom</th>
                                        <th class="px-2 py-2 text-left font-semibold">DDN</th>
                                        <th class="px-2 py-2 text-left font-semibold">Commune</th>
                                        <th class="px-2 py-2 text-left font-semibold">Lieu de vote demandé</th>
                                        <th class="px-2 py-2 text-center font-semibold">Traité ?</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($demandes as $d)
                                    @php
                                        $typeBg = match($d->type_demande) {
                                            'Inscription'        => ['bg'=>'#dcfce7','color'=>'#16a34a'],
                                            'Modification'       => ['bg'=>'#dbeafe','color'=>'#0284c7'],
                                            'Radiation'          => ['bg'=>'#fee2e2','color'=>'#dc2626'],
                                            'Changement de statut'=> ['bg'=>'#ede9fe','color'=>'#7c3aed'],
                                            default              => ['bg'=>'#f1f5f9','color'=>'#64748b'],
                                        };
                                        // Statut traitement : comparaison CSV vs revision demandée
                                        if ($d->type_demande === 'Radiation') {
                                            $traite   = !$csv ? '✅ Radié' : '❌ Toujours dans CSV';
                                            $traiteBg = !$csv ? '#16a34a' : '#dc2626';
                                        } elseif ($d->type_demande === 'Modification') {
                                            if (!$csv) {
                                                $traite = '❌ Absent CSV'; $traiteBg = '#dc2626';
                                            } else {
                                                $communeOk = strtoupper(trim($csv->commune ?? '')) === strtoupper(trim($d->commune ?? ''));
                                                $lieuOk    = strtoupper(trim($csv->lieu_vote ?? '')) === strtoupper(trim($d->lieu_vote ?? ''));
                                                if ($communeOk && $lieuOk) {
                                                    $traite = '✅ Conforme'; $traiteBg = '#16a34a';
                                                } elseif ($communeOk) {
                                                    $traite = '🟡 Commune OK'; $traiteBg = '#ca8a04';
                                                } else {
                                                    $traite = '❌ Non conforme'; $traiteBg = '#dc2626';
                                                }
                                            }
                                        } else {
                                            // Inscription / Changement statut
                                            $traite   = $csv ? '✅ Dans CSV' : '❌ Absent CSV';
                                            $traiteBg = $csv ? '#16a34a' : '#dc2626';
                                        }
                                    @endphp
                                    <tr class="border-t border-slate-100">
                                        <td class="px-2 py-2 font-mono">{{ $d->num_demande }}</td>
                                        <td class="px-2 py-2">
                                            <span class="px-1.5 py-0.5 rounded text-white font-medium"
                                                  style="background:{{ $typeBg['color'] }}; font-size:0.65rem;">
                                                {{ $d->type_demande }}
                                            </span>
                                        </td>
                                        <td class="px-2 py-2 font-medium">{{ $d->nom }} {{ $d->prenom }}</td>
                                        <td class="px-2 py-2 font-mono">{{ $d->datenaiss }}</td>
                                        <td class="px-2 py-2">{{ $d->commune }}<br><span class="text-slate-400">{{ $d->region }}</span></td>
                                        <td class="px-2 py-2">{{ $d->lieu_vote }} <span class="text-slate-400 font-mono">b{{ $d->bureau }}</span></td>
                                        <td class="px-2 py-2 text-center font-semibold text-white">
                                            <span class="px-1.5 py-0.5 rounded" style="background:{{ $traiteBg }}; font-size:0.65rem;">
                                                {{ $traite }}
                                            </span>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </div>

                </div>
            @endif
        @endif
    </div>

    {{-- Onglets --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200">
        <div class="flex flex-wrap border-b border-slate-100">
            @php
                $tabs = [
                    ['key' => 'overview',          'label' => 'Vue d\'ensemble'],
                    ['key' => 'inscription_ko',    'label' => '❌ Inscriptions non traitées ('.number_format($stats['inscription']['absent']).')'],
                    ['key' => 'modification_ko',   'label' => '❌ Modifications non traitées ('.number_format($stats['modification']['absent']).')'],
                    ['key' => 'modification_audit','label' => '🔍 Audit modifications (lieu/bureau)'],
                    ['key' => 'radiation_all',     'label' => '🗑️ Radiations ('.number_format($stats['radiation']['total']).')'],
                ];
            @endphp
            @foreach($tabs as $t)
                <a href="{{ route('audit-revisions', ['type' => $t['key']]) }}"
                   class="px-3 py-3 text-sm font-medium border-b-2 transition-colors"
                   style="border-color:{{ $type === $t['key'] ? '#0284c7' : 'transparent' }};
                          color:{{ $type === $t['key'] ? '#0284c7' : '#64748b' }};">
                    {{ $t['label'] }}
                </a>
            @endforeach
        </div>

        <div class="p-4">
            @if($type === 'overview')
                <div class="text-sm text-slate-600 space-y-2">
                    <p>Méthode: chaque demande PDF est appariée par <strong>NUMCNI</strong> avec le nouveau fichier électoral CSV.</p>
                    <ul class="list-disc ml-5 space-y-1">
                        <li><strong>Inscription</strong>: demande OK si NUMCNI présent dans nouveau CSV</li>
                        <li><strong>Modification</strong>: demande OK si NUMCNI présent (audit poussé: lieu+bureau correspondent)</li>
                        <li><strong>Radiation</strong>: demande OK si NUMCNI ABSENT du nouveau CSV</li>
                    </ul>
                    <p class="text-xs text-slate-500 mt-3">Note: changements de statut (26 cas) non audités automatiquement.</p>
                </div>
            @else
                {{-- Recherche --}}
                <form method="GET" action="{{ route('audit-revisions') }}" class="flex gap-2 mb-4">
                    <input type="hidden" name="type" value="{{ $type }}">
                    <input type="text" name="q" value="{{ $search }}" placeholder="Rechercher (nom, NIN, commune...)"
                           class="flex-1 px-3 py-2 rounded-lg border border-slate-300 text-sm">
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background:#16a34a;">Rechercher</button>
                    @if($search)
                        <a href="{{ route('audit-revisions', ['type' => $type]) }}"
                           class="px-4 py-2 rounded-lg text-sm border border-slate-300 hover:bg-slate-50">✕</a>
                    @endif
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead style="background:#f8fafc;">
                            <tr>
                                <th class="px-2 py-2 text-left font-semibold">N°</th>
                                <th class="px-2 py-2 text-left font-semibold">N° demande</th>
                                <th class="px-2 py-2 text-left font-semibold">NUMCNI</th>
                                <th class="px-2 py-2 text-left font-semibold">Nom Prénom</th>
                                <th class="px-2 py-2 text-left font-semibold">Né(e)</th>
                                <th class="px-2 py-2 text-left font-semibold">Commune / Dept</th>
                                @if($type === 'modification_audit')
                                    <th class="px-2 py-2 text-left font-semibold">Demandé<br><span class="font-normal text-slate-400">(lieu/bureau)</span></th>
                                    <th class="px-2 py-2 text-left font-semibold">Effectif (CSV)<br><span class="font-normal text-slate-400">(lieu/bureau)</span></th>
                                    <th class="px-2 py-2 text-center font-semibold">Status</th>
                                @elseif($type === 'radiation_all')
                                    <th class="px-2 py-2 text-center font-semibold">Statut</th>
                                @else
                                    <th class="px-2 py-2 text-left font-semibold">Lieu / Bureau demandé</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @php $i = method_exists($rows, 'currentPage') ? ($rows->currentPage() - 1) * $rows->perPage() + 1 : 1; @endphp
                            @forelse($rows as $r)
                                <tr class="border-t border-slate-100 hover:bg-slate-50 align-top">
                                    <td class="px-2 py-2 text-slate-500">{{ $i }}</td>
                                    <td class="px-2 py-2 font-mono">{{ $r->num_demande }}</td>
                                    <td class="px-2 py-2 font-mono">{{ $r->numcni }}</td>
                                    <td class="px-2 py-2">{{ strtoupper($r->nom) }} {{ strtoupper($r->prenom) }}</td>
                                    <td class="px-2 py-2 font-mono">{{ $r->datenaiss }}</td>
                                    <td class="px-2 py-2">
                                        <span class="font-medium">{{ $r->commune ?? $r->commune_demandee ?? '' }}</span><br>
                                        <span class="text-xs text-slate-500">{{ $r->departement ?? '' }} / {{ $r->region ?? '' }}</span>
                                    </td>
                                    @if($type === 'modification_audit')
                                        <td class="px-2 py-2">
                                            <span class="text-slate-700">{{ $r->lieu_demande }}</span><br>
                                            <span class="text-slate-400 font-mono">b{{ $r->bureau_demande }}</span>
                                        </td>
                                        <td class="px-2 py-2">
                                            <span class="text-slate-700">{{ $r->lieu_csv }}</span><br>
                                            <span class="text-slate-400 font-mono">b{{ $r->bureau_csv }}</span>
                                        </td>
                                        <td class="px-2 py-2 text-center">
                                            @if($r->match_status === 'OK')
                                                <span class="font-bold" style="color:#16a34a;">✅ OK</span>
                                            @else
                                                <span class="font-bold" style="color:#dc2626;">❌ KO</span>
                                            @endif
                                        </td>
                                    @elseif($type === 'radiation_all')
                                        <td class="px-2 py-2 text-center">
                                            @if($r->statut === 'BIEN RADIE')
                                                <span class="font-bold" style="color:#16a34a;">✅ Radié</span>
                                            @else
                                                <span class="font-bold" style="color:#dc2626;">❌ Toujours présent</span>
                                            @endif
                                        </td>
                                    @else
                                        <td class="px-2 py-2">
                                            <span>{{ $r->lieu_vote }}</span>
                                            <span class="text-slate-400 font-mono">b{{ $r->bureau }}</span>
                                        </td>
                                    @endif
                                </tr>
                                @php $i++; @endphp
                            @empty
                                <tr><td colspan="9" class="px-2 py-12 text-center text-slate-400">Aucun résultat</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if(method_exists($rows, 'hasPages') && $rows->hasPages())
                    <div class="mt-4">{{ $rows->links() }}</div>
                @endif
            @endif
        </div>
    </div>

</div>
@endsection

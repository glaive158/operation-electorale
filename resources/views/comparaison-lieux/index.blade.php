@extends('layouts.app')
@section('title','Comparaison Lieux de Vote')
@section('page-title','Comparaison Lieux de Vote')

@section('content')
<div class="space-y-5">

    <div class="flex items-start justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-lg font-bold text-slate-800">Évolution des lieux de vote</h2>
            <p class="text-xs text-slate-400 mt-0.5">Comparaison ancien fichier (DB) vs nouveau fichier électoral (CSV)</p>
        </div>
        <a href="{{ route('comparaison-lieux.export', ['type' => $type]) }}"
           class="px-4 py-2 rounded-lg text-sm font-medium text-white hover:opacity-90 flex items-center gap-2"
           style="background:#0284c7;">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Exporter CSV
        </a>
    </div>

    {{-- Section LIEUX --}}
    <h3 class="text-sm font-semibold text-slate-600 uppercase tracking-wider">Lieux de vote</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        @php
            $tabs = [
                ['key' => 'nouveaux',  'label' => 'Nouveaux lieux',  'desc' => 'En CSV, pas en DB',  'color' => '#16a34a', 'bg' => '#dcfce7'],
                ['key' => 'supprimes', 'label' => 'Lieux supprimés', 'desc' => 'En DB, pas en CSV',  'color' => '#dc2626', 'bg' => '#fee2e2'],
                ['key' => 'deplaces',  'label' => 'Lieux déplacés',  'desc' => 'Commune différente', 'color' => '#7c3aed', 'bg' => '#ede9fe'],
            ];
        @endphp
        @foreach($tabs as $t)
            <a href="{{ route('comparaison-lieux', ['type' => $t['key']]) }}"
               class="block rounded-xl p-4 border-2 transition-all"
               style="background:{{ $type === $t['key'] ? $t['bg'] : '#fff' }};
                      border-color:{{ $type === $t['key'] ? $t['color'] : '#e2e8f0' }};">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider" style="color:{{ $t['color'] }};">{{ $t['label'] }}</p>
                        <p class="text-xs text-slate-500 mt-1">{{ $t['desc'] }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-bold" style="color:{{ $t['color'] }};">{{ number_format($stats[$t['key']]) }}</p>
                    </div>
                </div>
            </a>
        @endforeach
    </div>

    {{-- Section BUREAUX --}}
    <h3 class="text-sm font-semibold text-slate-600 uppercase tracking-wider mt-2">Bureaux de vote</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        @php
            $btabs = [
                ['key' => 'bureaux-nouveaux',  'stat' => 'bureaux_nouveaux',  'label' => 'Nouveaux bureaux',  'desc' => '(commune,lieu,n°) en CSV pas en DB', 'color' => '#16a34a', 'bg' => '#dcfce7'],
                ['key' => 'bureaux-supprimes', 'stat' => 'bureaux_supprimes', 'label' => 'Bureaux supprimés', 'desc' => '(commune,lieu,n°) en DB pas en CSV', 'color' => '#dc2626', 'bg' => '#fee2e2'],
                ['key' => 'bureaux-deplaces',  'stat' => 'bureaux_deplaces',  'label' => 'Bureaux déplacés',  'desc' => 'Même lieu+n° commune ≠ + migration électeurs', 'color' => '#0284c7', 'bg' => '#dbeafe'],
            ];
        @endphp
        @foreach($btabs as $t)
            <a href="{{ route('comparaison-lieux', ['type' => $t['key']]) }}"
               class="block rounded-xl p-4 border-2 transition-all"
               style="background:{{ $type === $t['key'] ? $t['bg'] : '#fff' }};
                      border-color:{{ $type === $t['key'] ? $t['color'] : '#e2e8f0' }};">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider" style="color:{{ $t['color'] }};">{{ $t['label'] }}</p>
                        <p class="text-xs text-slate-500 mt-1">{{ $t['desc'] }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-bold" style="color:{{ $t['color'] }};">{{ number_format($stats[$t['stat']]) }}</p>
                    </div>
                </div>
            </a>
        @endforeach
    </div>

    {{-- Verdict + filtre seuil pour type=deplaces --}}
    @if($type === 'deplaces')
        <div class="rounded-2xl p-4 border-2" style="background:#dcfce7; border-color:#16a34a;">
            <h3 class="font-bold text-sm" style="color:#16a34a;">VERDICT (analyse électeurs suivis)</h3>
            <p class="text-xs text-slate-700 mt-1">
                Critère strict (suivi ≥ 3 + dept identique + effectif ±20%) :
                <strong style="color:#16a34a;">{{ $lieuxStats['strict_count'] }} lieu(x) réellement déplacé(s)</strong>.
                Les autres cas listés ci-dessous sont des homonymies de noms.
            </p>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-3 flex flex-wrap items-center gap-2">
            <span class="text-sm font-medium text-slate-700">Seuil min électeurs suivis (par couple commune):</span>
            <a href="{{ route('comparaison-lieux', ['type' => $type, 'q' => $search]) }}"
               class="px-3 py-1 rounded-lg text-xs font-medium border-2"
               style="border-color: {{ $minSuivis === 0 ? '#0284c7' : '#e2e8f0' }};
                      background: {{ $minSuivis === 0 ? '#dbeafe' : '#fff' }};
                      color: {{ $minSuivis === 0 ? '#0284c7' : '#475569' }};">
                Tous
            </a>
            @foreach($lieuxStats['by_seuil'] as $seuil => $nb)
                <a href="{{ route('comparaison-lieux', ['type' => $type, 'min_suivis' => $seuil, 'q' => $search]) }}"
                   class="px-3 py-1 rounded-lg text-xs font-medium border-2"
                   style="border-color: {{ $minSuivis === $seuil ? '#0284c7' : '#e2e8f0' }};
                          background: {{ $minSuivis === $seuil ? '#dbeafe' : '#fff' }};
                          color: {{ $minSuivis === $seuil ? '#0284c7' : '#475569' }};">
                    ≥ {{ $seuil }} <span class="text-slate-400 font-normal">({{ $nb }})</span>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Recherche --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4">
        <form method="GET" action="{{ route('comparaison-lieux') }}" class="flex flex-wrap gap-2">
            <input type="hidden" name="type" value="{{ $type }}">
            @if($type === 'deplaces' && $minSuivis > 0)
                <input type="hidden" name="min_suivis" value="{{ $minSuivis }}">
            @endif
            <input type="text" name="q" value="{{ $search }}"
                   placeholder="Rechercher commune ou lieu..."
                   class="flex-1 min-w-[200px] px-3 py-2 rounded-lg border border-slate-300 text-sm">
            <button type="submit"
                    class="px-5 py-2 rounded-lg text-sm font-medium text-white"
                    style="background:#16a34a;">
                Rechercher
            </button>
            @if($search)
                <a href="{{ route('comparaison-lieux', ['type' => $type]) }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-300 hover:bg-slate-50">
                    Réinitialiser
                </a>
            @endif
        </form>
    </div>

    {{-- Tableau --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead style="background:#f8fafc;">
                    <tr>
                        @if($type === 'nouveaux')
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">N°</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Département</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Commune</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Lieu de vote (nouveau)</th>
                        @elseif($type === 'supprimes')
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">N°</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Département</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Commune</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Lieu de vote (disparu)</th>
                            <th class="px-4 py-3 text-right font-semibold text-slate-700">Électeurs</th>
                        @elseif($type === 'deplaces')
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">N°</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Lieu de vote</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">ANCIEN<br><span class="text-xs font-normal text-slate-500">Département / Commune</span></th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">NOUVEAU<br><span class="text-xs font-normal text-slate-500">Département / Commune (par bureau)</span></th>
                            <th class="px-4 py-3 text-right font-semibold text-slate-700">Électeurs suivis<br><span class="text-xs font-normal text-slate-500">max / total</span></th>
                        @elseif($type === 'bureaux-nouveaux')
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">N°</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Département</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Commune</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Lieu de vote</th>
                            <th class="px-4 py-3 text-center font-semibold text-slate-700">Bureau n°</th>
                            <th class="px-4 py-3 text-right font-semibold text-slate-700">Électeurs</th>
                        @elseif($type === 'bureaux-supprimes')
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">N°</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Département</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Commune</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Lieu de vote</th>
                            <th class="px-4 py-3 text-center font-semibold text-slate-700">Bureau n°</th>
                            <th class="px-4 py-3 text-right font-semibold text-slate-700">Électeurs</th>
                        @elseif($type === 'bureaux-deplaces')
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">N°</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">Lieu de vote</th>
                            <th class="px-4 py-3 text-center font-semibold text-slate-700">N°</th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">ANCIEN<br><span class="text-xs font-normal text-slate-500">Dept / Commune</span></th>
                            <th class="px-4 py-3 text-left font-semibold text-slate-700">NOUVEAU<br><span class="text-xs font-normal text-slate-500">Dept / Commune</span></th>
                            <th class="px-4 py-3 text-right font-semibold text-slate-700">Effectif<br><span class="text-xs font-normal text-slate-500">ancien → nouveau</span></th>
                            <th class="px-4 py-3 text-right font-semibold text-slate-700">Migration<br><span class="text-xs font-normal text-slate-500">% électeurs suivis</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @php $i = ($rows->currentPage() - 1) * $rows->perPage() + 1; @endphp
                    @forelse($rows as $r)
                        <tr class="border-t border-slate-100 hover:bg-slate-50 align-top">
                            @if($type === 'nouveaux')
                                <td class="px-4 py-2 text-slate-500">{{ $i }}</td>
                                <td class="px-4 py-2">{{ $r->departement }}</td>
                                <td class="px-4 py-2 font-medium">{{ $r->commune }}</td>
                                <td class="px-4 py-2"><span class="font-medium" style="color:#16a34a;">{{ $r->lieu_vote }}</span></td>
                            @elseif($type === 'supprimes')
                                <td class="px-4 py-2 text-slate-500">{{ $i }}</td>
                                <td class="px-4 py-2">{{ $r->departement }}</td>
                                <td class="px-4 py-2 font-medium">{{ $r->commune }}</td>
                                <td class="px-4 py-2"><span class="font-medium" style="color:#dc2626;">{{ $r->lieu_vote }}</span></td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($r->effectif ?? 0) }}</td>
                            @elseif($type === 'deplaces')
                                <td class="px-4 py-2 text-slate-500">{{ $i }}</td>
                                <td class="px-4 py-2 font-medium" style="color:#7c3aed;">{{ $r->lieu_vote }}</td>
                                <td class="px-4 py-2">
                                    <span class="text-slate-500 text-xs">{{ $r->departement_old }}</span><br>
                                    <span class="font-medium">{{ $r->commune_old }}</span>
                                </td>
                                <td class="px-4 py-2 text-xs whitespace-pre-line">{{ $r->localisation_new ?? '' }}</td>
                                <td class="px-4 py-2 text-right">
                                    @php $smax = $r->suivi_max ?? 0; $stot = $r->suivi_total ?? 0; @endphp
                                    <span class="font-bold {{ $smax >= 3 ? 'text-red-600' : ($smax >= 1 ? 'text-amber-600' : 'text-slate-400') }}">{{ $smax }}</span>
                                    <span class="text-xs text-slate-400 block">total: {{ $stot }}</span>
                                </td>
                            @elseif($type === 'bureaux-nouveaux')
                                <td class="px-4 py-2 text-slate-500">{{ $i }}</td>
                                <td class="px-4 py-2">{{ $r->departement }}</td>
                                <td class="px-4 py-2 font-medium">{{ $r->commune }}</td>
                                <td class="px-4 py-2">{{ $r->lieu_vote }}</td>
                                <td class="px-4 py-2 text-center font-mono font-bold" style="color:#16a34a;">{{ str_pad($r->bureau, 2, '0', STR_PAD_LEFT) }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($r->effectif) }}</td>
                            @elseif($type === 'bureaux-supprimes')
                                <td class="px-4 py-2 text-slate-500">{{ $i }}</td>
                                <td class="px-4 py-2">{{ $r->departement }}</td>
                                <td class="px-4 py-2 font-medium">{{ $r->commune }}</td>
                                <td class="px-4 py-2">{{ $r->lieu_vote }}</td>
                                <td class="px-4 py-2 text-center font-mono font-bold" style="color:#dc2626;">{{ $r->bureau }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($r->effectif) }}</td>
                            @elseif($type === 'bureaux-deplaces')
                                <td class="px-4 py-2 text-slate-500">{{ $i }}</td>
                                <td class="px-4 py-2 font-medium" style="color:#0284c7;">{{ $r->lieu_vote }}</td>
                                <td class="px-4 py-2 text-center font-mono font-bold">{{ $r->bureau }}</td>
                                <td class="px-4 py-2">
                                    <span class="text-slate-500 text-xs">{{ $r->dept_old }}</span><br>
                                    <span class="font-medium">{{ $r->commune_old }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="text-slate-500 text-xs">{{ $r->dept_new }}</span><br>
                                    <span class="font-medium" style="color:#0284c7;">{{ $r->commune_new }}</span>
                                </td>
                                <td class="px-4 py-2 text-right font-mono">
                                    {{ number_format($r->effectif_old) }} → {{ number_format($r->effectif_new) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    @php
                                        $pct = $r->migration_pct ?? 0;
                                        $color = $pct >= 80 ? '#16a34a' : ($pct >= 50 ? '#fbbf24' : '#dc2626');
                                    @endphp
                                    <span class="font-bold" style="color:{{ $color }};">{{ $pct }}%</span>
                                    <span class="text-xs text-slate-400 block">({{ $r->migration_count }} / {{ $r->countOld }})</span>
                                </td>
                            @endif
                        </tr>
                        @php $i++; @endphp
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-slate-400 text-sm">Aucun résultat</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($rows->hasPages())
            <div class="px-4 py-3 border-t border-slate-100">
                {{ $rows->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

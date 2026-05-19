@extends('layouts.app')
@section('title', 'Audit Fichier Electoral')
@section('page-title', 'Audit Fichier Electoral')

@section('content')
<div class="space-y-5">

    <div>
        <h2 class="text-lg font-bold text-slate-800">Audit — Comparaison Ancien FIC vs Nouveau CSV</h2>
        <p class="text-xs text-slate-400 mt-0.5">Électeurs disparus, nouveaux inscrits et changements de bureau entre les deux fichiers électoraux</p>
    </div>

    {{-- Cartes compteurs --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <a href="{{ request()->fullUrlWithQuery(['tab' => 'disparus', 'page' => 1]) }}"
           class="rounded-xl p-4 border-2 transition-all hover:shadow-md {{ $tab === 'disparus' ? 'ring-2' : '' }}"
           style="background:#fee2e2; border-color:#dc2626; {{ $tab === 'disparus' ? 'ring-color:#dc2626;' : '' }}">
            <p class="text-xs uppercase font-bold tracking-wider" style="color:#dc2626;">Disparus</p>
            <p class="text-3xl font-bold mt-1" style="color:#dc2626;">{{ number_format($counts['disparus']) }}</p>
            <p class="text-xs text-slate-600 mt-1">Dans ancien FIC, absents du CSV</p>
        </a>
        <a href="{{ request()->fullUrlWithQuery(['tab' => 'nouveaux', 'page' => 1]) }}"
           class="rounded-xl p-4 border-2 transition-all hover:shadow-md {{ $tab === 'nouveaux' ? 'ring-2' : '' }}"
           style="background:#dcfce7; border-color:#16a34a;">
            <p class="text-xs uppercase font-bold tracking-wider" style="color:#16a34a;">Nouveaux inscrits</p>
            <p class="text-3xl font-bold mt-1" style="color:#16a34a;">{{ number_format($counts['nouveaux']) }}</p>
            <p class="text-xs text-slate-600 mt-1">Dans CSV uniquement — nouvelles inscriptions</p>
        </a>
        <a href="{{ request()->fullUrlWithQuery(['tab' => 'changements', 'page' => 1]) }}"
           class="rounded-xl p-4 border-2 transition-all hover:shadow-md {{ $tab === 'changements' ? 'ring-2' : '' }}"
           style="background:#fef9c3; border-color:#ca8a04;">
            <p class="text-xs uppercase font-bold tracking-wider" style="color:#ca8a04;">Changements bureau</p>
            <p class="text-3xl font-bold mt-1" style="color:#ca8a04;">{{ number_format($counts['changements']) }}</p>
            <p class="text-xs text-slate-600 mt-1">Bureau ou commune différent entre les deux fichiers</p>
        </a>
    </div>

    {{-- Panel principal --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200">

        {{-- Onglets --}}
        <div class="flex flex-wrap border-b border-slate-100">
            @php
                $tabs = [
                    ['key' => 'disparus',    'label' => '🔴 Disparus ('.number_format($counts['disparus']).')'],
                    ['key' => 'nouveaux',    'label' => '🟢 Nouveaux ('.number_format($counts['nouveaux']).')'],
                    ['key' => 'changements', 'label' => '🟡 Changements bureau ('.number_format($counts['changements']).')'],
                ];
            @endphp
            @foreach($tabs as $t)
                <a href="{{ request()->fullUrlWithQuery(['tab' => $t['key'], 'page' => 1]) }}"
                   class="px-4 py-3 text-sm font-medium border-b-2 transition-colors"
                   style="border-color:{{ $tab === $t['key'] ? '#0284c7' : 'transparent' }};
                          color:{{ $tab === $t['key'] ? '#0284c7' : '#64748b' }};">
                    {{ $t['label'] }}
                </a>
            @endforeach
        </div>

        <div class="p-4">

            {{-- Filtre + Export --}}
            <form method="GET" action="{{ route('audit-electoral') }}" class="flex flex-wrap items-center gap-2 mb-4">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <label class="text-xs font-semibold text-slate-600">Département :</label>
                <select name="dept" class="px-3 py-2 rounded-lg border border-slate-300 text-sm" style="min-width:180px">
                    <option value="">— Tous —</option>
                    @foreach($departements as $d)
                        <option value="{{ $d }}" {{ $dept === $d ? 'selected' : '' }}>{{ $d }}</option>
                    @endforeach
                </select>

                @if($tab === 'disparus')
                @php
                    $casOptions = [
                        ''                  => '— Tous les cas —',
                        'introuvable'       => '❌ Introuvable ('.($casCounts['introuvable'] ?? 0).')',
                        'radie'             => '✅ Radié ('.($casCounts['radie'] ?? 0).')',
                        'revision_demandee' => '📋 Révision demandée ('.($casCounts['revision_demandee'] ?? 0).')',
                        'numelec_change'    => '🔄 N° électeur changé ('.($casCounts['numelec_change'] ?? 0).')',
                        'nin_change'        => '⚠️ NIN changé ('.($casCounts['nin_change'] ?? 0).')',
                    ];
                @endphp
                <label class="text-xs font-semibold text-slate-600 ml-2">Cas :</label>
                <select name="cas" class="px-3 py-2 rounded-lg border border-slate-300 text-sm" style="min-width:230px">
                    @foreach($casOptions as $val => $label)
                        <option value="{{ $val }}" {{ $cas === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @endif

                <button type="submit"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-white transition-opacity hover:opacity-90"
                        style="background:#0284c7;">
                    Filtrer
                </button>
                @if($dept || $cas)
                <a href="{{ route('audit-electoral', ['tab' => $tab]) }}"
                   class="px-4 py-2 rounded-lg text-sm border border-slate-300 hover:bg-slate-50 text-slate-600">
                    ✕ Réinitialiser
                </a>
                @endif
                <a href="{{ route('audit-electoral.export', $tab) }}{{ $dept ? '?dept='.urlencode($dept) : '' }}"
                   class="ml-auto px-4 py-2 rounded-lg text-sm font-medium text-white transition-opacity hover:opacity-90"
                   style="background:#16a34a;">
                    ↓ Exporter CSV
                </a>
            </form>

            {{-- TABLE DISPARUS --}}
            @if($tab === 'disparus')
            @php
                $casBadge = [
                    'introuvable'       => ['bg' => '#dc2626', 'label' => 'Introuvable'],
                    'radie'             => ['bg' => '#16a34a', 'label' => 'Radié'],
                    'revision_demandee' => ['bg' => '#0284c7', 'label' => 'Révision demandée'],
                    'numelec_change'    => ['bg' => '#7c3aed', 'label' => 'N° changé'],
                    'nin_change'        => ['bg' => '#ca8a04', 'label' => 'NIN changé'],
                ];
            @endphp
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th class="px-2 py-2 text-left font-semibold">N°</th>
                            <th class="px-2 py-2 text-left font-semibold">Cas</th>
                            <th class="px-2 py-2 text-left font-semibold">N° Électeur</th>
                            <th class="px-2 py-2 text-left font-semibold">NIN</th>
                            <th class="px-2 py-2 text-left font-semibold">Nom Prénom</th>
                            <th class="px-2 py-2 text-left font-semibold">Date Naiss.</th>
                            <th class="px-2 py-2 text-left font-semibold">Dept / Commune</th>
                            <th class="px-2 py-2 text-left font-semibold">N° Demande</th>
                            <th class="px-2 py-2 text-left font-semibold">Trouvé dans CSV</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $i = ($resultats->currentPage() - 1) * $resultats->perPage() + 1; @endphp
                        @forelse($resultats as $r)
                        @php
                            $badge = $casBadge[$r->cas] ?? ['bg' => '#64748b', 'label' => $r->cas];
                            $rowBg = match($r->cas) {
                                'introuvable'       => '#fff5f5',
                                'radie'             => '#f0fdf4',
                                'revision_demandee' => '#eff6ff',
                                'numelec_change'    => '#f5f3ff',
                                'nin_change'        => '#fefce8',
                                default             => '#ffffff',
                            };
                        @endphp
                        <tr class="border-t border-slate-100 align-top" style="background:{{ $rowBg }}">
                            <td class="px-2 py-2 text-slate-400">{{ $i++ }}</td>
                            <td class="px-2 py-2">
                                <span class="px-1.5 py-0.5 rounded text-white font-medium" style="background:{{ $badge['bg'] }}; font-size:0.65rem;">
                                    {{ $badge['label'] }}
                                </span>
                            </td>
                            <td class="px-2 py-2 font-mono text-slate-600">{{ $r->ancien_numelec }}</td>
                            <td class="px-2 py-2 font-mono font-semibold">{{ $r->ancien_numcni }}</td>
                            <td class="px-2 py-2 font-medium">{{ $r->nom }} {{ $r->prenom }}</td>
                            <td class="px-2 py-2 font-mono">{{ $r->datenaiss }}</td>
                            <td class="px-2 py-2">
                                {{ $r->departement }}<br>
                                <span class="text-slate-500">{{ $r->commune }}</span>
                            </td>
                            <td class="px-2 py-2 font-mono text-slate-500">{{ $r->num_demande ?? '—' }}</td>
                            <td class="px-2 py-2">
                                @if($r->cas === 'introuvable')
                                    @php
                                        $strategies = [
                                            ['label'=>'Nom+DDN', 'val'=>$r->s2_nom_ddn,    'color'=>'#0284c7'],
                                            ['label'=>'Nom+Prn', 'val'=>$r->s3_nom_prenom, 'color'=>'#7c3aed'],
                                            ['label'=>'NIN',      'val'=>$r->s4_numcni,    'color'=>'#16a34a'],
                                        ];
                                        $found = collect($strategies)->filter(fn($s)=>$s['val'])->count();
                                    @endphp
                                    @if($found === 0)
                                        <span class="px-1.5 py-0.5 rounded text-white font-medium" style="background:#dc2626; font-size:0.65rem;">❌ Introuvable</span>
                                    @else
                                        <div class="space-y-0.5">
                                        @foreach($strategies as $s)
                                            @if($s['val'])
                                            <div class="flex items-center gap-1">
                                                <span class="px-1 rounded text-white font-medium" style="background:{{ $s['color'] }}; font-size:0.6rem; white-space:nowrap;">{{ $s['label'] }}</span>
                                                <span class="font-mono text-xs">{{ $s['val'] }}</span>
                                            </div>
                                            @endif
                                        @endforeach
                                        </div>
                                    @endif
                                @elseif($r->nouveau_numelec)
                                    <span class="text-xs">
                                        <span class="font-mono">{{ $r->nouveau_numelec }}</span><br>
                                        @if($r->nouveau_numcni !== $r->ancien_numcni)
                                            <span class="text-amber-600 font-mono">NIN: {{ $r->nouveau_numcni }}</span><br>
                                        @endif
                                        <span class="text-slate-500">{{ $r->nouvelle_commune }}</span>
                                        @if($r->nouveau_bureau)
                                            · bur.{{ str_pad($r->nouveau_bureau, 2, '0', STR_PAD_LEFT) }}
                                        @endif
                                    </span>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="9" class="px-2 py-12 text-center text-slate-400">Aucun résultat</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif

            {{-- TABLE NOUVEAUX --}}
            @if($tab === 'nouveaux')
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th class="px-2 py-2 text-left font-semibold">N°</th>
                            <th class="px-2 py-2 text-left font-semibold">N° Électeur</th>
                            <th class="px-2 py-2 text-left font-semibold">NIN</th>
                            <th class="px-2 py-2 text-left font-semibold">Nom Prénom</th>
                            <th class="px-2 py-2 text-left font-semibold">Date Naiss.</th>
                            <th class="px-2 py-2 text-left font-semibold">Région</th>
                            <th class="px-2 py-2 text-left font-semibold">Département</th>
                            <th class="px-2 py-2 text-left font-semibold">Commune</th>
                            <th class="px-2 py-2 text-left font-semibold">Lieu de vote</th>
                            <th class="px-2 py-2 text-center font-semibold">Bureau</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $i = ($resultats->currentPage() - 1) * $resultats->perPage() + 1; @endphp
                        @forelse($resultats as $r)
                        <tr class="border-t border-slate-100 hover:bg-green-50 align-top">
                            <td class="px-2 py-2 text-slate-400">{{ $i++ }}</td>
                            <td class="px-2 py-2 font-mono text-slate-600">{{ $r->numelec }}</td>
                            <td class="px-2 py-2 font-mono font-semibold">{{ $r->numcni }}</td>
                            <td class="px-2 py-2 font-medium">{{ $r->nom }} {{ $r->prenom }}</td>
                            <td class="px-2 py-2 font-mono">{{ $r->datenaiss }}</td>
                            <td class="px-2 py-2 text-slate-500">{{ $r->region }}</td>
                            <td class="px-2 py-2">{{ $r->departement }}</td>
                            <td class="px-2 py-2">{{ $r->commune }}</td>
                            <td class="px-2 py-2 text-slate-500">{{ $r->lieu_vote }}</td>
                            <td class="px-2 py-2 text-center">
                                <span class="px-1.5 py-0.5 rounded text-white text-xs font-mono" style="background:#16a34a;">
                                    {{ $r->code_bureau }}
                                </span>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="10" class="px-2 py-12 text-center text-slate-400">Aucun résultat</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif

            {{-- TABLE CHANGEMENTS BUREAU --}}
            @if($tab === 'changements')
            <div class="text-xs text-slate-500 mb-2 flex items-center gap-2">
                <span class="inline-block w-3 h-3 rounded" style="background:#fef9c3; border:1px solid #ca8a04;"></span>
                Fond jaune = commune différente entre ancien et nouveau fichier
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th class="px-2 py-2 text-left font-semibold" rowspan="2">N°</th>
                            <th class="px-2 py-2 text-left font-semibold" rowspan="2">N° Électeur</th>
                            <th class="px-2 py-2 text-left font-semibold" rowspan="2">NIN</th>
                            <th class="px-2 py-2 text-left font-semibold" rowspan="2">Nom Prénom</th>
                            <th class="px-2 py-2 text-center font-semibold" colspan="4" style="background:#fee2e2; color:#dc2626;">Ancien (FIC)</th>
                            <th class="px-2 py-2 text-center font-semibold" colspan="4" style="background:#dcfce7; color:#16a34a;">Nouveau (CSV)</th>
                        </tr>
                        <tr>
                            <th class="px-2 py-2 font-semibold" style="background:#fee2e2; color:#dc2626;">Dept</th>
                            <th class="px-2 py-2 font-semibold" style="background:#fee2e2; color:#dc2626;">Commune</th>
                            <th class="px-2 py-2 font-semibold" style="background:#fee2e2; color:#dc2626;">Lieu</th>
                            <th class="px-2 py-2 text-center font-semibold" style="background:#fee2e2; color:#dc2626;">Bur.</th>
                            <th class="px-2 py-2 font-semibold" style="background:#dcfce7; color:#16a34a;">Dept</th>
                            <th class="px-2 py-2 font-semibold" style="background:#dcfce7; color:#16a34a;">Commune</th>
                            <th class="px-2 py-2 font-semibold" style="background:#dcfce7; color:#16a34a;">Lieu</th>
                            <th class="px-2 py-2 text-center font-semibold" style="background:#dcfce7; color:#16a34a;">Bur.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $i = ($resultats->currentPage() - 1) * $resultats->perPage() + 1; @endphp
                        @forelse($resultats as $r)
                        @php $memeCommune = ($r->ancienne_commune === $r->nouvelle_commune); @endphp
                        <tr class="border-t border-slate-100 align-top" style="{{ !$memeCommune ? 'background:#fef9c3;' : '' }}">
                            <td class="px-2 py-2 text-slate-400">{{ $i++ }}</td>
                            <td class="px-2 py-2 font-mono text-slate-600">{{ $r->numelec }}</td>
                            <td class="px-2 py-2 font-mono font-semibold">{{ $r->numcni }}</td>
                            <td class="px-2 py-2 font-medium">{{ $r->nom }} {{ $r->prenom }}</td>
                            <td class="px-2 py-2">{{ $r->ancien_dept }}</td>
                            <td class="px-2 py-2">{{ $r->ancienne_commune }}</td>
                            <td class="px-2 py-2 text-slate-500">{{ $r->ancien_lieu }}</td>
                            <td class="px-2 py-2 text-center">
                                <span class="px-1.5 py-0.5 rounded text-white font-mono" style="background:#dc2626;">
                                    {{ substr($r->ancien_code_bureau, -2) }}
                                </span>
                            </td>
                            <td class="px-2 py-2">{{ $r->nouveau_dept }}</td>
                            <td class="px-2 py-2 {{ !$memeCommune ? 'font-bold' : '' }}">{{ $r->nouvelle_commune }}</td>
                            <td class="px-2 py-2 text-slate-500">{{ $r->nouveau_lieu }}</td>
                            <td class="px-2 py-2 text-center">
                                <span class="px-1.5 py-0.5 rounded text-white font-mono" style="background:#16a34a;">
                                    {{ str_pad($r->nouveau_bureau_num, 2, '0', STR_PAD_LEFT) }}
                                </span>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="12" class="px-2 py-12 text-center text-slate-400">Aucun résultat</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif

            {{-- Pagination --}}
            @if($resultats->hasPages())
                <div class="mt-4">{{ $resultats->links() }}</div>
            @endif

        </div>
    </div>

</div>
@endsection

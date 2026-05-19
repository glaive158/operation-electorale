@extends('layouts.app')
@section('title','Carte Électorale')
@section('page-title','Carte Électorale')

@section('content')
<div class="space-y-5">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-bold text-slate-800">Carte Électorale</h2>
            <p class="text-xs text-slate-400 mt-0.5">Statistiques géographiques des électeurs</p>
        </div>
        <a href="{{ route('fichier-electoral') }}"
           class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition"
           style="background:#009A44;">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
            </svg>
            Fichier Électoral
        </a>
    </div>

    {{-- Formulaire filtres --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#fffbeb;">
            <svg class="w-4 h-4" style="color:#d97706;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            <span class="font-semibold text-sm" style="color:#d97706;">Filtres géographiques</span>
        </div>
        <div class="p-5">
            <form method="GET" action="{{ route('carte-electorale') }}">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1" id="lbl_region">Région</label>
                        <select id="ce_region" name="region_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="">-- Toutes les régions --</option>
                            @foreach($regions as $r)
                                <option value="{{ $r->id }}" data-nom="{{ $r->nom }}"
                                    {{ ($filtres['region_id'] ?? '') == $r->id ? 'selected' : '' }}>
                                    {{ $r->nom }}
                                </option>
                            @endforeach
                            <option value="etranger" data-nom="ETRANGER"
                                {{ ($filtres['region_id'] ?? '') === 'etranger' ? 'selected' : '' }}>
                                🌍 ÉTRANGER
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1" id="lbl_dept">Département</label>
                        <select id="ce_dept" name="dept_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                disabled>
                            <option value="">-- Tous --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1" id="lbl_arr">Arrondissement</label>
                        <select id="ce_arr" name="arr_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                disabled>
                            <option value="">-- Tous --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1" id="lbl_commune">Commune</label>
                        <select id="ce_commune" name="commune_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                disabled>
                            <option value="">-- Toutes --</option>
                        </select>
                    </div>
                </div>

                <input type="hidden" id="ce_region_id"   value="{{ $filtres['region_id'] ?? '' }}">
                <input type="hidden" id="ce_dept_id"     value="{{ $filtres['dept_id'] ?? '' }}">
                <input type="hidden" id="ce_arr_id"      value="{{ $filtres['arr_id'] ?? '' }}">
                <input type="hidden" id="ce_commune_nom" value="{{ $filtres['commune_id'] ?? '' }}">

                <div class="flex gap-2">
                    <button type="submit"
                            class="px-6 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition flex items-center gap-2"
                            style="background:#d97706;">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        Voir statistiques
                    </button>
                    <a href="{{ route('carte-electorale') }}"
                       class="px-4 py-2 rounded-xl border border-slate-300 text-slate-600 text-sm hover:bg-slate-50 transition">
                        ✕
                    </a>
                </div>
            </form>
        </div>
    </div>

    @if($searched)
    @php
        $modeEtr   = ($stats['mode'] ?? '') === 'etranger';
        $niveau    = $stats['niveau'] ?? 1;
        $drillable = $stats['drillable'] ?? false;
        $colLabels = $stats['col_labels'] ?? ['Col'];
        $baseQuery = http_build_query(array_filter([
            'region_id'  => $filtres['region_id'] ?? '',
            'dept_id'    => $filtres['dept_id'] ?? '',
            'arr_id'     => $filtres['arr_id'] ?? '',
            'commune_id' => $filtres['commune_id'] ?? '',
            'mode'       => $modeEtr ? 'etranger' : 'national',
        ]));
    @endphp

    {{-- Breadcrumb drill-down --}}
    @if(!empty($breadcrumb))
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-5 py-3 flex items-center gap-1 text-sm flex-wrap">
        @foreach($breadcrumb as $i => $crumb)
            @if($i > 0)<span class="text-slate-300 mx-1">›</span>@endif
            @if($crumb['url'])
                <a href="{{ $crumb['url'] }}" class="font-medium hover:underline" style="color:#009A44;">{{ $crumb['label'] }}</a>
            @else
                <span class="font-semibold text-slate-800">{{ $crumb['label'] }}</span>
            @endif
        @endforeach
    </div>
    @endif

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#009A44;">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800">{{ number_format($stats['nb_electeurs']) }}</p>
                <p class="text-xs text-slate-500">Électeurs</p>
            </div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#0284c7;">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800">{{ number_format($stats['nb_bureaux']) }}</p>
                <p class="text-xs text-slate-500">Bureaux de vote</p>
            </div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#7c3aed;">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800">{{ number_format($stats['nb_communes']) }}</p>
                <p class="text-xs text-slate-500">
                    @if($modeEtr){{ $niveau===1 ? 'Villes' : 'Lieux de vote' }}
                    @else{{ $niveau===1 ? 'Départements' : ($niveau===2 ? 'Communes' : ($niveau===3 ? 'Lieux de vote' : 'Bureaux')) }}
                    @endif
                </p>
            </div>
        </div>
    </div>

    @if($stats['detail']->count() > 0)
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <strong class="font-semibold text-slate-800 text-sm">
                @if($modeEtr)
                    {{ $niveau===1 ? 'Pays / Ville' : 'Lieux de vote' }}
                @else
                    {{ $niveau===1 ? 'Régions / Départements' : ($niveau===2 ? 'Communes' : ($niveau===3 ? 'Lieux de vote' : 'Bureaux de vote')) }}
                @endif
            </strong>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium text-white" style="background:#d97706;">
                {{ number_format($stats['detail']->count()) }} ligne(s)
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        @foreach($colLabels as $lbl)
                            <th class="px-5 py-3 text-left font-semibold">{{ $lbl }}</th>
                        @endforeach
                        @if($niveau===1 && !$modeEtr)
                            <th class="px-5 py-3 text-right font-semibold">Communes</th>
                        @endif
                        <th class="px-5 py-3 text-right font-semibold">Électeurs</th>
                        <th class="px-5 py-3 text-right font-semibold">Bureaux</th>
                        @if($drillable)<th class="px-5 py-3"></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($stats['detail'] as $row)
                    <tr class="hover:bg-slate-50 transition-colors">
                        @if($modeEtr && $niveau===1)
                            <td class="px-5 py-3 text-slate-700">{{ $row->col1 }}</td>
                            <td class="px-5 py-3 text-slate-700">{{ $row->col2 }}</td>
                            @if($drillable)
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($row->nb_electeurs) }}</td>
                            <td class="px-5 py-3 text-right text-slate-500">{{ number_format($row->nb_bureaux) }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('carte-electorale') }}?{{ $baseQuery }}&niveau=2&sel_col1={{ urlencode($row->col1) }}&sel_col2={{ urlencode($row->col2) }}"
                                   class="px-2.5 py-1 rounded-lg text-xs font-medium text-white hover:opacity-90" style="background:#0284c7;">
                                    Détail →
                                </a>
                            </td>
                            @endif
                        @elseif($modeEtr && $niveau===2)
                            <td class="px-5 py-3 text-slate-700">{{ $row->col1 }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($row->nb_electeurs) }}</td>
                            <td class="px-5 py-3 text-right text-slate-500">{{ number_format($row->nb_bureaux) }}</td>
                        @elseif(!$modeEtr && $niveau===1)
                            <td class="px-5 py-3 text-slate-700 font-medium">{{ $row->col1 }}</td>
                            <td class="px-5 py-3 text-slate-500 text-xs">{{ $row->col2 }}</td>
                            <td class="px-5 py-3 text-right text-slate-500">{{ number_format($row->nb_communes) }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($row->nb_electeurs) }}</td>
                            <td class="px-5 py-3 text-right text-slate-500">{{ number_format($row->nb_bureaux) }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('carte-electorale') }}?{{ $baseQuery }}&niveau=2&sel_col1={{ urlencode($row->col1) }}&sel_col2={{ urlencode($row->col2) }}"
                                   class="px-2.5 py-1 rounded-lg text-xs font-medium text-white hover:opacity-90" style="background:#009A44;">
                                    Communes →
                                </a>
                            </td>
                        @elseif(!$modeEtr && $niveau===2)
                            <td class="px-5 py-3 text-slate-700">{{ $row->col1 }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($row->nb_electeurs) }}</td>
                            <td class="px-5 py-3 text-right text-slate-500">{{ number_format($row->nb_bureaux) }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('carte-electorale') }}?{{ $baseQuery }}&niveau=3&sel_col1={{ urlencode(request('sel_col1')) }}&sel_col2={{ urlencode(request('sel_col2')) }}&sel_col3={{ urlencode($row->col1) }}"
                                   class="px-2.5 py-1 rounded-lg text-xs font-medium text-white hover:opacity-90" style="background:#7c3aed;">
                                    Lieux →
                                </a>
                            </td>
                        @elseif(!$modeEtr && $niveau===3)
                            <td class="px-5 py-3 text-slate-700">{{ $row->col1 }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($row->nb_electeurs) }}</td>
                            <td class="px-5 py-3 text-right text-slate-500">{{ number_format($row->nb_bureaux) }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('carte-electorale') }}?{{ $baseQuery }}&niveau=4&sel_col1={{ urlencode(request('sel_col1')) }}&sel_col2={{ urlencode(request('sel_col2')) }}&sel_col3={{ urlencode(request('sel_col3')) }}&sel_col4={{ urlencode($row->col1) }}"
                                   class="px-2.5 py-1 rounded-lg text-xs font-medium text-white hover:opacity-90" style="background:#d97706;">
                                    Bureaux →
                                </a>
                            </td>
                        @elseif(!$modeEtr && $niveau===4)
                            <td class="px-5 py-3 font-mono text-xs text-slate-700">{{ $row->col1 }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($row->nb_electeurs) }}</td>
                            <td class="px-5 py-3 text-right text-slate-500">-</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('charge-electorale.bureau', $row->code_bureau) }}"
                                   class="px-2.5 py-1 rounded-lg text-xs font-medium text-white hover:opacity-90" style="background:#0284c7;">
                                    Liste →
                                </a>
                            </td>
                        @else
                            <td class="px-5 py-3 text-slate-700">{{ $row->col1 }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ number_format($row->nb_electeurs) }}</td>
                            <td class="px-5 py-3 text-right text-slate-500">{{ isset($row->nb_bureaux) ? number_format($row->nb_bureaux) : '-' }}</td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-800 text-white">
                        <th class="px-5 py-3 text-left text-xs" colspan="{{ count($colLabels) + ($niveau===1 && !$modeEtr ? 1 : 0) }}">Total</th>
                        <th class="px-5 py-3 text-right text-sm">{{ number_format($stats['nb_electeurs']) }}</th>
                        <th class="px-5 py-3 text-right text-sm">{{ number_format($stats['nb_bureaux']) }}</th>
                        @if($drillable)<th></th>@endif
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endif

    @endif{{-- end if $searched --}}

</div>
@endsection

@push('scripts')
<script>
(function () {
    var ceRegion  = document.getElementById('ce_region');
    var ceDept    = document.getElementById('ce_dept');
    var ceArr     = document.getElementById('ce_arr');
    var ceCommune = document.getElementById('ce_commune');
    var lblDept   = document.getElementById('lbl_dept');
    var lblArr    = document.getElementById('lbl_arr');
    var lblCommune= document.getElementById('lbl_commune');

    var LABELS_NAT = ['Département', 'Arrondissement', 'Commune'];
    var LABELS_ETR = ['Pays', 'Ville', 'Lieu de vote'];

    function setLabels(etr) {
        var L = etr ? LABELS_ETR : LABELS_NAT;
        lblDept.textContent    = L[0];
        lblArr.textContent     = L[1];
        lblCommune.textContent = L[2];
    }

    function fillSelect(sel, items, enabled) {
        sel.innerHTML = '<option value="">-- Tous --</option>';
        // Defensive: handle if API returns object instead of array
        if (!Array.isArray(items)) {
            items = items && items.data ? items.data : [];
        }
        items.forEach(function(item) {
            var opt = document.createElement('option');
            opt.value = item.id;
            opt.dataset.nom = item.nom;
            opt.textContent = item.nom;
            sel.appendChild(opt);
        });
        sel.disabled = !enabled || items.length === 0;
    }

    function isEtranger() { return ceRegion.value === 'etranger'; }

    // Pre-loaded geo data for instant client-side cascade filtering
    var allDepts    = @json($allDepts);
    var allArrs     = @json($allArrs);
    var allCommunes = @json($allCommunes);

    function resetFrom(level) {
        if (level <= 1) { fillSelect(ceDept, [], false); }
        if (level <= 2) { fillSelect(ceArr, [], false); }
        if (level <= 3) { fillSelect(ceCommune, [], false); }
    }

    ceRegion.addEventListener('change', function () {
        resetFrom(1);
        var etr = isEtranger();
        setLabels(etr);
        if (!this.value) return;

        if (etr) {
            // Etranger: query DB (small dataset, fast cached)
            fetch('/carte-electorale/api/pays').then(r => r.json()).then(data => fillSelect(ceDept, data, true));
        } else {
            // National: filter pre-loaded depts client-side
            var depts = allDepts.filter(d => d.region_id == this.value);
            fillSelect(ceDept, depts, true);
        }
    });

    ceDept.addEventListener('change', function () {
        resetFrom(2);
        if (!this.value) return;

        if (isEtranger()) {
            fetch('/carte-electorale/api/villes/' + encodeURIComponent(this.value))
                .then(r => r.json()).then(data => fillSelect(ceArr, data, true));
        } else {
            var arrs = allArrs.filter(a => a.departement_id == this.value);
            fillSelect(ceArr, arrs, true);
        }
    });

    ceArr.addEventListener('change', function () {
        resetFrom(3);
        if (!this.value || isEtranger()) return;
        var communes = allCommunes.filter(c => c.arrondissement_id == this.value);
        fillSelect(ceCommune, communes, true);
    });

    // Restaurer cascade après soumission (instantané, client-side)
    var savedRegionId = ceRegion.value;
    var savedDeptId   = '{{ $filtres["dept_id"] ?? "" }}';
    var savedArrId    = '{{ $filtres["arr_id"] ?? "" }}';

    if (savedRegionId) {
        var etr = savedRegionId === 'etranger';
        setLabels(etr);

        if (etr) {
            // Etranger restoration via fetch (small)
            fetch('/carte-electorale/api/pays').then(r => r.json()).then(function(data) {
                fillSelect(ceDept, data, true);
                if (savedDeptId) {
                    ceDept.value = savedDeptId;
                    return fetch('/carte-electorale/api/villes/' + encodeURIComponent(savedDeptId)).then(r => r.json());
                }
            }).then(function(data) {
                if (data) fillSelect(ceArr, data, true);
            });
        } else {
            // National: client-side restoration
            var depts = allDepts.filter(d => d.region_id == savedRegionId);
            fillSelect(ceDept, depts, true);
            if (savedDeptId) {
                ceDept.value = savedDeptId;
                var arrs = allArrs.filter(a => a.departement_id == savedDeptId);
                fillSelect(ceArr, arrs, true);
                if (savedArrId) {
                    ceArr.value = savedArrId;
                    var communes = allCommunes.filter(c => c.arrondissement_id == savedArrId);
                    fillSelect(ceCommune, communes, true);
                }
            }
        }
    }
})();
</script>
@endpush

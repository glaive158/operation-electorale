@extends('layouts.app')
@section('title','Fichier Électoral')
@section('page-title','Fichier Électoral')

@section('content')
<div class="space-y-5">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-bold text-slate-800">Fichier Électoral</h2>
            <p class="text-xs text-slate-400 mt-0.5">{{ number_format(7371894) }} électeurs enregistrés</p>
        </div>
        <a href="{{ route('carte-electorale') }}"
           class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition"
           style="background:#d97706;">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
            </svg>
            Carte Électorale
        </a>
    </div>

    {{-- Blocs de recherche --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

        {{-- BLOC 1 : Identité --}}
        <div class="bg-white rounded-2xl shadow-sm border-l-4 overflow-hidden" style="border-left-color:#009A44;">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#f0fdf4;">
                <svg class="w-4 h-4" style="color:#009A44;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <span class="font-semibold text-sm" style="color:#009A44;">Bloc 1 — Identité</span>
            </div>
            <div class="p-5">
                <form method="GET" action="{{ route('fichier-electoral') }}">
                    <input type="hidden" name="bloc" value="1">

                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Nom <span class="text-red-500">*</span></label>
                            <input type="text" name="nom" value="{{ $bloc==1 ? ($filtres['nom'] ?? '') : '' }}"
                                   placeholder="Nom de famille"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                   style="text-transform:uppercase;">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Prénom</label>
                            <input type="text" name="prenom" value="{{ $bloc==1 ? ($filtres['prenom'] ?? '') : '' }}"
                                   placeholder="Prénom"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                   style="text-transform:uppercase;">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Date de naissance</label>
                            <input type="text" name="datenaiss" value="{{ $bloc==1 ? ($filtres['datenaiss'] ?? '') : '' }}"
                                   placeholder="JJ/MM/AAAA"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Lieu de naissance</label>
                            <input type="text" name="lieunaiss" value="{{ $bloc==1 ? ($filtres['lieunaiss'] ?? '') : '' }}"
                                   placeholder="Lieu de naissance"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                   style="text-transform:uppercase;">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Prénom du père</label>
                            <input type="text" name="prenom_pere" value="{{ $bloc==1 ? ($filtres['prenom_pere'] ?? '') : '' }}"
                                   placeholder="Prénom père"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                   style="text-transform:uppercase;">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Prénom de la mère</label>
                            <input type="text" name="prenom_mere" value="{{ $bloc==1 ? ($filtres['prenom_mere'] ?? '') : '' }}"
                                   placeholder="Prénom mère"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                   style="text-transform:uppercase;">
                        </div>
                    </div>

                    {{-- Cascade géo --}}
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Région</label>
                            <select id="b1_region" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">-- Toutes --</option>
                                @foreach($regions as $r)
                                    <option value="{{ $r->id }}" data-nom="{{ $r->nom }}"
                                        {{ ($bloc==1 && ($filtres['region_id'] ?? '')==$r->id) ? 'selected' : '' }}>
                                        {{ $r->nom }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Département</label>
                            <select id="b1_dept" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" disabled>
                                <option value="">-- Tous --</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Arrondissement</label>
                            <select id="b1_arr" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" disabled>
                                <option value="">-- Tous --</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Commune</label>
                            <select id="b1_commune" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" disabled>
                                <option value="">-- Toutes --</option>
                            </select>
                        </div>
                    </div>

                    <input type="hidden" name="region_id"   id="b1_region_id"   value="{{ $bloc==1 ? ($filtres['region_id'] ?? '') : '' }}">
                    <input type="hidden" name="region_nom"  id="b1_region_nom"  value="{{ $bloc==1 ? ($filtres['region_nom'] ?? '') : '' }}">
                    <input type="hidden" name="dept_id"     id="b1_dept_id"     value="{{ $bloc==1 ? ($filtres['dept_id'] ?? '') : '' }}">
                    <input type="hidden" name="dept_nom"    id="b1_dept_nom"    value="{{ $bloc==1 ? ($filtres['dept_nom'] ?? '') : '' }}">
                    <input type="hidden" name="arr_id"      id="b1_arr_id"      value="{{ $bloc==1 ? ($filtres['arr_id'] ?? '') : '' }}">
                    <input type="hidden" name="arr_nom"     id="b1_arr_nom"     value="{{ $bloc==1 ? ($filtres['arr_nom'] ?? '') : '' }}">
                    <input type="hidden" name="commune_nom" id="b1_commune_nom" value="{{ $bloc==1 ? ($filtres['commune_nom'] ?? '') : '' }}">

                    <div class="flex gap-2">
                        <button type="submit"
                                class="flex-1 py-2 rounded-xl text-white text-sm font-medium hover:opacity-90 transition flex items-center justify-center gap-2"
                                style="background:#009A44;">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                            </svg>
                            Rechercher
                        </button>
                        <a href="{{ route('fichier-electoral') }}"
                           class="px-4 py-2 rounded-xl border border-slate-300 text-slate-600 text-sm hover:bg-slate-50 transition">
                            ✕
                        </a>
                    </div>
                </form>
            </div>
        </div>

        {{-- BLOC 2 : NIN / N° Électeur --}}
        <div class="bg-white rounded-2xl shadow-sm border-l-4 overflow-hidden" style="border-left-color:#0284c7;">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#eff6ff;">
                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                </svg>
                <span class="font-semibold text-sm text-blue-600">Bloc 2 — NIN / N° Électeur</span>
            </div>
            <div class="p-5">
                <form method="GET" action="{{ route('fichier-electoral') }}">
                    <input type="hidden" name="bloc" value="2">
                    <div class="space-y-3 mb-6">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">NIN / CNI</label>
                            <input type="text" name="numcni" value="{{ $bloc==2 ? ($filtres['numcni'] ?? '') : '' }}"
                                   placeholder="Numéro NIN exact"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">N° Électeur</label>
                            <input type="text" name="numelec" value="{{ $bloc==2 ? ($filtres['numelec'] ?? '') : '' }}"
                                   placeholder="Numéro électeur exact"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit"
                                class="flex-1 py-2 rounded-xl text-white text-sm font-medium hover:opacity-90 transition flex items-center justify-center gap-2"
                                style="background:#0284c7;">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                            </svg>
                            Rechercher
                        </button>
                        <a href="{{ route('fichier-electoral') }}"
                           class="px-4 py-2 rounded-xl border border-slate-300 text-slate-600 text-sm hover:bg-slate-50 transition">
                            ✕
                        </a>
                    </div>
                </form>
            </div>
        </div>

    </div>{{-- end grid blocs --}}

    {{-- RÉSULTATS --}}
    @if(in_array($bloc, [1,2]) && $searched)
        @if($resultats && $resultats->count() === 0)
            <div class="bg-amber-50 border border-amber-200 text-amber-800 px-5 py-4 rounded-2xl flex items-center gap-3 text-sm">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                Aucun électeur trouvé.
            </div>
        @elseif($resultats)
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <span class="font-semibold text-slate-800">Résultats</span>
                    <span class="text-xs px-2.5 py-1 rounded-full font-medium text-white" style="background:#64748b;">
                        Page {{ $resultats->currentPage() }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                                <th class="px-5 py-3 text-left font-semibold">N° Électeur</th>
                                <th class="px-5 py-3 text-left font-semibold">NIN</th>
                                <th class="px-5 py-3 text-left font-semibold">Prénom</th>
                                <th class="px-5 py-3 text-left font-semibold">Nom</th>
                                <th class="px-5 py-3 text-left font-semibold">Date Naiss.</th>
                                <th class="px-5 py-3 text-left font-semibold">Région</th>
                                <th class="px-5 py-3 text-left font-semibold">Commune</th>
                                <th class="px-5 py-3 text-left font-semibold">Lieu de Vote</th>
                                <th class="px-5 py-3 text-center font-semibold">N° Bureau</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach($resultats as $e)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ $e->numelec }}</td>
                                <td class="px-5 py-3 font-mono text-xs font-semibold text-slate-800">{{ $e->numcni }}</td>
                                <td class="px-5 py-3 text-slate-700">{{ $e->prenom }}</td>
                                <td class="px-5 py-3 font-semibold text-slate-800">{{ $e->nom }}</td>
                                <td class="px-5 py-3 text-xs text-slate-500">{{ $e->datenaiss }}</td>
                                <td class="px-5 py-3 text-xs text-slate-500">{{ $e->departement }}</td>
                                <td class="px-5 py-3 text-xs text-slate-500">{{ $e->commune }}</td>
                                <td class="px-5 py-3 text-xs text-slate-500">{{ $e->lieu_vote }}</td>
                                <td class="px-5 py-3 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background:#64748b;">
                                        {{ substr($e->code_bureau, -2) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3">
                                    <a href="{{ route('fichier-electoral.show', $e->id) }}"
                                       class="px-3 py-1.5 rounded-lg text-xs font-medium text-white hover:opacity-90"
                                       style="background:#0284c7;">
                                        Fiche
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($resultats->hasPages())
                <div class="px-6 py-3 border-t border-slate-100 flex items-center justify-between">
                    <div class="flex gap-2">
                        @if($resultats->previousPageUrl())
                            <a href="{{ $resultats->previousPageUrl() }}"
                               class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs text-slate-600 hover:bg-slate-50 transition">
                                ← Préc.
                            </a>
                        @endif
                        @if($resultats->nextPageUrl())
                            <a href="{{ $resultats->nextPageUrl() }}"
                               class="px-3 py-1.5 rounded-lg text-xs font-medium text-white hover:opacity-90"
                               style="background:#009A44;">
                                Suiv. →
                            </a>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        @endif
    @endif

</div>
@endsection

@push('scripts')
<script>
(function () {
    var b1Region  = document.getElementById('b1_region');
    var b1Dept    = document.getElementById('b1_dept');
    var b1Arr     = document.getElementById('b1_arr');
    var b1Commune = document.getElementById('b1_commune');

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

    function syncHidden(selectEl, hiddenId, hiddenNom) {
        var opt = selectEl.options[selectEl.selectedIndex];
        document.getElementById(hiddenId).value  = opt ? opt.value : '';
        document.getElementById(hiddenNom).value = (opt && opt.dataset.nom) ? opt.dataset.nom : '';
    }

    b1Region.addEventListener('change', function () {
        var id = this.value;
        syncHidden(this, 'b1_region_id', 'b1_region_nom');
        fillSelect(b1Dept, [], false);
        fillSelect(b1Arr, [], false);
        fillSelect(b1Commune, [], false);
        ['b1_dept_id','b1_dept_nom','b1_arr_id','b1_arr_nom','b1_commune_nom'].forEach(function(i) {
            document.getElementById(i).value = '';
        });
        if (!id) return;
        fetch('/geo/region/' + id + '/departements')
            .then(r => r.json()).then(data => fillSelect(b1Dept, data, true));
    });

    b1Dept.addEventListener('change', function () {
        var id = this.value;
        syncHidden(this, 'b1_dept_id', 'b1_dept_nom');
        fillSelect(b1Arr, [], false);
        fillSelect(b1Commune, [], false);
        ['b1_arr_id','b1_arr_nom','b1_commune_nom'].forEach(function(i) {
            document.getElementById(i).value = '';
        });
        if (!id) return;
        fetch('/geo/departement/' + id + '/arrondissements')
            .then(r => r.json()).then(data => fillSelect(b1Arr, data, true));
    });

    b1Arr.addEventListener('change', function () {
        var id = this.value;
        syncHidden(this, 'b1_arr_id', 'b1_arr_nom');
        fillSelect(b1Commune, [], false);
        document.getElementById('b1_commune_nom').value = '';
        if (!id) return;
        fetch('/geo/arrondissement/' + id + '/communes')
            .then(r => r.json()).then(data => fillSelect(b1Commune, data, true));
    });

    b1Commune.addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        document.getElementById('b1_commune_nom').value = (opt && opt.value) ? (opt.dataset.nom || opt.textContent.trim()) : '';
    });

    // Restaurer cascade si filtres actifs
    var savedRegionId = document.getElementById('b1_region_id').value;
    var savedDeptId   = document.getElementById('b1_dept_id').value;
    var savedArrId    = document.getElementById('b1_arr_id').value;
    var savedCommune  = document.getElementById('b1_commune_nom').value;

    if (savedRegionId) {
        fetch('/geo/region/' + savedRegionId + '/departements')
            .then(r => r.json())
            .then(function(data) {
                fillSelect(b1Dept, data, true);
                b1Region.value = savedRegionId;
                if (savedDeptId) {
                    b1Dept.value = savedDeptId;
                    return fetch('/geo/departement/' + savedDeptId + '/arrondissements').then(r => r.json());
                }
            })
            .then(function(data) {
                if (!data || !savedDeptId) return;
                fillSelect(b1Arr, data, true);
                if (savedArrId) {
                    b1Arr.value = savedArrId;
                    return fetch('/geo/arrondissement/' + savedArrId + '/communes').then(r => r.json());
                }
            })
            .then(function(data) {
                if (!data || !savedArrId) return;
                fillSelect(b1Commune, data, true);
                if (savedCommune) {
                    Array.from(b1Commune.options).forEach(function(opt) {
                        if (opt.dataset.nom === savedCommune) b1Commune.value = opt.value;
                    });
                }
            });
    }
})();
</script>
@endpush

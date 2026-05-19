@extends('layouts.app')
@section('title','Charge Électorale')
@section('page-title','Charge Électorale')

@section('content')
<div class="space-y-5">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-bold text-slate-800">Analyse de Charge des Bureaux de Vote</h2>
            <p class="text-xs text-slate-400 mt-0.5">Bureaux sous-chargés (&lt; {{ $seuilMin }}) et surchargés (&gt; {{ $seuilMax }})</p>
        </div>
        <a href="{{ route('carte-electorale') }}"
           class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition"
           style="background:#009A44;">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
            </svg>
            Carte Électorale
        </a>
    </div>

    {{-- Formulaire filtres --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#fef3c7;">
            <svg class="w-4 h-4" style="color:#d97706;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
            </svg>
            <span class="font-semibold text-sm" style="color:#d97706;">Filtres & Seuils</span>
        </div>
        <div class="p-5">
            <form method="GET" action="{{ route('charge-electorale') }}">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Type</label>
                        <select name="type" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="tous" {{ $type === 'tous' ? 'selected' : '' }}>Tous</option>
                            <option value="sous" {{ $type === 'sous' ? 'selected' : '' }}>Sous-charge</option>
                            <option value="sur" {{ $type === 'sur' ? 'selected' : '' }}>Surcharge</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Seuil min</label>
                        <input type="number" name="seuil_min" value="{{ $seuilMin }}" min="1" max="100"
                               class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Seuil max</label>
                        <input type="number" name="seuil_max" value="{{ $seuilMax }}" min="500" max="1000"
                               class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Région</label>
                        <select id="ch_region" name="region_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <option value="">-- Toutes --</option>
                            @foreach($regions as $r)
                                <option value="{{ $r->id }}" {{ ($regionId ?? '') == $r->id ? 'selected' : '' }}>
                                    {{ $r->nom }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Département</label>
                        <select id="ch_dept" name="dept_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                {{ !$regionId ? 'disabled' : '' }}>
                            <option value="">-- Tous --</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit"
                            class="px-6 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition flex items-center gap-2"
                            style="background:#d97706;">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        Analyser
                    </button>
                    <a href="{{ route('charge-electorale') }}"
                       class="px-4 py-2 rounded-xl border border-slate-300 text-slate-600 text-sm hover:bg-slate-50 transition">
                        Réinitialiser
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#dc2626;">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800">{{ number_format($data['nb_sous']) }}</p>
                <p class="text-xs text-slate-500">Bureaux sous-chargés (&lt; {{ $seuilMin }})</p>
            </div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#f59e0b;">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-slate-800">{{ number_format($data['nb_sur']) }}</p>
                <p class="text-xs text-slate-500">Bureaux surchargés (&gt; {{ $seuilMax }})</p>
            </div>
        </div>
    </div>

    {{-- Sous-charge --}}
    @if(($type === 'sous' || $type === 'tous') && $data['nb_sous'] > 0)
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100" style="background:#fef2f2;">
            <strong class="font-semibold text-sm" style="color:#dc2626;">
                <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/>
                </svg>
                Bureaux Sous-chargés
            </strong>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium text-white" style="background:#dc2626;">
                {{ number_format($data['nb_sous']) }} BV
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Code Bureau</th>
                        <th class="px-5 py-3 text-left font-semibold">Lieu de Vote</th>
                        <th class="px-5 py-3 text-left font-semibold">Commune</th>
                        <th class="px-5 py-3 text-left font-semibold">Arrondissement</th>
                        <th class="px-5 py-3 text-left font-semibold">Région</th>
                        <th class="px-5 py-3 text-right font-semibold">Effectif</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($data['sous_charge'] as $bv)
                    <tr class="hover:bg-red-50 transition-colors">
                        <td class="px-5 py-3 font-mono text-xs text-slate-700">{{ $bv->code_bureau }}</td>
                        <td class="px-5 py-3 text-slate-700">{{ $bv->lieu_vote }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $bv->commune }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $bv->arrondissement }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $bv->departement }}</td>
                        <td class="px-5 py-3 text-right">
                            <span class="px-2 py-1 rounded-lg text-xs font-bold text-white" style="background:#dc2626;">
                                {{ number_format($bv->effectif) }}
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <a href="{{ route('charge-electorale.bureau', $bv->code_bureau) }}"
                               class="px-2.5 py-1 rounded-lg text-xs font-medium text-white hover:opacity-90" style="background:#0284c7;">
                                Liste →
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Surcharge --}}
    @if(($type === 'sur' || $type === 'tous') && $data['nb_sur'] > 0)
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100" style="background:#fffbeb;">
            <strong class="font-semibold text-sm" style="color:#f59e0b;">
                <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
                Bureaux Surchargés
            </strong>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium text-white" style="background:#f59e0b;">
                {{ number_format($data['nb_sur']) }} BV
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Code Bureau</th>
                        <th class="px-5 py-3 text-left font-semibold">Lieu de Vote</th>
                        <th class="px-5 py-3 text-left font-semibold">Commune</th>
                        <th class="px-5 py-3 text-left font-semibold">Arrondissement</th>
                        <th class="px-5 py-3 text-left font-semibold">Région</th>
                        <th class="px-5 py-3 text-right font-semibold">Effectif</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($data['sur_charge'] as $bv)
                    <tr class="hover:bg-yellow-50 transition-colors">
                        <td class="px-5 py-3 font-mono text-xs text-slate-700">{{ $bv->code_bureau }}</td>
                        <td class="px-5 py-3 text-slate-700">{{ $bv->lieu_vote }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $bv->commune }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $bv->arrondissement }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $bv->departement }}</td>
                        <td class="px-5 py-3 text-right">
                            <span class="px-2 py-1 rounded-lg text-xs font-bold text-white" style="background:#f59e0b;">
                                {{ number_format($bv->effectif) }}
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <a href="{{ route('charge-electorale.bureau', $bv->code_bureau) }}"
                               class="px-2.5 py-1 rounded-lg text-xs font-medium text-white hover:opacity-90" style="background:#0284c7;">
                                Liste →
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($data['nb_sous'] === 0 && $data['nb_sur'] === 0)
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-8 text-center">
        <svg class="w-12 h-12 mx-auto text-green-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-slate-600 font-medium">Aucun bureau sous-chargé ou surchargé trouvé avec ces critères.</p>
        <p class="text-xs text-slate-400 mt-1">Tous les bureaux ont un effectif normal.</p>
    </div>
    @endif

</div>
@endsection

@push('scripts')
<script>
(function () {
    var chRegion = document.getElementById('ch_region');
    var chDept = document.getElementById('ch_dept');
    var savedDeptId = '{{ $deptId ?? "" }}';

    function fillDepts(items) {
        chDept.innerHTML = '<option value="">-- Tous --</option>';
        if (!Array.isArray(items)) items = items && items.data ? items.data : [];
        items.forEach(function(item) {
            var opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.nom;
            chDept.appendChild(opt);
        });
        chDept.disabled = items.length === 0;
    }

    chRegion.addEventListener('change', function () {
        chDept.value = '';
        if (!this.value) {
            chDept.disabled = true;
            return;
        }
        fetch('/geo/region/' + this.value + '/departements')
            .then(r => r.json())
            .then(data => fillDepts(data));
    });

    // Restore cascade
    if (chRegion.value) {
        fetch('/geo/region/' + chRegion.value + '/departements')
            .then(r => r.json())
            .then(function(data) {
                fillDepts(data);
                if (savedDeptId) chDept.value = savedDeptId;
            });
    }
})();
</script>
@endpush

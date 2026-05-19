@extends('layouts.app')
@section('title','Liste d\'Émargement')
@section('page-title','Liste d\'Émargement')

@section('content')
<div class="space-y-5">

    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-bold text-slate-800">Édition des Listes d'Émargement</h2>
            <p class="text-xs text-slate-400 mt-0.5">Impression par bureau, lieu de vote, commune ou département</p>
        </div>
    </div>

    @if(session('error'))
    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-red-800 font-medium">{{ session('error') }}</p>
        </div>
    </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#dcfce7;">
            <svg class="w-4 h-4" style="color:#16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span class="font-semibold text-sm" style="color:#16a34a;">Sélection des filtres</span>
        </div>
        <div class="p-5">
            <form method="POST" id="le_form" action="{{ route('liste-emargement.generate') }}" target="_blank">
                @csrf
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Région *</label>
                        <select id="le_region" name="region_id" required
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">-- Sélectionner --</option>
                            @foreach($regions as $r)
                                <option value="{{ $r->id }}">{{ $r->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Département</label>
                        <select id="le_dept" name="dept_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                disabled>
                            <option value="">-- Tous --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Arrondissement</label>
                        <select id="le_arr" name="arr_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                disabled>
                            <option value="">-- Tous --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Commune *</label>
                        <select id="le_commune" name="commune_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                disabled>
                            <option value="">-- Sélectionner --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Lieu de Vote</label>
                        <select id="le_lieu" name="lieu_vote"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                disabled>
                            <option value="">-- Tous --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Bureau de Vote</label>
                        <select id="le_bureau" name="code_bureau"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                                disabled>
                            <option value="">-- Tous --</option>
                        </select>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
                    <p class="text-xs text-blue-800">
                        <strong>Mode commune</strong> (max 100 bureaux) : sélectionnez au moins une commune.<br>
                        <strong>Mode arrondissement (Stream)</strong> (max 1500 bureaux) : sélectionnez un arrondissement, génération mémoire-optimisée.
                    </p>
                </div>

                {{-- Compteur bureaux --}}
                <div id="bureau-counter" class="bg-white border-2 rounded-lg p-4 mb-4" style="display:none;">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-700">Bureaux à générer :</p>
                            <p class="text-xs text-slate-500">Basé sur les filtres sélectionnés</p>
                        </div>
                        <div class="text-right">
                            <p id="bureau-count" class="text-3xl font-bold"></p>
                            <p class="text-xs text-slate-500">/ <span id="bureau-limit">100</span> max</p>
                        </div>
                    </div>
                    <div id="bureau-warning" class="mt-3 bg-red-50 border border-red-200 rounded p-2" style="display:none;">
                        <p class="text-xs text-red-800">
                            <strong>⚠️ Limite dépassée!</strong> Réduisez le périmètre (commune/lieu).
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" id="btn-commune"
                            class="px-6 py-2.5 rounded-xl text-sm font-medium text-white hover:opacity-90 transition flex items-center gap-2"
                            style="background:#16a34a;">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        Générer Commune (≤100 bureaux)
                    </button>
                    <button type="button" id="btn-zip"
                            class="px-6 py-2.5 rounded-xl text-sm font-medium text-white hover:opacity-90 transition flex items-center gap-2"
                            style="background:#7c3aed;">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v3h2a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm6 4V5h2v3h-2zm-2 2v2h2v-2H8zm4 0v2h-2v2h2v2h-2v2h2a2 2 0 002-2v-4a2 2 0 00-2-2z"/>
                        </svg>
                        Télécharger ZIP (1 HTML/commune, ≤1500)
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
(function () {
    // Pre-loaded geo data
    var allDepts = @json($departements);
    var allArrs = @json($arrondissements);
    var allCommunes = @json($communes);

    var leRegion = document.getElementById('le_region');
    var leDept = document.getElementById('le_dept');
    var leArr = document.getElementById('le_arr');
    var leCommune = document.getElementById('le_commune');
    var leLieu = document.getElementById('le_lieu');
    var leBureau = document.getElementById('le_bureau');

    function fillSelect(sel, items, enabled) {
        var firstOpt = sel.options[0].text;
        sel.innerHTML = '<option value="">' + firstOpt + '</option>';
        items.forEach(function(item) {
            var opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.nom;
            sel.appendChild(opt);
        });
        sel.disabled = !enabled || items.length === 0;
    }

    function resetFrom(level) {
        if (level <= 1) fillSelect(leDept, [], false);
        if (level <= 2) fillSelect(leArr, [], false);
        if (level <= 3) fillSelect(leCommune, [], false);
        if (level <= 4) fillSelect(leLieu, [], false);
        if (level <= 5) fillSelect(leBureau, [], false);
    }

    leRegion.addEventListener('change', function () {
        resetFrom(1);
        if (!this.value) return;
        var depts = allDepts.filter(d => d.region_id == this.value);
        fillSelect(leDept, depts, true);
    });

    leDept.addEventListener('change', function () {
        resetFrom(2);
        if (!this.value) return;
        var arrs = allArrs.filter(a => a.departement_id == this.value);
        fillSelect(leArr, arrs, true);
    });

    leArr.addEventListener('change', function () {
        resetFrom(3);
        if (!this.value) return;
        var communes = allCommunes.filter(c => c.arrondissement_id == this.value);
        fillSelect(leCommune, communes, true);
        updateCount();
    });

    leCommune.addEventListener('change', function () {
        resetFrom(4);
        if (!this.value) return;
        fetch('/liste-emargement/api/lieux/' + this.value)
            .then(r => r.json()).then(data => fillSelect(leLieu, data, true));
    });

    leLieu.addEventListener('change', function () {
        resetFrom(5);
        if (!this.value) return;
        fetch('/liste-emargement/api/bureaux/' + encodeURIComponent(this.value))
            .then(r => r.json()).then(data => fillSelect(leBureau, data, true));
        updateCount();
    });

    leBureau.addEventListener('change', updateCount);

    // Update bureau count
    function updateCount() {
        var params = new URLSearchParams({
            region_id: leRegion.value || '',
            dept_id: leDept.value || '',
            arr_id: leArr.value || '',
            commune_id: leCommune.value || '',
            lieu_vote: leLieu.value || ''
        });

        // Show counter as soon as arrondissement OR commune chosen
        if (!leArr.value && !leCommune.value) {
            document.getElementById('bureau-counter').style.display = 'none';
            return;
        }

        fetch('/liste-emargement/api/count?' + params.toString())
            .then(r => r.json())
            .then(data => {
                document.getElementById('bureau-counter').style.display = 'block';
                document.getElementById('bureau-count').textContent = data.count;
                document.getElementById('bureau-limit').textContent = data.limit;
                document.getElementById('bureau-count').style.color = data.over_limit ? '#dc2626' : '#16a34a';
                document.getElementById('bureau-warning').style.display = data.over_limit ? 'block' : 'none';
                document.getElementById('bureau-counter').style.borderColor = data.over_limit ? '#fca5a5' : '#86efac';
            });
    }

    leCommune.addEventListener('change', updateCount);

    // ZIP button: switch action to zip route then submit (no target=_blank, file download)
    var form = document.getElementById('le_form');
    var actionDefault = form.action;
    var defaultTarget = form.target;
    var actionZip = '{{ route('liste-emargement.generate-zip') }}';
    document.getElementById('btn-zip').addEventListener('click', function () {
        if (!leArr.value && !leCommune.value) {
            alert('Sélectionnez au moins un arrondissement ou une commune.');
            return;
        }
        form.action = actionZip;
        form.target = '_self';
        form.submit();
        setTimeout(function () {
            form.action = actionDefault;
            form.target = defaultTarget;
        }, 500);
    });
})();
</script>
@endpush

@extends('layouts.app')
@section('title','Comparaison Fichiers Électoraux')
@section('page-title','Comparaison Fichiers Électoraux')

@section('content')
<div class="space-y-5">

    <div>
        <h2 class="text-lg font-bold text-slate-800">Comparaison Ancien vs Nouveau Fichier</h2>
        <p class="text-xs text-slate-400 mt-0.5">Stats par {{ $levelLabel }} — niveau ajusté selon les filtres</p>
    </div>

    {{-- Filtres cascade --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#fef3c7;">
            <svg class="w-4 h-4" style="color:#d97706;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
            </svg>
            <span class="font-semibold text-sm" style="color:#d97706;">Filtres en cascade</span>
        </div>
        <div class="p-5">
            <form method="GET" action="{{ route('comparaison-fichiers') }}">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Région</label>
                        <select id="cf_region" name="region_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm">
                            <option value="">-- Toutes --</option>
                            @foreach($regions as $r)
                                <option value="{{ $r->id }}" {{ ($filtres['region_id'] ?? '') == $r->id ? 'selected' : '' }}>{{ $r->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Département</label>
                        <select id="cf_dept" name="dept_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm" disabled>
                            <option value="">-- Tous --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Arrondissement</label>
                        <select id="cf_arr" name="arr_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm" disabled>
                            <option value="">-- Tous --</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Commune</label>
                        <select id="cf_commune" name="commune_id"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm" disabled>
                            <option value="">-- Toutes --</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit"
                            class="px-5 py-2 rounded-lg text-sm font-medium text-white"
                            style="background:#d97706;">Appliquer</button>
                    <a href="{{ route('comparaison-fichiers') }}"
                       class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-300 hover:bg-slate-50">Réinitialiser</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Totaux résumé --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="bg-white rounded-xl p-4 border-2" style="border-color:#94a3b8;">
            <p class="text-xs uppercase font-bold tracking-wider text-slate-500">Ancien fichier</p>
            <div class="mt-2 grid grid-cols-3 gap-2 text-sm">
                <div><span class="font-bold text-slate-700">{{ number_format($totals['old']['lieux']) }}</span><br><span class="text-xs text-slate-400">lieux</span></div>
                <div><span class="font-bold text-slate-700">{{ number_format($totals['old']['bureaux']) }}</span><br><span class="text-xs text-slate-400">bureaux</span></div>
                <div><span class="font-bold text-slate-700">{{ number_format($totals['old']['electeurs']) }}</span><br><span class="text-xs text-slate-400">électeurs</span></div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-4 border-2" style="border-color:#16a34a;">
            <p class="text-xs uppercase font-bold tracking-wider" style="color:#16a34a;">Nouveau fichier</p>
            <div class="mt-2 grid grid-cols-3 gap-2 text-sm">
                <div><span class="font-bold text-slate-700">{{ number_format($totals['new']['lieux']) }}</span><br><span class="text-xs text-slate-400">lieux</span></div>
                <div><span class="font-bold text-slate-700">{{ number_format($totals['new']['bureaux']) }}</span><br><span class="text-xs text-slate-400">bureaux</span></div>
                <div><span class="font-bold text-slate-700">{{ number_format($totals['new']['electeurs']) }}</span><br><span class="text-xs text-slate-400">électeurs</span></div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-4 border-2" style="border-color:#7c3aed;">
            <p class="text-xs uppercase font-bold tracking-wider" style="color:#7c3aed;">Différence (nouveau - ancien)</p>
            <div class="mt-2 grid grid-cols-3 gap-2 text-sm">
                <div><span class="font-bold {{ $totals['diff']['lieux'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ ($totals['diff']['lieux'] >= 0 ? '+' : '').number_format($totals['diff']['lieux']) }}</span><br><span class="text-xs text-slate-400">lieux</span></div>
                <div><span class="font-bold {{ $totals['diff']['bureaux'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ ($totals['diff']['bureaux'] >= 0 ? '+' : '').number_format($totals['diff']['bureaux']) }}</span><br><span class="text-xs text-slate-400">bureaux</span></div>
                <div><span class="font-bold {{ $totals['diff']['electeurs'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ ($totals['diff']['electeurs'] >= 0 ? '+' : '').number_format($totals['diff']['electeurs']) }}</span><br><span class="text-xs text-slate-400">électeurs</span></div>
            </div>
        </div>
    </div>

    {{-- 3 tableaux côte à côte --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Ancien --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b" style="background:#f1f5f9;">
                <h3 class="font-bold text-sm text-slate-700">📕 Ancien fichier</h3>
                <p class="text-xs text-slate-500">{{ $rowsOld->count() }} {{ Str::lower($levelLabel) }}{{ $rowsOld->count() > 1 ? 's' : '' }}</p>
            </div>
            <div class="overflow-x-auto max-h-[600px]">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-slate-100">
                        <tr>
                            <th class="px-2 py-2 text-left font-semibold">{{ $levelLabel }}</th>
                            <th class="px-2 py-2 text-right font-semibold">Lieux</th>
                            <th class="px-2 py-2 text-right font-semibold">Bureaux</th>
                            <th class="px-2 py-2 text-right font-semibold">Électeurs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rowsOld as $r)
                            <tr class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-2 py-1 font-medium">{{ $r->label }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($r->nb_lieux) }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($r->nb_bureaux) }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($r->nb_electeurs) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-2 py-6 text-center text-slate-400">Aucune donnée</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Nouveau --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b" style="background:#dcfce7;">
                <h3 class="font-bold text-sm" style="color:#16a34a;">📗 Nouveau fichier</h3>
                <p class="text-xs text-slate-500">{{ $rowsNew->count() }} {{ Str::lower($levelLabel) }}{{ $rowsNew->count() > 1 ? 's' : '' }}</p>
            </div>
            <div class="overflow-x-auto max-h-[600px]">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-slate-100">
                        <tr>
                            <th class="px-2 py-2 text-left font-semibold">{{ $levelLabel }}</th>
                            <th class="px-2 py-2 text-right font-semibold">Lieux</th>
                            <th class="px-2 py-2 text-right font-semibold">Bureaux</th>
                            <th class="px-2 py-2 text-right font-semibold">Électeurs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rowsNew as $r)
                            <tr class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-2 py-1 font-medium">{{ $r->label }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($r->nb_lieux) }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($r->nb_bureaux) }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($r->nb_electeurs) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-2 py-6 text-center text-slate-400">Aucune donnée</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Différence --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b" style="background:#ede9fe;">
                <h3 class="font-bold text-sm" style="color:#7c3aed;">📊 Différence (nouveau - ancien)</h3>
                <p class="text-xs text-slate-500">{{ $diffRows->count() }} ligne(s)</p>
            </div>
            <div class="overflow-x-auto max-h-[600px]">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-slate-100">
                        <tr>
                            <th class="px-2 py-2 text-left font-semibold">{{ $levelLabel }}</th>
                            <th class="px-2 py-2 text-right font-semibold">ΔL</th>
                            <th class="px-2 py-2 text-right font-semibold">ΔB</th>
                            <th class="px-2 py-2 text-right font-semibold">ΔÉlect.</th>
                            <th class="px-2 py-2 text-center font-semibold">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($diffRows as $r)
                            @php
                                $statusBadge = match($r->status) {
                                    'nouveau' => ['bg' => '#dcfce7', 'fg' => '#16a34a', 'lbl' => 'NOUV.'],
                                    'supprime' => ['bg' => '#fee2e2', 'fg' => '#dc2626', 'lbl' => 'SUPPR.'],
                                    default => ['bg' => '#fef3c7', 'fg' => '#d97706', 'lbl' => 'MODIF.'],
                                };
                            @endphp
                            <tr class="border-t border-slate-100 hover:bg-slate-50">
                                <td class="px-2 py-1 font-medium">{{ $r->label }}</td>
                                <td class="px-2 py-1 text-right font-mono {{ $r->diff_lieux > 0 ? 'text-green-600' : ($r->diff_lieux < 0 ? 'text-red-600' : 'text-slate-400') }}">{{ ($r->diff_lieux > 0 ? '+' : '').$r->diff_lieux }}</td>
                                <td class="px-2 py-1 text-right font-mono {{ $r->diff_bureaux > 0 ? 'text-green-600' : ($r->diff_bureaux < 0 ? 'text-red-600' : 'text-slate-400') }}">{{ ($r->diff_bureaux > 0 ? '+' : '').$r->diff_bureaux }}</td>
                                <td class="px-2 py-1 text-right font-mono {{ $r->diff_electeurs > 0 ? 'text-green-600' : ($r->diff_electeurs < 0 ? 'text-red-600' : 'text-slate-400') }}">{{ ($r->diff_electeurs > 0 ? '+' : '').number_format($r->diff_electeurs) }}</td>
                                <td class="px-2 py-1 text-center">
                                    <span class="text-xs font-bold px-1.5 py-0.5 rounded" style="background:{{ $statusBadge['bg'] }}; color:{{ $statusBadge['fg'] }};">{{ $statusBadge['lbl'] }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-2 py-6 text-center text-slate-400">Aucune donnée</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>
@endsection

@push('scripts')
<script>
(function () {
    var allDepts    = @json($allDepts);
    var allArrs     = @json($allArrs);
    var allCommunes = @json($allCommunes);

    var cfRegion  = document.getElementById('cf_region');
    var cfDept    = document.getElementById('cf_dept');
    var cfArr     = document.getElementById('cf_arr');
    var cfCommune = document.getElementById('cf_commune');

    function fillSelect(sel, items, enabled) {
        var firstOpt = sel.options[0].text;
        sel.innerHTML = '<option value="">' + firstOpt + '</option>';
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.nom;
            sel.appendChild(opt);
        });
        sel.disabled = !enabled || items.length === 0;
    }

    function resetFrom(level) {
        if (level <= 1) fillSelect(cfDept, [], false);
        if (level <= 2) fillSelect(cfArr, [], false);
        if (level <= 3) fillSelect(cfCommune, [], false);
    }

    cfRegion.addEventListener('change', function () {
        resetFrom(1);
        if (!this.value) return;
        var depts = allDepts.filter(d => d.region_id == this.value);
        fillSelect(cfDept, depts, true);
    });

    cfDept.addEventListener('change', function () {
        resetFrom(2);
        if (!this.value) return;
        var arrs = allArrs.filter(a => a.departement_id == this.value);
        fillSelect(cfArr, arrs, true);
    });

    cfArr.addEventListener('change', function () {
        resetFrom(3);
        if (!this.value) return;
        var communes = allCommunes.filter(c => c.arrondissement_id == this.value);
        fillSelect(cfCommune, communes, true);
    });

    // Restore cascade after submit
    var savedRegionId  = cfRegion.value;
    var savedDeptId    = '{{ $filtres["dept_id"] ?? "" }}';
    var savedArrId     = '{{ $filtres["arr_id"] ?? "" }}';
    var savedCommuneId = '{{ $filtres["commune_id"] ?? "" }}';

    if (savedRegionId) {
        var depts = allDepts.filter(d => d.region_id == savedRegionId);
        fillSelect(cfDept, depts, true);
        if (savedDeptId) {
            cfDept.value = savedDeptId;
            var arrs = allArrs.filter(a => a.departement_id == savedDeptId);
            fillSelect(cfArr, arrs, true);
            if (savedArrId) {
                cfArr.value = savedArrId;
                var communes = allCommunes.filter(c => c.arrondissement_id == savedArrId);
                fillSelect(cfCommune, communes, true);
                if (savedCommuneId) cfCommune.value = savedCommuneId;
            }
        }
    }
})();
</script>
@endpush

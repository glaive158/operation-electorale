@extends('layouts.app')
@section('title','Clés de répartition')
@section('page-title','Clés de répartition')

@php
    $peutAdministrer = \Illuminate\Support\Facades\Gate::allows('logistique-administrer') && $cycle->estOuvert();
    $champ = 'w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500';
    $pourcentage = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    @if($peutAdministrer)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
            <form method="POST" action="{{ route('logistique.cles.copier', $cycle) }}" class="flex flex-wrap gap-2 items-center">
                @csrf
                <select name="source_id" required aria-label="Cycle source" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    <option value="">Copier les clés manquantes depuis le cycle…</option>
                    @foreach($autresCycles as $c) <option value="{{ $c->id }}">{{ $c->libelle }}</option> @endforeach
                </select>
                <button type="submit" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm hover:bg-slate-50">Copier</button>
            </form>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $materiels->count() }} article(s) — majoration du cycle {{ $pourcentage($cycle->majoration_distribuee) }} %, réserve du cycle {{ $pourcentage($cycle->reserve_centrale) }} %</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        <th class="px-5 py-3 text-left font-semibold">Base</th>
                        <th class="px-5 py-3 text-right font-semibold">Ratio</th>
                        <th class="px-5 py-3 text-right font-semibold">Majoration</th>
                        <th class="px-5 py-3 text-right font-semibold">Réserve</th>
                        <th class="px-5 py-3 text-right font-semibold">Minimum</th>
                        <th class="px-5 py-3 text-left font-semibold">Arrondi</th>
                        <th class="px-5 py-3 text-left font-semibold">Clé affichée</th>
                        @if($peutAdministrer)<th class="px-5 py-3 text-left font-semibold">Actions</th>@endif
                    </tr>
                </thead>
                @foreach($materiels as $m)
                    @php $cle = $cles->get($m->id); @endphp
                    <tbody class="divide-y divide-slate-50 border-t border-slate-50" x-data="{ ouvert: false }">
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $m->libelle }}</td>
                            @if($cle)
                                <td class="px-5 py-3 text-slate-600">{{ \App\Models\Logistique\CleRepartition::LIBELLES_BASE[$cle->base] ?? $cle->base }}@if($cle->par_entite) <span class="text-xs text-slate-400">· par entité</span>@endif</td>
                                <td class="px-5 py-3 text-right text-slate-600">{{ $cle->ratio }}</td>
                                <td class="px-5 py-3 text-right text-slate-600">{{ $pourcentage($cle->majorationEffective()) }} %@if($cle->majoration_distribuee === null) <span class="text-xs text-slate-400">(cycle)</span>@endif</td>
                                <td class="px-5 py-3 text-right text-slate-600">{{ $pourcentage($cle->reserveEffective()) }} %@if($cle->reserve_centrale === null) <span class="text-xs text-slate-400">(cycle)</span>@endif</td>
                                <td class="px-5 py-3 text-right text-slate-600">{{ $cle->minimum }}@if($cle->minimum_par_commune) <span class="text-xs text-slate-400">/ commune</span>@endif</td>
                                <td class="px-5 py-3 text-slate-600">{{ \App\Models\Logistique\CleRepartition::LIBELLES_ARRONDI[$cle->arrondi] ?? $cle->arrondi }}@if($cle->arrondi_conditionnement) <span class="text-xs text-slate-400">· conditionnement</span>@endif</td>
                                <td class="px-5 py-3 text-slate-600">{{ $cle->cle_affichee ?? '—' }}</td>
                            @else
                                <td colspan="7" class="px-5 py-3 text-slate-400">Clé non définie</td>
                            @endif
                            @if($peutAdministrer)
                                <td class="px-5 py-3"><button type="button" @click="ouvert = !ouvert" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">{{ $cle ? 'Modifier' : 'Définir' }}</button></td>
                            @endif
                        </tr>
                        @if($peutAdministrer)
                            <tr x-show="ouvert" x-cloak class="bg-slate-50">
                                <td colspan="9" class="px-5 py-4">
                                    <form method="POST" action="{{ route('logistique.cles.update', [$cycle, $m]) }}" class="space-y-3">
                                        @csrf @method('PUT')
                                        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Base</label>
                                                <select name="base" class="{{ $champ }}">
                                                    @foreach(\App\Models\Logistique\CleRepartition::LIBELLES_BASE as $v => $l)
                                                        <option value="{{ $v }}" {{ ($cle->base ?? 'electeur') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Ratio</label>
                                                <input type="text" inputmode="decimal" name="ratio" value="{{ $cle->ratio ?? '1' }}" required class="{{ $champ }}">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Majoration (%, vide = cycle)</label>
                                                <input type="text" inputmode="decimal" name="majoration_distribuee" value="{{ $cle?->majoration_distribuee }}" class="{{ $champ }}">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Réserve centrale (%, vide = cycle)</label>
                                                <input type="text" inputmode="decimal" name="reserve_centrale" value="{{ $cle?->reserve_centrale }}" class="{{ $champ }}">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Minimum</label>
                                                <input type="number" min="0" max="10000000" step="1" name="minimum" value="{{ $cle->minimum ?? 0 }}" class="{{ $champ }}">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Arrondi</label>
                                                <select name="arrondi" class="{{ $champ }}">
                                                    @foreach(\App\Models\Logistique\CleRepartition::LIBELLES_ARRONDI as $v => $l)
                                                        <option value="{{ $v }}" {{ ($cle->arrondi ?? 'unite') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="md:col-span-2">
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Clé affichée</label>
                                                <input type="text" name="cle_affichee" value="{{ $cle?->cle_affichee }}" maxlength="150" class="{{ $champ }}">
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                                            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="par_entite" value="1" {{ $cle?->par_entite ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;"> Répartie par entité</label>
                                            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="minimum_par_commune" value="1" {{ $cle?->minimum_par_commune ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;"> Minimum par commune</label>
                                            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="arrondi_conditionnement" value="1" {{ $cle?->arrondi_conditionnement ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;"> Arrondi au conditionnement</label>
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer</button>
                                            <button type="button" @click="ouvert = false" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-white">Annuler</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @endforeach
            </table>
        </div>
    </div>
</div>
@endsection

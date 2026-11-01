@extends('layouts.app')
@section('title','Entités')
@section('page-title','Candidats, listes et options')

@php
    $peutAdministrer = \Illuminate\Support\Facades\Gate::allows('logistique-administrer') && $cycle->estOuvert();
    $libellesType = ['candidat' => 'Candidat', 'liste' => 'Liste', 'option' => 'Option'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    @if($peutAdministrer)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5" x-data="{ national: {{ old('national', '1') ? 'true' : 'false' }} }">
            <h3 class="font-semibold text-slate-800 mb-3">Ajouter une entité</h3>
            <form method="POST" action="{{ route('logistique.entites.store', $cycle) }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-slate-600 mb-1">Type</label>
                        <select name="type" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                            @foreach($libellesType as $v => $l) <option value="{{ $v }}" {{ old('type') === $v ? 'selected' : '' }}>{{ $l }}</option> @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-5">
                        <label class="block text-xs font-medium text-slate-600 mb-1">Nom</label>
                        <input type="text" name="nom" value="{{ old('nom') }}" required maxlength="180" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-slate-600 mb-1">Ordre sur le bulletin</label>
                        <input type="number" name="ordre" min="0" max="65535" value="{{ old('ordre', 0) }}" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    </div>
                    <label class="md:col-span-3 flex items-center gap-2 text-sm text-slate-600 pb-2">
                        <input type="checkbox" name="national" value="1" x-model="national" class="rounded border-slate-300" style="accent-color:#009A44;">
                        Entité nationale
                    </label>
                </div>
                <div x-show="!national" x-cloak class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Départements</label>
                        <select name="departements[]" multiple size="6" x-bind:disabled="national" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                            @foreach($departements as $d) <option value="{{ $d->id }}">{{ $d->nom }} ({{ $d->region_nom }})</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Communes</label>
                        <select name="communes[]" multiple size="6" x-bind:disabled="national" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                            @foreach($communes as $c) <option value="{{ $c->id }}">{{ $c->nom }} — {{ $c->departement_nom }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Pays (un par ligne)</label>
                        <textarea name="pays" rows="6" x-bind:disabled="national" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">{{ old('pays') }}</textarea>
                    </div>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Ajouter</button>
            </form>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $entites->count() }} entité(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-right font-semibold">Ordre</th>
                        <th class="px-5 py-3 text-left font-semibold">Type</th>
                        <th class="px-5 py-3 text-left font-semibold">Nom</th>
                        <th class="px-5 py-3 text-left font-semibold">Bulletin</th>
                        <th class="px-5 py-3 text-left font-semibold">Périmètre</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($entites as $e)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-right text-slate-600">{{ $e->ordre }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $libellesType[$e->type] ?? $e->type }}</td>
                            <td class="px-5 py-3">
                                @if($peutAdministrer)
                                    <form method="POST" action="{{ route('logistique.entites.update', [$cycle, $e]) }}" class="flex gap-2 items-center">
                                        @csrf @method('PUT')
                                        <input type="text" name="nom" value="{{ $e->nom }}" required maxlength="180" aria-label="Nom" class="w-56 px-2 py-1 rounded-lg border border-slate-300 text-xs focus:outline-none">
                                        <button type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Renommer</button>
                                    </form>
                                @else
                                    <span class="font-medium text-slate-800">{{ $e->nom }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $e->materiel?->libelle }} @if($e->materiel && ! $e->materiel->actif) <span class="text-xs text-slate-400">(désactivé)</span> @endif</td>
                            <td class="px-5 py-3 text-slate-600">
                                @if($e->national) National
                                @else
                                    {{ $e->perimetres->map(fn ($p) => $p->departement_id ? 'Département '.($departementsParId->get($p->departement_id)->nom ?? $p->departement_id)
                                        : ($p->commune_id ? 'Commune '.($communesParId->get($p->commune_id)->nom ?? $p->commune_id) : 'Pays '.$p->pays))->implode(', ') }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-10 text-center text-slate-400">Aucune entité</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

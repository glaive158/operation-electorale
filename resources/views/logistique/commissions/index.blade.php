@extends('layouts.app')
@section('title','Commissions administratives')
@section('page-title','Commissions administratives')

@php
    $peutAdministrer = \Illuminate\Support\Facades\Gate::allows('logistique-administrer') && $cycle->estOuvert();
    $libellesType = ['fixe' => 'Fixe', 'itinerante' => 'Itinérante', 'etranger' => 'À l\'étranger'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    @if($peutAdministrer)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 space-y-4" x-data="{ type: '{{ old('type', 'fixe') }}' }">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold text-slate-800">Ajouter une commission</h3>
                <form method="POST" action="{{ route('logistique.commissions.fixes', $cycle) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm hover:bg-slate-50">Créer les commissions fixes manquantes</button>
                </form>
            </div>
            <form method="POST" action="{{ route('logistique.commissions.store', $cycle) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                @csrf
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Type</label>
                    <select name="type" x-model="type" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        @foreach($libellesType as $v => $l) <option value="{{ $v }}">{{ $l }}</option> @endforeach
                    </select>
                </div>
                <div class="md:col-span-4" x-show="type !== 'etranger'">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Commune</label>
                    <select name="commune_id" x-bind:disabled="type === 'etranger'" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        <option value="">Choisir…</option>
                        @foreach($communes as $c)
                            <option value="{{ $c->id }}" {{ (string) old('commune_id') === (string) $c->id ? 'selected' : '' }}>{{ $c->nom }} — {{ $c->departement_nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2" x-show="type === 'etranger'" x-cloak>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Pays</label>
                    <input type="text" name="pays" value="{{ old('pays') }}" maxlength="100" x-bind:disabled="type !== 'etranger'" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                </div>
                <div class="md:col-span-2" x-show="type === 'etranger'" x-cloak>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Ville</label>
                    <input type="text" name="ville" value="{{ old('ville') }}" maxlength="100" x-bind:disabled="type !== 'etranger'" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                </div>
                <div class="md:col-span-4">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Libellé (facultatif)</label>
                    <input type="text" name="libelle" value="{{ old('libelle') }}" maxlength="200" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="w-full px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Ajouter</button>
                </div>
            </form>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $commissions->total() }} commission(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Type</th>
                        <th class="px-5 py-3 text-left font-semibold">Localité</th>
                        <th class="px-5 py-3 text-left font-semibold">Libellé</th>
                        @if($peutAdministrer)<th class="px-5 py-3 text-left font-semibold">Actions</th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($commissions as $c)
                        @php $commune = $c->commune_id ? $communesParId->get($c->commune_id) : null; @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-slate-600">{{ $libellesType[$c->type] ?? $c->type }}</td>
                            <td class="px-5 py-3 font-medium text-slate-800">
                                @if($c->commune_id) {{ $commune ? "{$commune->nom} ({$commune->departement_nom})" : "Commune n° {$c->commune_id}" }}
                                @else {{ $c->pays }} | {{ $c->ville }} @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $c->libelle ?? '—' }}</td>
                            @if($peutAdministrer)
                                <td class="px-5 py-3">
                                    <form method="POST" action="{{ route('logistique.commissions.destroy', [$cycle, $c]) }}" class="flex gap-2 items-center"
                                          onsubmit="return confirm('Supprimer cette commission ?');">
                                        @csrf @method('DELETE')
                                        <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif de la suppression"
                                               class="w-44 px-2 py-1 rounded-lg border border-slate-300 text-xs focus:outline-none">
                                        <button type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#EE1C25;">Supprimer</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-10 text-center text-slate-400">Aucune commission</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($commissions->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $commissions->links() }}</div>
        @endif
    </div>
</div>
@endsection

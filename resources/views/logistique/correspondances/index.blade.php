@extends('layouts.app')
@section('title','Correspondances')
@section('page-title','Correspondances des communes')

@php
    $peutAdministrer = \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    $libellesStatut = ['proposee' => 'À valider', 'validee' => 'Validées', 'rejetee' => 'Rejetées'];
    $classesStatut = ['proposee' => 'bg-amber-100 text-amber-700', 'validee' => 'bg-green-100 text-green-700', 'rejetee' => 'bg-red-100 text-red-700'];
@endphp

@section('content')
<div class="space-y-5" x-data="{ cible: null }">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-center">
            <select name="statut" aria-label="Statut" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="tous" {{ $statut === null ? 'selected' : '' }}>Tous les statuts</option>
                @foreach($libellesStatut as $v => $l)
                    <option value="{{ $v }}" {{ $statut === $v ? 'selected' : '' }}>{{ $l }} ({{ $compteurs[$v] ?? 0 }})</option>
                @endforeach
            </select>
            <div class="flex gap-2 md:col-start-3">
                <button class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex-1" style="background:#009A44;">Filtrer</button>
                <a href="{{ route('logistique.correspondances.index') }}" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">↺</a>
            </div>
        </div>
    </form>

    @if($peutAdministrer)
        <div x-show="cible" x-cloak class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
            <h3 class="font-semibold text-slate-800 mb-3">Valider : <span x-text="cible && cible.libelle"></span></h3>
            <form method="POST" :action="cible && cible.url" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                @csrf
                <div class="md:col-span-10">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Commune du recensement</label>
                    <select name="commune_id" required x-bind:value="cible && cible.commune_id ? cible.commune_id : ''" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        <option value="">Choisir…</option>
                        @foreach($communes as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }} — {{ $c->departement_nom }} ({{ $c->region_nom }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="w-full px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Valider</button>
                </div>
            </form>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $correspondances->total() }} correspondance(s)</h3>
            @if($peutAdministrer)
                <form method="POST" action="{{ route('logistique.correspondances.rapprocher') }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Lancer le rapprochement</button>
                </form>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Région</th>
                        <th class="px-5 py-3 text-left font-semibold">Département</th>
                        <th class="px-5 py-3 text-left font-semibold">Commune du fichier</th>
                        <th class="px-5 py-3 text-left font-semibold">Commune du recensement</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-left font-semibold">Décision</th>
                        @if($peutAdministrer)<th class="px-5 py-3 text-left font-semibold">Actions</th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($correspondances as $c)
                        @php $commune = $c->commune_id ? $communesParId->get($c->commune_id) : null; @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-slate-600">{{ $c->contexte_region }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $c->contexte_departement }}</td>
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $c->libelle_commune }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $commune ? "{$commune->nom} ({$commune->departement_nom})" : '—' }}</td>
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesStatut[$c->statut] ?? '' }}">{{ $libellesStatut[$c->statut] ?? $c->statut }}</span></td>
                            <td class="px-5 py-3 text-xs text-slate-500">
                                {{ $c->methode === 'auto' ? 'Automatique' : 'Manuelle' }}
                                @if($c->validePar) · {{ $c->validePar->nom_complet }} @endif
                                @if($c->motif) · {{ $c->motif }} @endif
                            </td>
                            @if($peutAdministrer)
                                <td class="px-5 py-3">
                                    <div class="flex flex-wrap gap-2 items-center">
                                        <button type="button" @click="cible = @js(['url' => route('logistique.correspondances.valider', $c), 'commune_id' => $c->commune_id, 'libelle' => $c->libelle_commune]); $nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }))"
                                                class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#009A44;">Valider</button>
                                        @if($c->statut !== 'rejetee')
                                            <form method="POST" action="{{ route('logistique.correspondances.rejeter', $c) }}" class="flex gap-2 items-center">
                                                @csrf
                                                <input type="text" name="motif" required maxlength="500" placeholder="Motif du rejet" aria-label="Motif du rejet"
                                                       class="w-40 px-2 py-1 rounded-lg border border-slate-300 text-xs focus:outline-none">
                                                <button type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#EE1C25;">Rejeter</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-10 text-center text-slate-400">Aucune correspondance</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($correspondances->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $correspondances->links() }}</div>
        @endif
    </div>
</div>
@endsection

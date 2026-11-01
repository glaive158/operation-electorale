@extends('layouts.app')
@section('title','Découpage')
@section('page-title','Découpage du cycle')

@php
    $peutAdministrer = \Illuminate\Support\Facades\Gate::allows('logistique-administrer') && $cycle->estOuvert();
    $sans = request()->boolean('sans_destinataire');
    $libelleLocalite = function ($p) use ($communes) {
        if ($p->commune_id !== null) {
            $c = $communes->get($p->commune_id);

            return $c ? "{$c->nom} ({$c->departement_nom})" : "Commune n° {$p->commune_id} (inconnue)";
        }

        return "{$p->pays} | {$p->ville}";
    };
@endphp

@section('content')
<div class="space-y-5" x-data="{ cible: null }">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-center">
            <select name="destinataire" aria-label="Destinataire" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none md:col-span-2">
                <option value="">Tous les destinataires</option>
                @foreach($destinataires as $d)
                    <option value="{{ $d->id }}" {{ (string) request('destinataire') === (string) $d->id ? 'selected' : '' }}>{{ $d->libelle }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="sans_destinataire" value="1" {{ $sans ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;">
                Sans destinataire ({{ $sansDestinataire->count() }})
            </label>
            <div class="flex gap-2">
                <button class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex-1" style="background:#009A44;">Filtrer</button>
                <a href="{{ route('logistique.decoupage.show', $cycle) }}" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">↺</a>
            </div>
        </div>
    </form>

    @if($peutAdministrer)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex flex-wrap gap-4 items-end">
            <form method="POST" action="{{ route('logistique.decoupage.generer', $cycle) }}">
                @csrf
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Générer le périmètre</button>
            </form>
            <form method="POST" action="{{ route('logistique.decoupage.copier', $cycle) }}" class="flex gap-2 items-center">
                @csrf
                <select name="source_id" required aria-label="Cycle source" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    <option value="">Copier depuis le cycle…</option>
                    @foreach($autresCycles as $c)
                        <option value="{{ $c->id }}">{{ $c->libelle }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm hover:bg-slate-50">Copier</button>
            </form>
        </div>

        <div x-show="cible" x-cloak class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 space-y-4">
            <h3 class="font-semibold text-slate-800">Localité : <span x-text="cible && cible.libelle"></span></h3>
            <form method="POST" action="{{ route('logistique.decoupage.affecter', $cycle) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                @csrf
                <input type="hidden" name="commune_id" :value="cible && cible.commune_id ? cible.commune_id : ''">
                <input type="hidden" name="pays" :value="cible && cible.pays ? cible.pays : ''">
                <input type="hidden" name="ville" :value="cible && cible.ville ? cible.ville : ''">
                <div class="md:col-span-5">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Destinataire</label>
                    <select name="destinataire_id" required class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        <option value="">Choisir…</option>
                        @foreach($destinataires as $d)
                            <option value="{{ $d->id }}">{{ $d->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-5">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Motif</label>
                    <input type="text" name="motif" required maxlength="500" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="w-full px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Affecter</button>
                </div>
            </form>
            <form method="POST" action="{{ route('logistique.decoupage.retirer', $cycle) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end"
                  onsubmit="return confirm('Retirer cette localité du périmètre du cycle ?');">
                @csrf @method('DELETE')
                <input type="hidden" name="commune_id" :value="cible && cible.commune_id ? cible.commune_id : ''">
                <input type="hidden" name="pays" :value="cible && cible.pays ? cible.pays : ''">
                <input type="hidden" name="ville" :value="cible && cible.ville ? cible.ville : ''">
                <div class="md:col-span-10">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Retirer du périmètre (commune sans électeur, par exemple) : motif</label>
                    <input type="text" name="motif" required maxlength="500" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="w-full px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">Retirer</button>
                </div>
            </form>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">
                @if($sans) {{ $sansDestinataire->count() }} localité(s) sans destinataire
                @else {{ $perimetre->total() }} localité(s) au périmètre @endif
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Localité</th>
                        <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                        @if($peutAdministrer)<th class="px-5 py-3 text-left font-semibold">Actions</th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @if($sans)
                        @forelse($sansDestinataire as $l)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3 text-slate-800">{{ $l['libelle'] }}</td>
                                <td class="px-5 py-3 text-slate-400">Aucun</td>
                                @if($peutAdministrer)
                                    <td class="px-5 py-3"><button type="button" @click="cible = @js($l); $nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }))" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Affecter</button></td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-6 py-10 text-center text-slate-400">Toutes les localités ont un destinataire</td></tr>
                        @endforelse
                    @else
                        @forelse($perimetre as $p)
                            @php $libelle = $libelleLocalite($p); @endphp
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3 text-slate-800">{{ $libelle }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $p->destinataire?->libelle }}</td>
                                @if($peutAdministrer)
                                    <td class="px-5 py-3"><button type="button" @click="cible = @js(['commune_id' => $p->commune_id, 'pays' => $p->pays, 'ville' => $p->ville, 'libelle' => $libelle]); $nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }))" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Modifier</button></td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-6 py-10 text-center text-slate-400">Aucune localité au périmètre</td></tr>
                        @endforelse
                    @endif
                </tbody>
            </table>
        </div>
        @if(! $sans && $perimetre->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $perimetre->links() }}</div>
        @endif
    </div>
</div>
@endsection

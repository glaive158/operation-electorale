@extends('layouts.app')
@section('title','Besoin')
@section('page-title','Besoin et proposition de commande')

@php
    $peutCommander = \Illuminate\Support\Facades\Gate::allows('logistique-administrer') && $cycle->estOuvert() && $tableau->isNotEmpty();
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    @if($plan === null)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-8 text-center text-slate-500">
            Aucun plan de dotation approuvé pour ce cycle : le besoin se calcule à partir du plan en vigueur.
            <a href="{{ route('logistique.plans.index', $cycle) }}" class="underline">Voir les plans</a>
        </div>
    @else
        @if($brouillons->isNotEmpty())
            <div class="p-4 rounded-xl border border-amber-200 bg-amber-50 text-sm text-amber-800">
                <p class="font-semibold">Des commandes brouillon existent déjà pour ce cycle.</p>
                <ul class="list-disc ml-5 mt-1">
                    @foreach($brouillons as $c)
                        <li>
                            <a href="{{ route('logistique.commandes.show', $c) }}" class="underline">{{ $c->reference }}</a>
                            — {{ $c->date_commande?->format('d/m/Y') }} — {{ $c->lignes_count }} {{ $c->lignes_count > 1 ? 'lignes' : 'ligne' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('logistique.besoin.commander', $cycle) }}" class="space-y-5">
            @csrf
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100">
                    <h3 class="font-semibold text-slate-800">Plan en vigueur : version {{ $plan->version }}</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                                <th class="px-4 py-3 text-left font-semibold">Article</th>
                                <th class="px-4 py-3 text-right font-semibold">Besoin</th>
                                <th class="px-4 py-3 text-right font-semibold">Expédié</th>
                                <th class="px-4 py-3 text-right font-semibold">Reste à expédier</th>
                                <th class="px-4 py-3 text-right font-semibold">Surplus expédié</th>
                                <th class="px-4 py-3 text-right font-semibold">Réserve cible</th>
                                <th class="px-4 py-3 text-right font-semibold">Réserve en stock</th>
                                <th class="px-4 py-3 text-right font-semibold">Attribué</th>
                                <th class="px-4 py-3 text-right font-semibold">Commandé à recevoir</th>
                                <th class="px-4 py-3 text-right font-semibold">Proposition</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($tableau as $l)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-4 py-3 font-medium text-slate-800">{{ $l['materiel']->libelle }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $n($l['besoin']) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $n($l['expedie']) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $n($l['reste']) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $n($l['surplus']) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $n($l['reserve_cible']) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $n($l['reserve_stock']) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $n($l['attribue']) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $n($l['a_recevoir']) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        @if($peutCommander)
                                            <input type="number" min="0" max="10000000" step="1" name="quantites[{{ $l['materiel']->id }}]"
                                                   value="{{ old('quantites.'.$l['materiel']->id, $l['approvisionnement']) }}" aria-label="Quantité à commander : {{ $l['materiel']->libelle }}"
                                                   class="w-28 px-2 py-1 rounded-lg border border-slate-300 text-sm text-right focus:outline-none">
                                        @else
                                            <span class="font-semibold text-slate-800">{{ $n($l['approvisionnement']) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="px-6 py-10 text-center text-slate-400">Aucun article dans le plan</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($peutCommander)
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
                    <h3 class="font-semibold text-slate-800">Convertir en commande (brouillon)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1.5">Référence <span class="text-red-500">*</span></label>
                            <input type="text" name="reference" value="{{ old('reference') }}" required maxlength="50" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1.5">Fournisseur <span class="text-red-500">*</span></label>
                            <input type="text" name="fournisseur" value="{{ old('fournisseur') }}" required maxlength="200" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1.5">Date de commande <span class="text-red-500">*</span></label>
                            <input type="date" name="date_commande" value="{{ old('date_commande', now()->format('Y-m-d')) }}" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1.5">Motif (obligatoire si les quantités diffèrent de la proposition)</label>
                        <input type="text" name="motif" value="{{ old('motif') }}" maxlength="500" class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="px-6 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Créer la commande brouillon</button>
                    </div>
                </div>
            @endif
        </form>
    @endif
</div>
@endsection

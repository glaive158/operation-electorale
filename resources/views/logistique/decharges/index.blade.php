@extends('layouts.app')
@section('title','Décharges')
@section('page-title','Décharges')

@php
    $libellesDocument = ['brouillon' => 'Brouillon', 'validee' => 'Validée', 'annulee' => 'Annulée'];
    $classesDocument = ['brouillon' => 'bg-amber-100 text-amber-700', 'validee' => 'bg-green-100 text-green-700', 'annulee' => 'bg-red-100 text-red-700'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3 items-center">
            <select name="statut" aria-label="Statut" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les statuts</option>
                @foreach($libellesDocument as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($statut === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select name="categorie" aria-label="Catégorie" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Toutes les catégories</option>
                @foreach(\App\Models\Logistique\Decharge::CATEGORIES as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($categorie === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select name="nature" aria-label="Nature" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Toutes les natures</option>
                @foreach(\App\Models\Logistique\Decharge::NATURES as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($nature === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select name="departement" aria-label="Département" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les départements</option>
                @foreach($departements as $d)
                    <option value="{{ $d['departement_id'] }}" @selected($departement === $d['departement_id'])>{{ $d['nom'] }}</option>
                @endforeach
            </select>
            <select name="destinataire" aria-label="Destinataire" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les destinataires</option>
                @foreach($destinataires as $d)
                    <option value="{{ $d->id }}" @selected($destinataire === $d->id)>{{ $d->libelle }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#0284c7;">Filtrer</button>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $decharges->total() }} décharge(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Numéro</th>
                        <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                        <th class="px-5 py-3 text-left font-semibold">Catégorie</th>
                        <th class="px-5 py-3 text-left font-semibold">Nature</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-left font-semibold">Acheminement</th>
                        <th class="px-5 py-3 text-left font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($decharges as $d)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $d->numero ?? 'Brouillon' }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $d->destinataire?->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ \App\Models\Logistique\Decharge::CATEGORIES[$d->categorie] ?? $d->categorie }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ \App\Models\Logistique\Decharge::NATURES[$d->nature] ?? $d->nature }}</td>
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesDocument[$d->statut_document] ?? '' }}">{{ $libellesDocument[$d->statut_document] ?? $d->statut_document }}</span></td>
                            <td class="px-5 py-3 text-slate-600">{{ $d->statut_acheminement }}</td>
                            <td class="px-5 py-3"><a href="{{ route('logistique.decharges.show', $d) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Détail</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-10 text-center text-slate-400">Aucune décharge</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($decharges->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $decharges->links() }}</div>
        @endif
    </div>

    <form method="GET" action="{{ route('logistique.decharges.lot-pdf', $cycle) }}" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 space-y-3">
        <h3 class="font-semibold text-slate-800">Télécharger les décharges en lot (ZIP)</h3>
        <p class="text-xs text-slate-500">Les décharges validées et annulées du périmètre, un PDF chacune. Un seul critère à la fois ; sans critère, tout le cycle.</p>
        <div class="flex flex-wrap gap-3 items-center">
            @include('logistique.decharges._perimetre', ['regions' => $regions])
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#0284c7;">Télécharger le ZIP</button>
        </div>
    </form>
</div>
@endsection

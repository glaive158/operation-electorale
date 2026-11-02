@extends('layouts.app')
@section('title','Expéditions')
@section('page-title','Expéditions : dotation, engagé et reste')

@php
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $peutPreparer = $cycle->estOuvert();
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="flex flex-wrap gap-3 items-center">
            <select name="categorie" aria-label="Catégorie" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Toutes les catégories</option>
                @foreach(\App\Models\Logistique\Decharge::CATEGORIES as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($categorie === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select name="departement" aria-label="Département" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les départements</option>
                @foreach($departements as $d)
                    <option value="{{ $d['departement_id'] }}" @selected($departement === $d['departement_id'])>{{ $d['nom'] }}</option>
                @endforeach
            </select>
            <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="reste" value="1" @checked(request()->boolean('reste'))> Reste à expédier seulement
            </label>
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#0284c7;">Filtrer</button>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $lignes->total() }} ligne(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                        <th class="px-5 py-3 text-left font-semibold">Catégorie</th>
                        <th class="px-5 py-3 text-right font-semibold">Dotation</th>
                        <th class="px-5 py-3 text-right font-semibold">Engagé</th>
                        <th class="px-5 py-3 text-right font-semibold">Reste</th>
                        <th class="px-5 py-3 text-left font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($lignes as $l)
                        @php $brouillon = $brouillons[$l['destinataire_id'].'|'.$l['categorie']] ?? null; @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $l['destinataire']->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ \App\Models\Logistique\Decharge::CATEGORIES[$l['categorie']] ?? $l['categorie'] }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($l['dotation']) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($l['engage']) }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $n($l['reste']) }}</td>
                            <td class="px-5 py-3">
                                @if($brouillon)
                                    <a href="{{ route('logistique.decharges.show', $brouillon) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Voir le brouillon</a>
                                @elseif($l['reste'] > 0 && $peutPreparer)
                                    <form method="POST" action="{{ route('logistique.decharges.store', $cycle) }}">
                                        @csrf
                                        <input type="hidden" name="destinataire_id" value="{{ $l['destinataire_id'] }}">
                                        <input type="hidden" name="categorie" value="{{ $l['categorie'] }}">
                                        <button type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#009A44;">Préparer</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-slate-400">Aucune ligne : approuvez d'abord un plan de dotation.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($lignes->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $lignes->links() }}</div>
        @endif
    </div>

    @if($peutPreparer)
        <form method="POST" action="{{ route('logistique.decharges.lot', $cycle) }}" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 space-y-3"
              onsubmit="return confirm('Préparer un brouillon pour chaque destinataire du périmètre qui a du matériel à expédier ?');">
            @csrf
            <h3 class="font-semibold text-slate-800">Préparer un lot de décharges</h3>
            <p class="text-xs text-slate-500">Un brouillon par destinataire du périmètre qui a quelque chose à expédier dans la catégorie. Un seul critère de périmètre à la fois ; sans critère, tout le cycle.</p>
            <div class="flex flex-wrap gap-3 items-center">
                <select name="categorie" required aria-label="Catégorie du lot" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    <option value="">Catégorie…</option>
                    @foreach(\App\Models\Logistique\Decharge::CATEGORIES as $valeur => $libelle)
                        <option value="{{ $valeur }}">{{ $libelle }}</option>
                    @endforeach
                </select>
                @include('logistique.decharges._perimetre')
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Préparer le lot</button>
            </div>
        </form>
    @endif
</div>
@endsection

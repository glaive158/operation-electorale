@extends('layouts.app')
@section('title','Plan de dotation v'.$plan->version)
@section('page-title','Plan de dotation v'.$plan->version)

@php
    $libellesStatut = ['brouillon' => 'Brouillon', 'approuve' => 'Approuvé', 'remplace' => 'Remplacé'];
    $classesStatut = ['brouillon' => 'bg-amber-100 text-amber-700', 'approuve' => 'bg-green-100 text-green-700', 'remplace' => 'bg-slate-200 text-slate-600'];
    $brouillon = $plan->statut === 'brouillon';
    $peutCorriger = $brouillon && $cycle->estOuvert() && \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    $entier = fn ($v) => $v === null ? '—' : number_format((int) $v, 0, ',', ' ');
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-800">Version {{ $plan->version }} — instantané v{{ $plan->instantane?->version }}</h3>
                <p class="text-xs text-slate-500">
                    Calculé par {{ $plan->calculePar?->nom_complet }} le {{ $plan->created_at?->format('d/m/Y H:i') }}
                    @if($plan->approuvePar) · approuvé par {{ $plan->approuvePar->nom_complet }} le {{ $plan->approuve_le?->format('d/m/Y H:i') }} @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesStatut[$plan->statut] ?? '' }}">{{ $libellesStatut[$plan->statut] ?? $plan->statut }}</span>
                @can('logistique-administrer')
                    @if($brouillon && $nbAnomalies === 0 && $cycle->estOuvert())
                        <form method="POST" action="{{ route('logistique.plans.approuver', $plan) }}"
                              onsubmit="return confirm('Approuver ce plan ? Il remplacera le plan en vigueur.');">
                            @csrf
                            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Approuver</button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>

        @if($nbAnomalies > 0)
            <div class="mt-4 p-4 rounded-xl border border-amber-200 bg-amber-50 text-sm text-amber-800">
                <p class="font-semibold">{{ $nbAnomalies }} anomalie(s){{ $brouillon ? ' : le plan ne peut pas être approuvé.' : '.' }}</p>
                @if(! empty($plan->anomalies))
                    <ul class="list-disc ml-5 mt-1">
                        @foreach($plan->anomalies as $a) <li>{{ $a }}</li> @endforeach
                    </ul>
                @endif
                @if($nbAnomalies > count($plan->anomalies ?? []))
                    <a href="{{ route('logistique.plans.show', [$plan, 'anomalies' => 1]) }}" class="underline">Voir les lignes en anomalie</a>
                @endif
            </div>
        @endif
    </div>

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-center">
            <select name="destinataire" aria-label="Destinataire" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les destinataires</option>
                @foreach($destinataires as $d)
                    <option value="{{ $d->id }}" {{ (string) request('destinataire') === (string) $d->id ? 'selected' : '' }}>{{ $d->libelle }}</option>
                @endforeach
            </select>
            <select name="materiel" aria-label="Article" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les articles</option>
                @foreach($materiels as $m)
                    <option value="{{ $m->id }}" {{ (string) request('materiel') === (string) $m->id ? 'selected' : '' }}>{{ $m->libelle }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="anomalies" value="1" {{ request()->boolean('anomalies') ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;">
                En anomalie seulement
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="corrigees" value="1" {{ request()->boolean('corrigees') ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;">
                Corrigées seulement
            </label>
            <div class="flex gap-2">
                <button class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex-1" style="background:#009A44;">Filtrer</button>
                <a href="{{ route('logistique.plans.show', $plan) }}" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">↺</a>
            </div>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Annexe de calcul — {{ $lignes->total() }} ligne(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-4 py-3 text-left font-semibold">Destinataire</th>
                        <th class="px-4 py-3 text-left font-semibold">Article</th>
                        <th class="px-4 py-3 text-left font-semibold">Clé affichée</th>
                        <th class="px-4 py-3 text-right font-semibold">Effectif</th>
                        <th class="px-4 py-3 text-right font-semibold">Exact</th>
                        <th class="px-4 py-3 text-right font-semibold">Majoration</th>
                        <th class="px-4 py-3 text-right font-semibold">Minimum</th>
                        <th class="px-4 py-3 text-left font-semibold">Arrondi</th>
                        <th class="px-4 py-3 text-right font-semibold">Dotation calculée</th>
                        <th class="px-4 py-3 text-left font-semibold">Correction (motif)</th>
                        <th class="px-4 py-3 text-right font-semibold">Dotation approuvée</th>
                        <th class="px-4 py-3 text-left font-semibold">Anomalie</th>
                        @if($peutCorriger)<th class="px-4 py-3 text-left font-semibold">Actions</th>@endif
                    </tr>
                </thead>
                @forelse($lignes as $l)
                    <tbody class="divide-y divide-slate-50 border-t border-slate-50" x-data="{ ouvert: false }">
                        <tr class="hover:bg-slate-50 transition-colors {{ $l->anomalie ? 'bg-amber-50' : '' }}">
                            <td class="px-4 py-3 text-slate-800">{{ $l->destinataire?->libelle }}</td>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $plan->parametres[$l->materiel_id]['cle_affichee'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ $entier($l->effectif) }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ $l->exact }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ $l->majoration }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ $l->minimum }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ \App\Models\Logistique\CleRepartition::LIBELLES_ARRONDI[$l->arrondi] ?? $l->arrondi }}</td>
                            <td class="px-4 py-3 text-right text-slate-800">{{ $entier($l->dotation_calculee) }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                @if($l->correction !== null) <span class="font-medium">{{ $entier($l->correction) }}</span> — {{ $l->correction_motif }} @else — @endif
                                @if($l->calculModifieDepuisCorrection())
                                    <span class="block text-xs text-amber-700">calcul modifié depuis la correction ({{ $entier($l->correction_calcul_source) }} → {{ $entier($l->dotation_calculee) }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-slate-800">{{ $entier($l->dotation_approuvee) }}</td>
                            <td class="px-4 py-3 text-amber-700">{{ $l->anomalie ?? '' }}</td>
                            @if($peutCorriger)
                                <td class="px-4 py-3"><button type="button" @click="ouvert = !ouvert" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Corriger</button></td>
                            @endif
                        </tr>
                        @if($peutCorriger)
                            <tr x-show="ouvert" x-cloak class="bg-slate-50">
                                <td colspan="13" class="px-4 py-3">
                                    <form method="POST" action="{{ route('logistique.plan-lignes.corriger', $l) }}" class="flex flex-wrap gap-2 items-end">
                                        @csrf
                                        <div>
                                            <label class="block text-xs font-medium text-slate-600 mb-1">Quantité retenue</label>
                                            <input type="number" name="valeur" min="0" max="10000000" step="1" required value="{{ $l->correction ?? $l->dotation_calculee }}" class="w-32 px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                        </div>
                                        <div class="flex-1 min-w-[16rem]">
                                            <label class="block text-xs font-medium text-slate-600 mb-1">Motif</label>
                                            <input type="text" name="motif" required maxlength="500" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                        </div>
                                        <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer la correction</button>
                                    </form>
                                    @if($l->correction !== null)
                                        <form method="POST" action="{{ route('logistique.plan-lignes.annuler-correction', $l) }}" class="flex flex-wrap gap-2 items-end mt-3">
                                            @csrf
                                            <div class="flex-1 min-w-[16rem]">
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Motif de l'annulation</label>
                                                <input type="text" name="motif" required maxlength="500" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                            </div>
                                            <button type="submit" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-100">Annuler la correction</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody><tr><td colspan="13" class="px-6 py-10 text-center text-slate-400">Aucune ligne</td></tr></tbody>
                @endforelse
            </table>
        </div>
        @if($lignes->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $lignes->links() }}</div>
        @endif
    </div>
</div>
@endsection

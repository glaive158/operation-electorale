@extends('layouts.app')
@section('title', auth()->user()->role === 'gouverneur' ? 'Suivi de la région' : 'Suivi du périmètre')
@section('page-title', auth()->user()->role === 'gouverneur' ? 'Suivi de la région' : 'Suivi du périmètre')

@php
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $classesEtat = ['en_cours' => 'bg-sky-100 text-sky-700', 'solde_complet' => 'bg-green-100 text-green-700', 'cloture_avec_ecart' => 'bg-amber-100 text-amber-700'];
    $entetes = [
        'Dotation', 'Besoin', 'Engagé', 'Expédié', 'Reste à expédier', 'En transit', 'Chez l\'intermédiaire',
        'Reçu', 'Reçu utilisable', 'Reste à réceptionner', 'Réclamations',
    ];
    $cles = ['dotation', 'besoin_expedition', 'engage', 'expedie', 'reste_a_expedier', 'en_transit', 'chez_intermediaire', 'recu', 'recu_utilisable', 'reste_a_receptionner', 'reclamations_actives'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._erreurs')

    @if($cycle === null)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-10 text-center text-slate-400">Aucun cycle électoral.</div>
    @else
        @if($cycles->count() > 1)
            <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                <div class="flex flex-wrap gap-3 items-center">
                    <select name="cycle" aria-label="Cycle" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        @foreach($cycles as $c)
                            <option value="{{ $c->id }}" @selected($c->id === $cycle->id)>{{ $c->libelle }}@unless($c->estOuvert()) (clôturé)@endunless</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#0284c7;">Afficher</button>
                </div>
            </form>
        @endif

        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="font-semibold text-slate-800">Par article</h3>
                <p class="text-xs text-slate-500">{{ $cycle->libelle }}, votre périmètre seulement. Le reçu utilisable ne compte que les réceptions retenues, sans les articles défectueux.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                            <th class="px-5 py-3 text-left font-semibold">Article</th>
                            @foreach($entetes as $entete) <th class="px-4 py-3 text-right font-semibold">{{ $entete }}</th> @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($articles as $a)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3 font-medium text-slate-800">{{ $a['materiel']->libelle }}</td>
                                @foreach($cles as $cle) <td class="px-4 py-3 text-right text-slate-600">{{ $n($a[$cle]) }}</td> @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($entetes) + 1 }}" class="px-6 py-10 text-center text-slate-400">Aucun suivi pour votre périmètre.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="font-semibold text-slate-800">Par destinataire</h3>
                <p class="text-xs text-slate-500">{{ $lignes->total() }} ligne(s).</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                            <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                            <th class="px-5 py-3 text-left font-semibold">Article</th>
                            @foreach($entetes as $entete) <th class="px-4 py-3 text-right font-semibold">{{ $entete }}</th> @endforeach
                            <th class="px-5 py-3 text-left font-semibold">État</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($lignes as $l)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3 font-medium text-slate-800">{{ $l['destinataire']->libelle }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $l['materiel']->libelle }}</td>
                                @foreach($cles as $cle) <td class="px-4 py-3 text-right text-slate-600">{{ $n($l[$cle]) }}</td> @endforeach
                                <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesEtat[$l['etat']] ?? 'bg-slate-200 text-slate-600' }}">{{ $etats[$l['etat']] ?? $l['etat'] }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($entetes) + 3 }}" class="px-6 py-10 text-center text-slate-400">Aucun suivi pour votre périmètre.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($lignes->hasPages())
                <div class="px-6 py-4 border-t border-slate-100">{{ $lignes->links() }}</div>
            @endif
        </div>
    @endif
</div>
@endsection

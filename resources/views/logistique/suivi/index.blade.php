@extends('layouts.app')
@section('title','Suivi')
@section('page-title','Suivi du cycle : de la dotation à la réception')

@php
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $peutCloturer = $cycle->estOuvert() && \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    $classesEtat = ['en_cours' => 'bg-sky-100 text-sky-700', 'solde_complet' => 'bg-green-100 text-green-700', 'cloture_avec_ecart' => 'bg-amber-100 text-amber-700'];
    $entetes = [
        'Dotation', 'Besoin', 'Engagé', 'Expédié', 'Reste à expédier', 'En transit', 'Chez l\'intermédiaire',
        'Reçu', 'Reçu utilisable', 'Reste à réceptionner', 'Réclamations',
    ];
    $cles = ['dotation', 'besoin_expedition', 'engage', 'expedie', 'reste_a_expedier', 'en_transit', 'chez_intermediaire', 'recu', 'recu_utilisable', 'reste_a_receptionner', 'reclamations_actives'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Par article</h3>
            <p class="text-xs text-slate-500">Tout le cycle. Le besoin comprend les remplacements autorisés ; le reçu utilisable ne compte que les réceptions retenues, sans les articles défectueux.</p>
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
                        <tr class="hover:bg-slate-50 transition-colors" data-article="{{ $a['materiel']->id }}">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $a['materiel']->libelle }}</td>
                            @foreach($cles as $cle) <td class="px-4 py-3 text-right text-slate-600">{{ $n($a[$cle]) }}</td> @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($entetes) + 1 }}" class="px-6 py-10 text-center text-slate-400">Aucun suivi : approuvez d'abord un plan de dotation.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="flex flex-wrap gap-3 items-center">
            <select name="departement" aria-label="Département" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les départements</option>
                @foreach($departements as $d)
                    <option value="{{ $d['departement_id'] }}" @selected($departement === $d['departement_id'])>{{ $d['nom'] }}</option>
                @endforeach
            </select>
            <select name="etat" aria-label="État" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les états</option>
                @foreach($etats as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($etat === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#0284c7;">Filtrer</button>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Par destinataire</h3>
            <p class="text-xs text-slate-500">{{ $lignes->total() }} ligne(s). Une sous-préfecture suit le département de sa préfecture.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        @foreach($entetes as $entete) <th class="px-4 py-3 text-right font-semibold">{{ $entete }}</th> @endforeach
                        <th class="px-5 py-3 text-left font-semibold">État</th>
                        @if($peutCloturer) <th class="px-5 py-3 text-left font-semibold">Écart</th> @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($lignes as $l)
                        <tr class="hover:bg-slate-50 transition-colors" data-ligne="{{ $l['destinataire']->id }}-{{ $l['materiel']->id }}">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $l['destinataire']->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $l['materiel']->libelle }}</td>
                            @foreach($cles as $cle) <td class="px-4 py-3 text-right text-slate-600">{{ $n($l[$cle]) }}</td> @endforeach
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesEtat[$l['etat']] ?? 'bg-slate-200 text-slate-600' }}">{{ $etats[$l['etat']] ?? $l['etat'] }}</span></td>
                            @if($peutCloturer)
                                <td class="px-5 py-3">
                                    @if($l['reste_a_receptionner'] > 0 || $l['reste_a_expedier'] > 0)
                                        <details>
                                            <summary class="cursor-pointer text-xs underline text-slate-600">Clôturer avec écart</summary>
                                            <form method="POST" action="{{ route('logistique.clotures-ecart.store', $cycle) }}" class="mt-2 space-y-2 min-w-64"
                                                  onsubmit="return confirm('Accepter cet écart ? La décision est définitive.');">
                                                @csrf
                                                <input type="hidden" name="destinataire_id" value="{{ $l['destinataire']->id }}">
                                                <input type="hidden" name="materiel_id" value="{{ $l['materiel']->id }}">
                                                <label class="block text-xs text-slate-500">Abandonné à la réception (au plus {{ $n($l['reste_a_receptionner']) }})
                                                    <input type="number" name="abandon" min="0" max="{{ $l['reste_a_receptionner'] }}" step="1" value="0" class="mt-1 w-full px-2 py-1 rounded-lg border border-slate-300 text-sm text-right focus:outline-none">
                                                </label>
                                                @php $nonEngage = max(0, $l['besoin_expedition'] - $l['engage']); @endphp
                                                <label class="block text-xs text-slate-500">Expédition annulée (non engagé, au plus {{ $n($nonEngage) }})
                                                    <input type="number" name="expedition_annulee" min="0" max="{{ $nonEngage }}" step="1" value="0" class="mt-1 w-full px-2 py-1 rounded-lg border border-slate-300 text-sm text-right focus:outline-none">
                                                </label>
                                                <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif de la clôture avec écart" class="w-full px-2 py-1 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                                <button type="submit" class="px-3 py-1 rounded text-xs text-white hover:opacity-90" style="background:#d97706;">Accepter l'écart</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($entetes) + 3 + ($peutCloturer ? 1 : 0) }}" class="px-6 py-10 text-center text-slate-400">Aucun suivi pour ces critères.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($lignes->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $lignes->links() }}</div>
        @endif
    </div>
</div>
@endsection

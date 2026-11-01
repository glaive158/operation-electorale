@extends('layouts.app')
@section('title','Commande '.$commande->reference)
@section('page-title','Commande '.$commande->reference)

@php
    $enCours = in_array($commande->statut, ['confirmee', 'partiellement_recue'], true);
    $classesStatut = match($commande->statut) {
        'confirmee', 'partiellement_recue' => 'bg-sky-100 text-sky-700',
        'recue' => 'bg-green-100 text-green-700',
        'annulee' => 'bg-red-100 text-red-700',
        default => 'bg-slate-200 text-slate-600',
    };
@endphp

@section('content')
<div class="space-y-5">

    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="font-semibold text-slate-800">{{ $commande->reference }}</h3>
                <p class="text-sm text-slate-600">{{ $commande->fournisseur }} — commandée le {{ $commande->date_commande?->format('d/m/Y') }}</p>
                @if($commande->auteur)
                    <p class="text-xs text-slate-500">Saisie par {{ $commande->auteur->nom_complet }}</p>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesStatut }}">
                    {{ \App\Models\Logistique\Commande::LIBELLES_STATUT[$commande->statut] ?? $commande->statut }}
                </span>
                <a href="{{ route('logistique.commandes.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">Retour</a>
            </div>
        </div>

        <div class="flex flex-wrap gap-3 items-start">
            @if($commande->statut === 'brouillon')
                <form method="POST" action="{{ route('logistique.commandes.confirmer', $commande) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Confirmer la commande</button>
                </form>
            @endif
            @can('logistique-administrer')
                @if(in_array($commande->statut, ['brouillon', 'confirmee'], true) && $commande->receptions->isEmpty())
                    <form method="POST" action="{{ route('logistique.commandes.annuler', $commande) }}" class="flex gap-2 items-center"
                          onsubmit="return confirm('Annuler cette commande ?');">
                        @csrf
                        <input type="text" name="motif" required maxlength="500" placeholder="Motif de l'annulation" aria-label="Motif de l'annulation"
                               class="w-64 px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">Annuler la commande</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Lignes</h3>
            <p class="text-sm text-slate-600">Reste à recevoir : <span class="font-semibold">{{ $reste }}</span></p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        <th class="px-5 py-3 text-left font-semibold">Cycle visé</th>
                        <th class="px-5 py-3 text-right font-semibold">Commandée</th>
                        <th class="px-5 py-3 text-right font-semibold">Acceptée</th>
                        <th class="px-5 py-3 text-right font-semibold">Rejetée</th>
                        <th class="px-5 py-3 text-right font-semibold">Abandonnée</th>
                        <th class="px-5 py-3 text-right font-semibold">Reste à recevoir</th>
                        @can('logistique-administrer')
                            @if($enCours)
                                <th class="px-5 py-3 text-left font-semibold">Abandon du reliquat</th>
                            @endif
                        @endcan
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($commande->lignes as $l)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $l->cycle?->libelle ?? 'Stock commun' }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l->quantite_commandee }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l->quantite_acceptee }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l->quantite_rejetee }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l->quantite_abandonnee }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $l->resteARecevoir() }}</td>
                            @can('logistique-administrer')
                                @if($enCours)
                                    <td class="px-5 py-3">
                                        @if($l->resteARecevoir() > 0)
                                            <form method="POST" action="{{ route('logistique.commande-lignes.abandonner', $l) }}" class="flex flex-wrap gap-2 items-center"
                                                  onsubmit="return confirm('Abandonner ce reliquat ?');">
                                                @csrf
                                                <input type="number" name="quantite" min="1" max="{{ $l->resteARecevoir() }}" step="1" required placeholder="Qté" aria-label="Quantité abandonnée"
                                                       class="w-24 px-2 py-1 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-green-500">
                                                <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif de l'abandon"
                                                       class="w-40 px-2 py-1 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-green-500">
                                                <button type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#EE1C25;">Abandonner</button>
                                            </form>
                                        @else
                                            <span class="text-xs text-slate-400">—</span>
                                        @endif
                                    </td>
                                @endif
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($enCours)
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <h3 class="font-semibold text-slate-800 mb-1">Enregistrer une réception</h3>
        <p class="text-xs text-slate-500 mb-4">Seules les quantités acceptées entrent en stock ; le rejeté reste dû jusqu'à son abandon. Laisser vide les lignes non livrées.</p>
        <form method="POST" action="{{ route('logistique.commandes.receptions.store', $commande) }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1.5">Date de réception <span class="text-red-500">*</span></label>
                    <input type="date" name="date_reception" required value="{{ old('date_reception', now()->format('Y-m-d')) }}"
                           class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1.5">Bon de livraison <span class="text-red-500">*</span></label>
                    <input type="text" name="bon_livraison" required maxlength="100" value="{{ old('bon_livraison') }}"
                           class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                            <th class="px-5 py-3 text-left font-semibold">Article</th>
                            <th class="px-5 py-3 text-right font-semibold">Reste à recevoir</th>
                            <th class="px-5 py-3 text-left font-semibold">Acceptée</th>
                            <th class="px-5 py-3 text-left font-semibold">Rejetée</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($commande->lignes->filter(fn ($l) => $l->resteARecevoir() > 0) as $l)
                            <tr>
                                <td class="px-5 py-3 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                                <td class="px-5 py-3 text-right text-slate-600">{{ $l->resteARecevoir() }}</td>
                                <td class="px-5 py-3">
                                    <input type="number" name="lignes[{{ $l->id }}][acceptee]" min="0" max="{{ $l->resteARecevoir() }}" step="1"
                                           value="{{ old('lignes.'.$l->id.'.acceptee') }}" aria-label="Quantité acceptée"
                                           class="w-28 px-3 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                </td>
                                <td class="px-5 py-3">
                                    <input type="number" name="lignes[{{ $l->id }}][rejetee]" min="0" max="{{ $l->resteARecevoir() }}" step="1"
                                           value="{{ old('lignes.'.$l->id.'.rejetee') }}" aria-label="Quantité rejetée"
                                           class="w-28 px-3 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="px-6 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer la réception</button>
            </div>
        </form>
    </div>
    @endif

    @if($commande->receptions->isNotEmpty())
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Réceptions</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Date</th>
                        <th class="px-5 py-3 text-left font-semibold">Bon de livraison</th>
                        <th class="px-5 py-3 text-left font-semibold">Détail</th>
                        <th class="px-5 py-3 text-left font-semibold">Saisie par</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($commande->receptions as $r)
                        <tr>
                            <td class="px-5 py-3 text-slate-600">{{ $r->date_reception?->format('d/m/Y') }}</td>
                            <td class="px-5 py-3 text-slate-800">{{ $r->bon_livraison }}</td>
                            <td class="px-5 py-3 text-slate-600">
                                @foreach($r->lignes as $rl)
                                    <p>{{ $rl->ligneCommande?->materiel?->libelle }} : {{ $rl->acceptee }} acceptée(s), {{ $rl->rejetee }} rejetée(s)</p>
                                @endforeach
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $r->auteur?->nom_complet ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endsection

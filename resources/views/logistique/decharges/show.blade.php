@extends('layouts.app')
@section('title','Décharge '.($decharge->numero ?? 'brouillon'))
@section('page-title','Décharge '.($decharge->numero ?? 'brouillon'))

@php
    use App\Models\Logistique\Decharge;
    $libellesDocument = ['brouillon' => 'Brouillon', 'validee' => 'Validée', 'annulee' => 'Annulée'];
    $classesDocument = ['brouillon' => 'bg-amber-100 text-amber-700', 'validee' => 'bg-green-100 text-green-700', 'annulee' => 'bg-red-100 text-red-700'];
    $libellesCircuit = ['via_prefet' => 'Via le préfet', 'direct' => 'Direct', 'etranger' => 'Étranger'];
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');

    $brouillon = $decharge->estBrouillon();
    $annulee = $decharge->statut_document === 'annulee';
    $ouvert = $decharge->cycle?->estOuvert() ?? false;
    $peutAnnuler = $ouvert && $decharge->statut_document === 'validee' && ! $decharge->estPartie()
        && \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    // Une remplaçante elle-même annulée n'empêche pas d'en préparer une nouvelle.
    $peutRemplacer = $ouvert && $annulee && ($decharge->remplacante === null || $decharge->remplacante->statut_document === 'annulee');

    $exemplairesAttendus = array_filter([
        'initial' => $decharge->statut_document !== 'brouillon',
        'annulation' => $annulee,
    ]);
    $documents = $decharge->documents->keyBy('exemplaire');
    $libellesExemplaire = ['initial' => 'Décharge', 'annulation' => 'Exemplaire d\'annulation'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    @if($annulee)
        <div class="p-5 rounded-xl border-2 border-red-300 bg-red-50 text-red-800">
            <p class="text-xl font-bold tracking-wide">ANNULÉE</p>
            <p class="text-sm mt-1">
                Le {{ $decharge->annule_le?->format('d/m/Y H:i') }}@if($decharge->annulePar) par {{ $decharge->annulePar->nom_complet }}@endif
                — motif : {{ $decharge->motif_annulation }}
            </p>
            @if($decharge->remplacante)
                <p class="text-sm mt-2">
                    Remplacée par
                    <a href="{{ route('logistique.decharges.show', $decharge->remplacante) }}" class="underline font-medium">
                        {{ $decharge->remplacante->numero ?? 'le brouillon n° '.$decharge->remplacante->id }}
                    </a>.
                </p>
            @endif
        </div>
    @endif
    @if($decharge->remplacee)
        <div class="p-4 rounded-xl border border-amber-200 bg-amber-50 text-sm text-amber-800">
            Remplace la décharge annulée
            <a href="{{ route('logistique.decharges.show', $decharge->remplacee) }}" class="underline font-medium">{{ $decharge->remplacee->numero }}</a>.
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="font-semibold text-slate-800">{{ $decharge->numero ?? 'Brouillon sans numéro' }}</h3>
                <p class="text-sm text-slate-600">{{ $decharge->destinataire?->libelle }} — {{ Decharge::CATEGORIES[$decharge->categorie] ?? $decharge->categorie }}</p>
                <p class="text-xs text-slate-500">{{ $decharge->cycle?->libelle }}</p>
            </div>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesDocument[$decharge->statut_document] ?? 'bg-slate-200 text-slate-600' }}">
                {{ $libellesDocument[$decharge->statut_document] ?? $decharge->statut_document }}
            </span>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div><dt class="text-xs text-slate-500">Nature</dt><dd class="text-slate-800">{{ Decharge::NATURES[$decharge->nature] ?? $decharge->nature }}</dd></div>
            <div><dt class="text-xs text-slate-500">Document</dt><dd class="text-slate-800">{{ $libellesDocument[$decharge->statut_document] ?? $decharge->statut_document }}</dd></div>
            <div><dt class="text-xs text-slate-500">Acheminement</dt><dd class="text-slate-800">{{ $decharge->statut_acheminement }}</dd></div>
            <div><dt class="text-xs text-slate-500">Réclamation</dt><dd class="text-slate-800">{{ $decharge->statut_reclamation }}</dd></div>
            <div><dt class="text-xs text-slate-500">Destinataire</dt><dd class="text-slate-800">{{ $decharge->destinataire?->libelle }}</dd></div>
            @if($decharge->remisA)
                <div><dt class="text-xs text-slate-500">Remis à</dt><dd class="text-slate-800">{{ $decharge->remisA->libelle }}</dd></div>
            @endif
            @if($decharge->circuit)
                <div><dt class="text-xs text-slate-500">Circuit</dt><dd class="text-slate-800">{{ $libellesCircuit[$decharge->circuit] ?? $decharge->circuit }}</dd></div>
            @endif
            <div><dt class="text-xs text-slate-500">Plan de dotation</dt><dd class="text-slate-800">@if($decharge->plan) v{{ $decharge->plan->version }} @else — @endif</dd></div>
            @if($decharge->valide_le)
                <div><dt class="text-xs text-slate-500">Validée le</dt><dd class="text-slate-800">{{ $decharge->valide_le->format('d/m/Y H:i') }}</dd></div>
            @endif
        </dl>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Lignes</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        <th class="px-5 py-3 text-right font-semibold">Dotation approuvée</th>
                        <th class="px-5 py-3 text-right font-semibold">Déjà engagé</th>
                        <th class="px-5 py-3 text-right font-semibold">Cette expédition</th>
                        <th class="px-5 py-3 text-left font-semibold">Observations</th>
                        @if($brouillon && $ouvert) <th class="px-5 py-3"></th> @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($decharge->lignes as $l)
                        @php $engage = $brouillon ? ($etat->get($l->materiel_id)['engage'] ?? 0) : $l->deja_engage; @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">
                                {{ $l->materiel?->libelle }}
                                @if($l->cle_affichee) <span class="block text-xs font-normal text-slate-500">{{ $l->cle_affichee }}</span> @endif
                            </td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($l->dotation_approuvee) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($engage) }}</td>
                            @if($brouillon && $ouvert)
                                <td class="px-5 py-3 text-right">
                                    <form id="ligne-{{ $l->id }}" method="POST" action="{{ route('logistique.decharge-lignes.update', $l) }}">@csrf @method('PUT')</form>
                                    <input type="number" form="ligne-{{ $l->id }}" name="quantite" min="0" max="10000000" step="1" required value="{{ $l->quantite }}"
                                           aria-label="Quantité : {{ $l->materiel?->libelle }}" class="w-28 px-2 py-1 rounded-lg border border-slate-300 text-sm text-right focus:outline-none">
                                </td>
                                <td class="px-5 py-3">
                                    <input type="text" form="ligne-{{ $l->id }}" name="observations" maxlength="500" value="{{ $l->observations }}"
                                           aria-label="Observations : {{ $l->materiel?->libelle }}" class="w-full px-2 py-1 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                </td>
                                <td class="px-5 py-3">
                                    <button type="submit" form="ligne-{{ $l->id }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Enregistrer</button>
                                </td>
                            @else
                                <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $n($l->quantite) }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $l->observations }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $brouillon && $ouvert ? 6 : 5 }}" class="px-6 py-10 text-center text-slate-400">Aucune ligne</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($brouillon && $ouvert)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
            <h3 class="font-semibold text-slate-800">Brouillon</h3>
            <p class="text-xs text-slate-500">Une quantité à 0 retire la ligne à la validation. La validation contrôle le plafond du besoin et réserve le stock.</p>
            <div class="flex flex-wrap gap-2 items-center">
                <a href="{{ route('logistique.decharges.apercu', $decharge) }}" target="_blank" rel="noopener" class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-300 text-slate-700 hover:bg-slate-50">Aperçu PDF</a>
                <form method="POST" action="{{ route('logistique.decharges.actualiser', $decharge) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-300 text-slate-700 hover:bg-slate-50">Actualiser d'après le plan</button>
                </form>
                <form method="POST" action="{{ route('logistique.decharges.valider', $decharge) }}"
                      onsubmit="return confirm('Valider cette décharge ? Le stock sera réservé et le PDF émis.');">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Valider</button>
                </form>
            </div>
            <form method="POST" action="{{ route('logistique.decharges.destroy', $decharge) }}" class="flex flex-wrap gap-2 items-center"
                  onsubmit="return confirm('Supprimer ce brouillon ?');">
                @csrf @method('DELETE')
                <input type="text" name="motif" required maxlength="500" placeholder="Motif de suppression" aria-label="Motif de suppression"
                       class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">Supprimer le brouillon</button>
            </form>
        </div>
    @endif

    @if($peutAnnuler)
        <form method="POST" action="{{ route('logistique.decharges.annuler', $decharge) }}" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-3"
              onsubmit="return confirm('Annuler cette décharge ? Les réservations de stock seront libérées.');">
            @csrf
            <h3 class="font-semibold text-slate-800">Annuler la décharge</h3>
            <p class="text-xs text-slate-500">Possible tant que le matériel n'est pas parti. Les réservations sont libérées ; l'exemplaire d'annulation est émis.</p>
            <div class="flex flex-wrap gap-3 items-center">
                <input type="text" name="motif" required maxlength="500" placeholder="Motif de l'annulation" aria-label="Motif de l'annulation"
                       class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none w-full md:w-96">
                <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="vers_stock_commun" value="1"> Remettre le matériel au stock commun
                </label>
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">Annuler</button>
            </div>
        </form>
    @endif

    @if($peutRemplacer)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
            <form method="POST" action="{{ route('logistique.decharges.remplacer', $decharge) }}" class="flex flex-wrap gap-3 items-center">
                @csrf
                <p class="text-sm text-slate-600">Préparer un brouillon de remplacement d'après le plan en vigueur et le reste à expédier.</p>
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Préparer le remplacement</button>
            </form>
        </div>
    @endif

    @if($exemplairesAttendus !== [])
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-3">
            <h3 class="font-semibold text-slate-800">Documents archivés</h3>
            @foreach(array_keys($exemplairesAttendus) as $exemplaire)
                @php $document = $documents->get($exemplaire); @endphp
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="font-medium text-slate-800">{{ $libellesExemplaire[$exemplaire] }}</span>
                    @if($document)
                        <span class="text-xs text-slate-500">émis le {{ $document->emis_le?->format('d/m/Y H:i') }} — SHA-256 {{ substr($document->empreinte, 0, 12) }}…</span>
                        <a href="{{ route('logistique.decharges.pdf', [$decharge, $exemplaire]) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Télécharger</a>
                    @else
                        <span class="text-xs text-amber-700">Non émis</span>
                        <form method="POST" action="{{ route('logistique.decharges.emettre', $decharge) }}">
                            @csrf
                            <input type="hidden" name="exemplaire" value="{{ $exemplaire }}">
                            <button type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#009A44;">Émettre le PDF</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection

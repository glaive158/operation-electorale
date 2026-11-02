@extends('layouts.app')
@section('title','Décharge '.($decharge->numero ?? 'brouillon'))
@section('page-title','Décharge '.($decharge->numero ?? 'brouillon'))

@php
    use App\Models\Logistique\Decharge;
    $libellesDocument = ['brouillon' => 'Brouillon', 'validee' => 'Validée', 'annulee' => 'Annulée'];
    $classesDocument = ['brouillon' => 'bg-amber-100 text-amber-700', 'validee' => 'bg-green-100 text-green-700', 'annulee' => 'bg-red-100 text-red-700'];
    $libellesCircuit = ['via_prefet' => 'Via le préfet', 'direct' => 'Direct', 'etranger' => 'Étranger'];
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $peutAdministrer = \Illuminate\Support\Facades\Gate::allows('logistique-administrer');

    $brouillon = $decharge->estBrouillon();
    $annulee = $decharge->statut_document === 'annulee';
    $ouvert = $decharge->cycle?->estOuvert() ?? false;
    $peutCharger = $ouvert && $decharge->statut_document === 'validee' && ! $decharge->estPartie();
    $peutAnnuler = $ouvert && $decharge->statut_document === 'validee' && ! $decharge->estPartie()
        && \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    // Une remplaçante elle-même annulée n'empêche pas d'en préparer une nouvelle.
    $peutRemplacer = $ouvert && $annulee && ($decharge->remplacante === null || $decharge->remplacante->statut_document === 'annulee');

    $exemplairesAttendus = array_filter([
        'initial' => $decharge->statut_document !== 'brouillon',
        'annulation' => $annulee,
        'reception' => $decharge->documents->contains('exemplaire', 'reception')
            || app(\App\Services\Logistique\DechargePdfService::class)->receptionEmissible($decharge),
    ]);
    $documents = $decharge->documents->keyBy('exemplaire');
    $classesStatutRemise = ['en_attente' => 'bg-slate-100 text-slate-600', 'recue' => 'bg-green-100 text-green-700', 'a_verifier' => 'bg-amber-100 text-amber-700', 'annulee' => 'bg-red-100 text-red-700'];
    $libellesExemplaire = ['initial' => 'Décharge', 'annulation' => 'Exemplaire d\'annulation', 'reception' => 'Exemplaire de réception'];
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
            <div><dt class="text-xs text-slate-500">Acheminement</dt><dd class="text-slate-800">{{ Decharge::ACHEMINEMENTS[$decharge->statut_acheminement] ?? $decharge->statut_acheminement }}</dd></div>
            <div><dt class="text-xs text-slate-500">Réclamation</dt><dd class="text-slate-800">{{ Decharge::RECLAMATIONS[$decharge->statut_reclamation] ?? $decharge->statut_reclamation }}</dd></div>
            <div><dt class="text-xs text-slate-500">Destinataire</dt><dd class="text-slate-800">{{ $decharge->destinataire?->libelle }}</dd></div>
            @if($decharge->remisA)
                <div><dt class="text-xs text-slate-500">Remis à</dt><dd class="text-slate-800">{{ $decharge->remisA->libelle }}</dd></div>
            @endif
            @if($decharge->agent)
                <div><dt class="text-xs text-slate-500">Agent affecté</dt><dd class="text-slate-800">{{ $decharge->agent->nom_complet }}</dd></div>
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
                        <th class="px-5 py-3 text-right font-semibold">Besoin</th>
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
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($etat->get($l->materiel_id)['besoin'] ?? 0) }}</td>
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
                        <tr><td colspan="{{ $brouillon && $ouvert ? 7 : 6 }}" class="px-6 py-10 text-center text-slate-400">Aucune ligne</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($peutCharger)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-5">
            <h3 class="font-semibold text-slate-800">Départ du magasin</h3>

            <form method="POST" action="{{ route('logistique.decharges.agent', $decharge) }}" class="space-y-2">
                @csrf @method('PUT')
                <p class="text-xs text-slate-500">L'agent de transport affecté prend le matériel au chargement, puis déclare la réception sur sa tablette. Modifiable tant que le matériel n'est pas parti.</p>
                <div class="flex flex-wrap gap-3 items-center">
                    <select name="agent_id" aria-label="Agent de transport" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        <option value="">Aucun agent</option>
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" @selected((int) $decharge->agent_id === $agent->id)>{{ $agent->nom_complet }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="motif" required maxlength="500" placeholder="Motif de l'affectation" aria-label="Motif de l'affectation"
                           class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none w-full md:w-80">
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-300 text-slate-700 hover:bg-slate-50">Affecter l'agent</button>
                </div>
            </form>

            <form method="POST" action="{{ route('logistique.decharges.charger', $decharge) }}" class="space-y-3"
                  onsubmit="return confirm('Charger cette décharge ? Le matériel sort du stock et part en transit.');">
                @csrf
                @if($decharge->circuit === 'etranger')
                    <p class="text-xs text-slate-500">Circuit étranger : renseignez le transporteur (les quatre champs) à la place de l'agent.</p>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <input type="text" name="transporteur_nom" maxlength="150" value="{{ old('transporteur_nom') }}" placeholder="Nom du transporteur" aria-label="Nom du transporteur" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        <input type="text" name="transporteur_qualite" maxlength="150" value="{{ old('transporteur_qualite') }}" placeholder="Qualité" aria-label="Qualité du transporteur" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        <input type="text" name="transporteur_societe" maxlength="150" value="{{ old('transporteur_societe') }}" placeholder="Société" aria-label="Société du transporteur" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        <input type="text" name="transporteur_bordereau" maxlength="50" value="{{ old('transporteur_bordereau') }}" placeholder="N° de bordereau" aria-label="Numéro de bordereau" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    </div>
                @endif
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Charger</button>
            </form>
        </div>
    @endif

    @if($remises->isNotEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
            <h3 class="font-semibold text-slate-800">Remises et déclarations</h3>
            @foreach($remises as $remise)
                <div class="border border-slate-200 rounded-xl p-4 space-y-2">
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="font-medium text-slate-800">{{ \App\Models\Logistique\Remise::ETAPES[$remise->etape] ?? $remise->etape }}</span>
                        <span class="text-slate-600">{{ $parties[$remise->id]['de'] }} → {{ $parties[$remise->id]['a'] }}</span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesStatutRemise[$remise->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ \App\Models\Logistique\Remise::STATUTS[$remise->statut] ?? $remise->statut }}</span>
                        @if($remise->bordereau_numero) <span class="text-xs text-slate-500">Bordereau {{ $remise->bordereau_numero }}</span> @endif
                    </div>
                    @if($remise->transporteur_nom)
                        <p class="text-xs text-slate-500">Transporteur : {{ $remise->transporteur_nom }}@if($remise->transporteur_qualite), {{ $remise->transporteur_qualite }}@endif@if($remise->transporteur_societe) — {{ $remise->transporteur_societe }}@endif</p>
                    @endif
                    <p class="text-xs text-slate-600">
                        @foreach($remise->lignes as $l) {{ $l->materiel?->libelle }} : {{ $n($l->quantite) }}{{ $loop->last ? '' : ' ;' }} @endforeach
                    </p>
                    @foreach($remise->declarations as $declaration)
                        <div class="text-xs text-slate-600 pl-3 border-l-2 border-slate-200">
                            <span class="font-medium text-slate-800">{{ \App\Models\Logistique\Declaration::STATUTS[$declaration->statut] ?? $declaration->statut }}</span>
                            — mode : {{ \App\Models\Logistique\Declaration::MODES[$declaration->mode] ?? $declaration->mode }}, {{ $declaration->signataire_nom }}@if($declaration->signataire_qualite), {{ $declaration->signataire_qualite }}@endif,
                            le {{ $declaration->created_at?->format('d/m/Y H:i') }} :
                            @foreach($declaration->lignes as $l)
                                {{ $l->materiel?->libelle }} reçu {{ $n($l->recu) }}, défectueux {{ $n($l->defectueux) }}{{ $loop->last ? '' : ' ;' }}
                            @endforeach
                            @include('logistique.declarations._preuve', ['declaration' => $declaration, 'routePiece' => 'logistique.pieces.show', 'piecesVisibles' => null, 'peutLever' => $peutAdministrer])
                        </div>
                    @endforeach
                </div>
            @endforeach
            @if($decharge->statut_acheminement === 'a_verifier')
                <p class="text-sm text-amber-700">Une réception est à vérifier : <a href="{{ route('logistique.declarations.a-verifier', $cycle) }}" class="underline">ouvrir l'écran des vérifications</a>.</p>
            @endif
        </div>
    @endif

    @if($reserves->isNotEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-3">
            <h3 class="font-semibold text-slate-800">Réserve de la tablette de la préfecture</h3>
            <p class="text-xs text-slate-500">Part du solde du préfet réservée à l'appareil désigné pour redistribuer hors ligne : elle n'est pas mobilisable en ligne tant qu'elle n'est pas consommée ou libérée.</p>
            @foreach($reserves->groupBy('appareil_id') as $lignesAppareil)
                <p class="text-sm text-slate-600">
                    <span class="font-medium text-slate-800">{{ $lignesAppareil->first()->appareil?->nom ?? 'Tablette' }}</span> :
                    @foreach($lignesAppareil as $l) {{ $l->materiel?->libelle }} {{ $n($l->quantite) }}{{ $loop->last ? '' : ' ;' }} @endforeach
                </p>
            @endforeach
            @if($peutAdministrer)
                <form method="POST" action="{{ route('logistique.decharges.reserve.liberer', $decharge) }}" class="flex flex-wrap gap-3 items-center"
                      onsubmit="return confirm('Libérer la réserve de la tablette ? La quantité redevient mobilisable par le préfet ; la tablette ne pourra plus redistribuer hors ligne sur cette décharge.');">
                    @csrf
                    <input type="text" name="motif" required maxlength="500" placeholder="Motif de la libération" aria-label="Motif de la libération"
                           class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none w-full md:w-96">
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-300 text-slate-700 hover:bg-slate-50">Libérer la réserve</button>
                </form>
            @endif
        </div>
    @endif

    @if($decharge->reclamations->isNotEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-2">
            <h3 class="font-semibold text-slate-800">Réclamations</h3>
            @foreach($decharge->reclamations as $reclamation)
                <p class="text-sm text-slate-600">
                    <a href="{{ route('logistique.reclamations.show', $reclamation) }}" class="underline font-medium text-slate-800">{{ $reclamation->materiel?->libelle }}</a>
                    — {{ \App\Models\Logistique\Reclamation::MOTIFS[$reclamation->motif] ?? $reclamation->motif }}, {{ $n($reclamation->quantite) }}
                    ({{ \App\Models\Logistique\Reclamation::STATUTS[$reclamation->statut] ?? $reclamation->statut }})
                </p>
            @endforeach
        </div>
    @endif

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

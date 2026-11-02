@extends('layouts.app')
@section('title','Décharge '.$decharge->numero)
@section('page-title','Décharge '.$decharge->numero)

@php
    use App\Models\Logistique\Decharge;
    use App\Models\Logistique\Declaration;
    use App\Models\Logistique\Reclamation;
    use App\Models\Logistique\Remise;
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $annulee = $decharge->statut_document === 'annulee';
    $libellesDocument = ['validee' => 'Validée', 'annulee' => 'Annulée'];
    $classesDocument = ['validee' => 'bg-green-100 text-green-700', 'annulee' => 'bg-red-100 text-red-700'];
    $libellesCircuit = ['via_prefet' => 'Via le préfet', 'direct' => 'Direct', 'etranger' => 'Étranger'];
    $classesStatutRemise = ['en_attente' => 'bg-slate-100 text-slate-600', 'recue' => 'bg-green-100 text-green-700', 'a_verifier' => 'bg-amber-100 text-amber-700', 'annulee' => 'bg-red-100 text-red-700'];
    $champ = 'px-2 py-1 rounded-lg border border-slate-300 text-sm focus:outline-none';
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._erreurs')

    <p><a href="{{ route('terrain.decharges.index') }}" class="text-sm text-slate-500 underline">← {{ auth()->user()->role === 'gouverneur' ? 'Décharges de la région' : 'Mes décharges' }}</a></p>

    @if($annulee)
        <div class="p-5 rounded-xl border-2 border-red-300 bg-red-50 text-red-800">
            <p class="text-xl font-bold tracking-wide">ANNULÉE</p>
            <p class="text-sm mt-1">Cette décharge a été annulée le {{ $decharge->annule_le?->format('d/m/Y H:i') }}. Une réception déclarée sur cette version sera vérifiée par le service logistique.</p>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="font-semibold text-slate-800">{{ $decharge->numero }}</h3>
                <p class="text-sm text-slate-600">{{ $decharge->destinataire?->libelle }} — {{ Decharge::CATEGORIES[$decharge->categorie] ?? $decharge->categorie }}</p>
                <p class="text-xs text-slate-500">{{ $cycle->libelle }}@unless($cycle->estOuvert()) (cycle clôturé)@endunless</p>
            </div>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesDocument[$decharge->statut_document] ?? 'bg-slate-200 text-slate-600' }}">{{ $libellesDocument[$decharge->statut_document] ?? $decharge->statut_document }}</span>
        </div>
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div><dt class="text-xs text-slate-500">Nature</dt><dd class="text-slate-800">{{ Decharge::NATURES[$decharge->nature] ?? $decharge->nature }}</dd></div>
            <div><dt class="text-xs text-slate-500">Acheminement</dt><dd class="text-slate-800">{{ Decharge::ACHEMINEMENTS[$decharge->statut_acheminement] ?? $decharge->statut_acheminement }}</dd></div>
            <div><dt class="text-xs text-slate-500">Réclamation</dt><dd class="text-slate-800">{{ Decharge::RECLAMATIONS[$decharge->statut_reclamation] ?? $decharge->statut_reclamation }}</dd></div>
            @if($decharge->circuit)
                <div><dt class="text-xs text-slate-500">Circuit</dt><dd class="text-slate-800">{{ $libellesCircuit[$decharge->circuit] ?? $decharge->circuit }}</dd></div>
            @endif
            @if($decharge->remisA)
                <div><dt class="text-xs text-slate-500">Remis à</dt><dd class="text-slate-800">{{ $decharge->remisA->libelle }}</dd></div>
            @endif
            @if($decharge->agent)
                <div><dt class="text-xs text-slate-500">Agent affecté</dt><dd class="text-slate-800">{{ $decharge->agent->nom_complet }}</dd></div>
            @endif
        </dl>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Matériel</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        <th class="px-5 py-3 text-right font-semibold">Quantité</th>
                        <th class="px-5 py-3 text-left font-semibold">Observations</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($decharge->lignes as $l)
                        <tr>
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $n($l->quantite) }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $l->observations }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-10 text-center text-slate-400">Aucune ligne</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($solde->isNotEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="font-semibold text-slate-800">Solde du préfet</h3>
                <p class="text-xs text-slate-500">Ce que vous avez reçu en bon état, ce que vous avez déjà transféré au sous-préfet et ce qui vous reste.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                            <th class="px-5 py-3 text-left font-semibold">Article</th>
                            <th class="px-5 py-3 text-right font-semibold">Reçu utilisable</th>
                            <th class="px-5 py-3 text-right font-semibold">Transféré</th>
                            <th class="px-5 py-3 text-right font-semibold">Reliquat</th>
                            @if($peutRedistribuer) <th class="px-5 py-3 text-right font-semibold">À redistribuer</th> @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($decharge->lignes as $l)
                            @php $s = $solde->get($l->materiel_id, ['recu_utilisable' => 0, 'transfere' => 0, 'reliquat' => 0]); @endphp
                            <tr>
                                <td class="px-5 py-3 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                                <td class="px-5 py-3 text-right text-slate-600">{{ $n($s['recu_utilisable']) }}</td>
                                <td class="px-5 py-3 text-right text-slate-600">{{ $n($s['transfere']) }}</td>
                                <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $n($s['reliquat']) }}</td>
                                @if($peutRedistribuer)
                                    <td class="px-5 py-3 text-right">
                                        <input type="number" form="redistribution" name="lignes[{{ $l->materiel_id }}]" min="0" max="{{ max(0, $s['reliquat']) }}" step="1"
                                               value="{{ old('lignes.'.$l->materiel_id) }}" placeholder="0"
                                               aria-label="Quantité à redistribuer : {{ $l->materiel?->libelle }}" class="w-28 text-right {{ $champ }}">
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($peutRedistribuer)
                <form id="redistribution" method="POST" action="{{ route('terrain.redistributions.store', $decharge) }}" class="px-6 py-4 border-t border-slate-100"
                      onsubmit="return confirm('Remettre ces quantités au sous-préfet ?');">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Redistribuer au sous-préfet</button>
                </form>
            @endif
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
        <h3 class="font-semibold text-slate-800">Remises et déclarations</h3>
        @forelse($remises as $remise)
            <div class="border border-slate-200 rounded-xl p-4 space-y-2">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="font-medium text-slate-800">{{ Remise::ETAPES[$remise->etape] ?? $remise->etape }}</span>
                    <span class="text-slate-600">{{ $parties[$remise->id]['de'] }} → {{ $parties[$remise->id]['a'] }}</span>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesStatutRemise[$remise->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ Remise::STATUTS[$remise->statut] ?? $remise->statut }}</span>
                </div>
                <p class="text-xs text-slate-600">
                    @foreach($remise->lignes as $l) {{ $l->materiel?->libelle }} : {{ $n($l->quantite) }}{{ $loop->last ? '' : ' ;' }} @endforeach
                </p>
                @foreach($remise->declarations as $declaration)
                    <div class="text-xs text-slate-600 pl-3 border-l-2 border-slate-200">
                        <span class="font-medium text-slate-800">{{ Declaration::STATUTS[$declaration->statut] ?? $declaration->statut }}</span>
                        — mode : {{ Declaration::MODES[$declaration->mode] ?? $declaration->mode }}, {{ $declaration->signataire_nom }}@if($declaration->signataire_qualite), {{ $declaration->signataire_qualite }}@endif,
                        le {{ $declaration->created_at?->format('d/m/Y H:i') }} :
                        @foreach($declaration->lignes as $l)
                            {{ $l->materiel?->libelle }} reçu {{ $n($l->recu) }}, défectueux {{ $n($l->defectueux) }}{{ $l->observation ? ' ('.$l->observation.')' : '' }}{{ $loop->last ? '' : ' ;' }}
                        @endforeach
                    </div>
                @endforeach
                @if($remise->statut === 'a_verifier')
                    <p class="text-xs text-amber-700">Les déclarations diffèrent : le service logistique vérifie cette réception.</p>
                @endif

                @if(in_array($remise->id, $annulables, true))
                    <form method="POST" action="{{ route('terrain.redistributions.annuler', $remise) }}" class="flex flex-wrap gap-2 items-center pt-2"
                          onsubmit="return confirm('Annuler cette redistribution ?');">
                        @csrf
                        <input type="text" name="motif" required maxlength="500" placeholder="Motif de l'annulation" aria-label="Motif de l'annulation" class="w-full md:w-80 {{ $champ }}">
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-xs font-medium hover:opacity-90" style="background:#EE1C25;">Annuler la redistribution</button>
                    </form>
                @endif

                @if(in_array($remise->id, $declarables, true))
                    <form method="POST" action="{{ route('terrain.declarations.store', $remise) }}" class="space-y-3 border-t border-slate-100 pt-3"
                          onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                        @csrf
                        <input type="hidden" name="cle" value="{{ $cles[$remise->id] }}">
                        <p class="text-sm font-medium text-slate-800">Déclarer la réception</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-slate-500 text-xs uppercase tracking-wide">
                                        <th class="py-2 text-left font-semibold">Article</th>
                                        <th class="py-2 text-right font-semibold">Annoncé</th>
                                        <th class="py-2 text-right font-semibold">Reçu</th>
                                        <th class="py-2 text-right font-semibold">Dont défectueux</th>
                                        <th class="py-2 text-left font-semibold pl-3">Observation</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($remise->lignes as $l)
                                        <tr>
                                            <td class="py-1 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                                            <td class="py-1 text-right text-slate-600">{{ $n($l->quantite) }}</td>
                                            <td class="py-1 text-right"><input type="number" name="lignes[{{ $l->materiel_id }}][recu]" required min="0" max="10000000" step="1" value="{{ old('lignes.'.$l->materiel_id.'.recu', $l->quantite) }}" aria-label="Reçu : {{ $l->materiel?->libelle }}" class="w-28 text-right {{ $champ }}"></td>
                                            <td class="py-1 text-right"><input type="number" name="lignes[{{ $l->materiel_id }}][defectueux]" required min="0" max="10000000" step="1" value="{{ old('lignes.'.$l->materiel_id.'.defectueux', 0) }}" aria-label="Défectueux : {{ $l->materiel?->libelle }}" class="w-28 text-right {{ $champ }}"></td>
                                            <td class="py-1 pl-3"><input type="text" name="lignes[{{ $l->materiel_id }}][observation]" maxlength="500" value="{{ old('lignes.'.$l->materiel_id.'.observation') }}" aria-label="Observation : {{ $l->materiel?->libelle }}" class="w-full {{ $champ }}"></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <label class="block text-xs text-slate-500">Nom du signataire
                                <input type="text" name="signataire_nom" required maxlength="150" value="{{ old('signataire_nom', $signataireNom) }}" class="mt-1 w-full {{ $champ }}">
                            </label>
                            <label class="block text-xs text-slate-500">Qualité
                                <input type="text" name="signataire_qualite" required maxlength="150" value="{{ old('signataire_qualite', $signataireQualite) }}" class="mt-1 w-full {{ $champ }}">
                            </label>
                        </div>
                        <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Déclarer la réception</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-400">Aucune remise pour le moment : le matériel n'est pas encore parti du magasin.</p>
        @endforelse
        @if($decharge->statut_acheminement === 'a_verifier')
            <p class="text-sm text-amber-700">Une réception est à vérifier par le service logistique.</p>
        @endif
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-3">
        <h3 class="font-semibold text-slate-800">Réclamations</h3>
        @forelse($decharge->reclamations as $reclamation)
            <p class="text-sm text-slate-600">
                <span class="font-medium text-slate-800">{{ $reclamation->materiel?->libelle }}</span>
                — {{ Reclamation::MOTIFS[$reclamation->motif] ?? $reclamation->motif }}, {{ $n($reclamation->quantite) }}
                ({{ Reclamation::STATUTS[$reclamation->statut] ?? $reclamation->statut }}) : {{ $reclamation->message }}
            </p>
        @empty
            <p class="text-sm text-slate-400">Aucune réclamation.</p>
        @endforelse

        @if($peutReclamer)
            <form method="POST" action="{{ route('terrain.reclamations.store', $decharge) }}" class="space-y-3 border-t border-slate-100 pt-3">
                @csrf
                <p class="text-sm font-medium text-slate-800">Signaler un problème</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <select name="materiel_id" required aria-label="Article concerné" class="{{ $champ }}">
                        <option value="">Article concerné</option>
                        @foreach($decharge->lignes as $l)
                            <option value="{{ $l->materiel_id }}" @selected((int) old('materiel_id') === $l->materiel_id)>{{ $l->materiel?->libelle }}</option>
                        @endforeach
                    </select>
                    <select name="motif" required aria-label="Motif" class="{{ $champ }}">
                        @foreach(Reclamation::MOTIFS as $valeur => $libelle)
                            <option value="{{ $valeur }}" @selected(old('motif') === $valeur)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                    <input type="number" name="quantite" required min="1" max="10000000" step="1" value="{{ old('quantite') }}" placeholder="Quantité" aria-label="Quantité concernée" class="text-right {{ $champ }}">
                </div>
                <textarea name="message" required maxlength="1000" rows="3" placeholder="Décrivez le problème" aria-label="Message" class="w-full {{ $champ }}">{{ old('message') }}</textarea>
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#d97706;">Envoyer la réclamation</button>
            </form>
        @endif
    </div>
</div>
@endsection

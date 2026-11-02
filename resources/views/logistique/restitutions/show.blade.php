@extends('layouts.app')
@section('title','Restitution n° '.$restitution->id)
@section('page-title','Restitution n° '.$restitution->id)

@php
    use App\Models\Logistique\Declaration;
    use App\Models\Logistique\Restitution;
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $classes = ['a_preparer' => 'bg-slate-100 text-slate-600', 'en_retour' => 'bg-sky-100 text-sky-700', 'recue_central' => 'bg-amber-100 text-amber-700', 'controlee' => 'bg-green-100 text-green-700'];
    $classesDeclaration = ['retenue' => 'bg-green-100 text-green-700', 'concordante' => 'bg-green-50 text-green-700', 'a_verifier' => 'bg-red-100 text-red-700', 'valide' => 'bg-slate-100 text-slate-600', 'ecartee' => 'bg-slate-200 text-slate-500', 'remplacee' => 'bg-slate-200 text-slate-500'];
    $anomalies = ['excedent' => 'Retour supérieur à l\'attendu', 'divergence' => 'Divergence avec l\'autre déclaration', 'identite' => 'Identité du signataire non établie'];
    $champ = 'px-2 py-1 rounded-lg border border-slate-300 text-sm focus:outline-none';
    $bouton = 'px-3 py-1.5 rounded-lg text-white text-xs font-medium hover:opacity-90';
    $arbitrable = $administrer && $restitution->statut === 'a_preparer';
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <p><a href="{{ route('logistique.restitutions.index', $restitution->cycle_id) }}" class="text-sm text-slate-500 underline">← Restitutions du cycle</a></p>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-800">{{ $detenteur }}</h3>
                <p class="text-sm text-slate-600">{{ Restitution::DETENTEURS[$restitution->detenteur_type] ?? $restitution->detenteur_type }} — {{ $restitution->cycle?->libelle }}@if($restitution->cycle && ! $restitution->cycle->estOuvert()) (cycle clôturé)@endif</p>
                <p class="text-xs text-slate-500">Ouverte le {{ $restitution->created_at?->format('d/m/Y H:i') }}@if($restitution->ouvertePar) par {{ $restitution->ouvertePar->nom_complet }}@endif</p>
            </div>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classes[$restitution->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ Restitution::STATUTS[$restitution->statut] ?? $restitution->statut }}</span>
        </div>
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div><dt class="text-xs text-slate-500">Agent de retour</dt><dd class="text-slate-800">{{ $restitution->agent?->nom_complet ?? 'Non désigné' }}</dd></div>
            <div>
                <dt class="text-xs text-slate-500">Fiches de restitution</dt>
                <dd class="flex flex-wrap gap-2 pt-1">
                    @if($fiches['declaration'])
                        <a href="{{ route('logistique.restitutions.pdf', [$restitution, 'declaration']) }}" target="_blank" rel="noopener" class="underline text-sky-700">Fiche de déclaration</a>
                    @endif
                    @if($fiches['controle'])
                        <a href="{{ route('logistique.restitutions.pdf', [$restitution, 'controle']) }}" target="_blank" rel="noopener" class="underline text-sky-700">Fiche de contrôle</a>
                    @endif
                    @unless($fiches['declaration'] || $fiches['controle'])
                        <span class="text-slate-500">Disponibles une fois le retour pris en charge.</span>
                    @endunless
                </dd>
            </div>
        </dl>

        @if($agents->isNotEmpty())
            <form method="POST" action="{{ route('logistique.restitutions.agent', $restitution) }}" class="flex flex-wrap gap-2 items-center border-t border-slate-100 pt-4">
                @csrf
                <label for="agent-retour" class="text-xs text-slate-500">Agent qui rapporte le retour</label>
                <select id="agent-retour" name="agent_id" required class="{{ $champ }}">
                    <option value="">Choisir un agent</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" @selected((int) $restitution->agent_id === (int) $agent->id)>{{ $agent->nom_complet }}</option>
                    @endforeach
                </select>
                <button type="submit" class="{{ $bouton }}" style="background:#0284c7;">{{ $restitution->agent_id ? 'Changer d\'agent' : 'Désigner l\'agent' }}</button>
                <p class="w-full text-xs text-slate-500">Seul l'agent désigné recueille la déclaration sur sa tablette et prend le retour en charge.</p>
            </form>
        @endif
    </div>

    @if($aVerifier->isNotEmpty())
        <div class="p-4 rounded-xl text-sm bg-red-50 text-red-800 border border-red-200" role="alert">
            Une déclaration de restitution est à vérifier : le responsable logistique retient l'une des déclarations (ou confirme la déclaration retenue)
            avant la prise en charge du retour et son contrôle.
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Matériel attendu en retour</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        <th class="px-5 py-3 text-right font-semibold">Attendu</th>
                        <th class="px-5 py-3 text-right font-semibold">Déclaré rendu</th>
                        <th class="px-5 py-3 text-right font-semibold">Reçu au central</th>
                        <th class="px-5 py-3 text-right font-semibold">Bon</th>
                        <th class="px-5 py-3 text-right font-semibold">À réparer</th>
                        <th class="px-5 py-3 text-right font-semibold">Réformé</th>
                        <th class="px-5 py-3 text-left font-semibold">Réforme</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($lignes as $l)
                        <tr>
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($l->attendu) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($l->declare_restitue) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($l->recu_central) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($l->bon) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($l->a_reparer) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($l->reforme) }}</td>
                            <td class="px-5 py-3 text-slate-600">
                                @if($l->reforme > 0 && $l->motif_reforme !== null)
                                    <p class="text-xs">Décidée : {{ $l->motif_reforme }}</p>
                                @elseif($l->reforme > 0)
                                    @if($administrer)
                                        <form method="POST" action="{{ route('logistique.restitutions.reforme', $l) }}" class="flex flex-wrap gap-2 items-center">
                                            @csrf
                                            <input type="text" name="motif" required maxlength="500" placeholder="Motif de la réforme" aria-label="Motif de la réforme : {{ $l->materiel?->libelle }}" class="w-full md:w-56 {{ $champ }}">
                                            <button type="submit" class="{{ $bouton }}" style="background:#EE1C25;">Décider la réforme</button>
                                        </form>
                                    @else
                                        <p class="text-xs text-amber-700">Attend la décision du responsable logistique.</p>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($peutReceptionner)
            <form method="POST" action="{{ route('logistique.restitutions.receptionner', $restitution) }}" class="px-6 py-4 border-t border-slate-100 space-y-3"
                  onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                @csrf
                <p class="text-sm font-medium text-slate-800">Réception au central : ce que l'agent a rapporté</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    @foreach($lignes as $l)
                        <label class="block text-xs text-slate-500">{{ $l->materiel?->libelle }} (déclaré : {{ $n($l->declare_restitue) }})
                            <input type="number" name="recus[{{ $l->materiel_id }}]" required min="0" max="10000000" step="1" value="{{ old('recus.'.$l->materiel_id, $l->declare_restitue) }}"
                                   class="mt-1 w-full text-right {{ $champ }}">
                        </label>
                    @endforeach
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer la réception</button>
            </form>
        @endif

        @if($peutControler)
            <form method="POST" action="{{ route('logistique.restitutions.controler', $restitution) }}" class="px-6 py-4 border-t border-slate-100 space-y-3"
                  onsubmit="return confirm('Enregistrer le contrôle ? Le bon et le à réparer rentrent au magasin.');">
                @csrf
                <p class="text-sm font-medium text-slate-800">Contrôle : répartir chaque reçu en bon, à réparer et réformé</p>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-slate-500 text-xs uppercase tracking-wide">
                            <th class="py-2 text-left font-semibold">Article</th>
                            <th class="py-2 text-right font-semibold">Reçu</th>
                            <th class="py-2 text-right font-semibold">Bon</th>
                            <th class="py-2 text-right font-semibold">À réparer</th>
                            <th class="py-2 text-right font-semibold">Réformé</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lignes as $l)
                            <tr>
                                <td class="py-1 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                                <td class="py-1 text-right text-slate-600">{{ $n($l->recu_central) }}</td>
                                @foreach(['bon' => 'Bon', 'a_reparer' => 'À réparer', 'reforme' => 'Réformé'] as $etat => $libelle)
                                    <td class="py-1 text-right">
                                        <input type="number" name="controle[{{ $l->materiel_id }}][{{ $etat }}]" required min="0" max="10000000" step="1"
                                               value="{{ old('controle.'.$l->materiel_id.'.'.$etat, $etat === 'bon' ? $l->recu_central : 0) }}"
                                               aria-label="{{ $libelle }} : {{ $l->materiel?->libelle }}" class="w-24 text-right {{ $champ }}">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-xs text-slate-500">Seul le bon augmente le stock libre ; le réformé n'entre pas en stock et attend la décision motivée du responsable.</p>
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer le contrôle</button>
            </form>
        @endif
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
        <h3 class="font-semibold text-slate-800">Déclarations du détenteur</h3>
        @forelse($declarations as $declaration)
            <div class="border border-slate-200 rounded-xl p-4 space-y-2 {{ in_array($declaration->statut, ['ecartee', 'remplacee'], true) ? 'opacity-70' : '' }}">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesDeclaration[$declaration->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ Declaration::STATUTS[$declaration->statut] ?? $declaration->statut }}</span>
                    <span class="text-slate-700">Mode : {{ Declaration::MODES[$declaration->mode] ?? $declaration->mode }}</span>
                    <span class="text-slate-600">{{ $declaration->signataire_nom }}@if($declaration->signataire_qualite), {{ $declaration->signataire_qualite }}@endif</span>
                    <span class="text-xs text-slate-500">enregistrée le {{ $declaration->created_at?->format('d/m/Y H:i') }}@if($declaration->horodatage_terminal) ; terminal : {{ $declaration->horodatage_terminal->format('d/m/Y H:i') }}@endif
                        @if($declaration->user) ; envoyée par {{ $declaration->user->nom_complet }}@endif</span>
                </div>
                <p class="text-xs text-slate-600">
                    @foreach($declaration->lignes as $l)
                        {{ $l->materiel?->libelle }} rendu {{ $n($l->restitue) }}{{ $l->observation ? ' ('.$l->observation.')' : '' }}{{ $loop->last ? '' : ' ;' }}
                    @endforeach
                </p>
                @if(! empty($declaration->anomalies))
                    <p class="text-xs text-amber-700">Anomalies : {{ collect($declaration->anomalies)->map(fn ($a) => $anomalies[$a] ?? $a)->join(', ') }}</p>
                @endif
                @include('logistique.declarations._preuve', ['declaration' => $declaration, 'routePiece' => 'logistique.pieces.show', 'piecesVisibles' => null, 'peutLever' => false])

                @if($arbitrable && in_array($declaration->statut, ['a_verifier', 'valide'], true))
                    <form method="POST" action="{{ route('logistique.restitutions.retenir', $declaration) }}" class="flex flex-wrap gap-2 items-center pt-1"
                          onsubmit="return confirm('Retenir cette déclaration ? Les autres seront écartées.');">
                        @csrf
                        <input type="text" name="motif" required maxlength="500" placeholder="Motif de l'arbitrage" aria-label="Motif de l'arbitrage" class="w-full md:w-72 {{ $champ }}">
                        <button type="submit" class="{{ $bouton }}" style="background:#009A44;">Retenir cette déclaration</button>
                    </form>
                @elseif($arbitrable && $declaration->statut === 'retenue' && $aVerifier->isNotEmpty())
                    <form method="POST" action="{{ route('logistique.restitutions.retenir', $declaration) }}" class="flex flex-wrap gap-2 items-center pt-1"
                          onsubmit="return confirm('Confirmer la déclaration retenue ? Les déclarations à vérifier seront écartées.');">
                        @csrf
                        <input type="text" name="motif" required maxlength="500" placeholder="Motif de l'arbitrage" aria-label="Motif de la confirmation" class="w-full md:w-72 {{ $champ }}">
                        <button type="submit" class="{{ $bouton }}" style="background:#0284c7;">Confirmer la déclaration retenue</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-400">Aucune déclaration : le détenteur déclare depuis son compte, ou sur la tablette de l'agent de retour avec son code personnel.</p>
        @endforelse
    </div>
</div>
@endsection

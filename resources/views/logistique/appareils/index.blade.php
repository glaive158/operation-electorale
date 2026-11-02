@extends('layouts.app')
@section('title','Appareils désignés')
@section('page-title','Appareils désignés')

@php
    use App\Models\Logistique\Remise;
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $peutAdministrer = \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    $champ = 'px-2 py-1 rounded-lg border border-slate-300 text-sm focus:outline-none';
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="font-semibold text-slate-800">Un appareil désigné par préfecture</h3>
        <p class="text-xs text-slate-500">
            Seul l'appareil désigné prépare les redistributions du préfet sans connexion. Avant de partir hors ligne, il réserve une part du solde du préfet ;
            cette part n'est plus disponible en ligne. Toute redistribution synchronisée au-delà de la réserve, ou envoyée par un autre appareil, est conservée
            « à arbitrer » ci-dessous, sans aucun transfert compté.
        </p>
        @unless($peutAdministrer)
            <p class="text-xs text-amber-700 mt-1">Réservé aux responsables logistiques : la désignation et l'arbitrage ne sont pas ouverts à votre profil.</p>
        @endunless
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Préfectures</h3>
            <p class="text-xs text-slate-500">L'identifiant de l'appareil est celui qu'il affiche à la première connexion (un UUID). La tablette doit d'abord s'être connectée avec un compte de la préfecture : sa connexion est alors épinglée à la désignation, et seule elle est reconnue comme l'appareil désigné. Désigner un nouvel appareil révoque le précédent et libère sa réserve ; redésigner l'appareil actif après une reconnexion épingle son nouveau jeton.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 border-b border-slate-100">
                        <th class="px-4 py-2 font-medium">Préfecture</th>
                        <th class="px-4 py-2 font-medium">Appareil désigné</th>
                        @if($peutAdministrer) <th class="px-4 py-2 font-medium">Désigner un appareil</th> @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($prefectures as $prefecture)
                        @php
                            $historique = $appareils->get($prefecture->id, collect());
                            $actif = $historique->firstWhere('actif', true);
                        @endphp
                        <tr class="border-b border-slate-50 align-top">
                            <td class="px-4 py-2 text-slate-800">{{ $prefecture->libelle }}</td>
                            <td class="px-4 py-2">
                                @if($actif)
                                    <p class="text-slate-800">{{ $actif->nom }}</p>
                                    <p class="text-xs text-slate-500 break-all">{{ $actif->identifiant }}</p>
                                    @if($actif->estReconnaissable())
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Tablette reconnue</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">Tablette non reconnue</span>
                                        <p class="text-xs text-amber-700">Le jeton de la tablette est supprimé ou expiré : reconnectez-la avec un compte de la préfecture, puis désignez-la de nouveau.</p>
                                    @endif
                                    @if($peutAdministrer)
                                        <form method="POST" action="{{ route('logistique.appareils.revoquer', $actif) }}" class="flex flex-wrap gap-2 items-center mt-1"
                                              onsubmit="return confirm('Révoquer cet appareil ? Sa réserve sera libérée.');">
                                            @csrf
                                            <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif de la révocation pour {{ $prefecture->libelle }}" class="{{ $champ }}">
                                            <button type="submit" class="px-3 py-1 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">Révoquer</button>
                                        </form>
                                    @endif
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-200 text-slate-600">Aucun appareil désigné</span>
                                @endif
                                @php $revoques = $historique->where('actif', false); @endphp
                                @if($revoques->isNotEmpty())
                                    <p class="text-xs text-slate-400 mt-1">Révoqué(s) : {{ $revoques->map(fn ($a) => $a->nom.' ('.$a->revoque_le?->format('d/m/Y H:i').')')->implode(' ; ') }}</p>
                                @endif
                            </td>
                            @if($peutAdministrer)
                                <td class="px-4 py-2">
                                    <form method="POST" action="{{ route('logistique.appareils.designer', $prefecture) }}" class="flex flex-wrap gap-2 items-center">
                                        @csrf
                                        <input type="text" name="identifiant" required maxlength="36" placeholder="Identifiant (UUID)" aria-label="Identifiant de l'appareil pour {{ $prefecture->libelle }}" class="{{ $champ }} w-72">
                                        <input type="text" name="nom" required maxlength="100" placeholder="Nom (ex. tablette du préfet)" aria-label="Nom de l'appareil pour {{ $prefecture->libelle }}" class="{{ $champ }}">
                                        <button type="submit" class="px-3 py-1 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Désigner</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-8 text-center text-slate-400">Aucune préfecture.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $remises->count() }} redistribution(s) à arbitrer</h3>
            <p class="text-xs text-slate-500">Conservées telles que l'appareil les a faites. Valider les compte comme transférées (dans la limite de ce qui est mobilisable à cet instant) et ouvre la déclaration du sous-préfet ; rejeter les annule.</p>
        </div>
        @forelse($remises as $remise)
            <div class="px-6 py-4 border-b border-slate-50 space-y-2">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-medium text-slate-800">
                            <a href="{{ route('logistique.decharges.show', $remise->decharge) }}" class="underline">{{ $remise->decharge->numero }}</a>
                            — {{ $remise->decharge->destinataire?->libelle }}
                        </p>
                        <p class="text-xs text-slate-500">
                            {{ Remise::ETAPES[$remise->etape] ?? $remise->etape }} : {{ $parties[$remise->id]['de'] }} → {{ $parties[$remise->id]['a'] }}
                            ; envoyée par {{ $remise->preparePar?->nom_complet }}
                            @if($remise->appareil_declare) depuis l'appareil {{ $remise->appareil?->nom ?? 'inconnu' }} ({{ $remise->appareil_declare }}) @endif
                            @if($remise->horodatage_terminal) ; terminal : {{ $remise->horodatage_terminal->format('d/m/Y H:i') }} @endif
                        </p>
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">{{ Remise::STATUTS[$remise->statut] }}</span>
                </div>
                <p class="text-sm text-slate-600">
                    <span class="font-medium text-slate-800">Quantités :</span>
                    @foreach($remise->lignes as $l) {{ $l->materiel?->libelle }} {{ $n($l->quantite) }}{{ $loop->last ? '' : ' ;' }} @endforeach
                </p>
                @if($remise->raison_arbitrage)
                    <p class="text-xs text-amber-700">{{ $remise->raison_arbitrage }}</p>
                @endif
                @if($peutAdministrer)
                    <form method="POST" action="{{ route('logistique.remises.arbitrage', $remise) }}" class="flex flex-wrap gap-2 items-center">
                        @csrf
                        <input type="text" name="motif" required maxlength="500" placeholder="Motif de la décision" aria-label="Motif de l'arbitrage de la remise {{ $remise->id }}" class="{{ $champ }} w-72">
                        <button type="submit" name="decision" value="valider" class="px-3 py-1 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Valider</button>
                        <button type="submit" name="decision" value="rejeter" class="px-3 py-1 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">Rejeter</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="px-6 py-8 text-center text-slate-400">Aucune redistribution à arbitrer.</p>
        @endforelse
    </div>
</div>
@endsection

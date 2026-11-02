{{--
    Preuve d'une déclaration de réception : qui a signé, avec quelle vérification d'identité, la position, la contestation,
    la signature et la photo. Partagé par les écrans centraux et terrain.

    Variables : $declaration ; $routePiece (route qui sert une pièce) ; $piecesVisibles (empreintes que l'appelant a le droit
    de voir, ou null pour toutes) ; $peutLever (afficher la levée de la contestation).
--}}
@php
    use App\Models\Logistique\Declaration;
    use App\Models\Logistique\DeclarationPiece;

    $etat = $declaration->verification_identite ?? 'non_verifiee';
    // Seule une identité établie (code, appareil, compte) est présentée comme confirmée.
    $classeEtat = $declaration->identiteEtablie() ? 'bg-green-100 text-green-700' : ($etat === 'echec' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700');
    $signataire = $declaration->signataireUser;
    $delegant = $declaration->delegation?->autorite;
    $piecesVisibles ??= null;
    $peutLever ??= false;
    $contestation = $declaration->contestation ?? 'aucune';
    $classesContestation = ['contestee' => 'bg-red-100 text-red-700', 'levee' => 'bg-slate-200 text-slate-600'];
@endphp
<div class="mt-1 space-y-1 text-xs text-slate-600">
    <p>
        @if($signataire)
            Signataire : <span class="font-medium text-slate-800">{{ $signataire->nom_complet }}</span>@if($delegant), par délégation de {{ $delegant->nom_complet }}@endif
        @else
            Signataire non identifié@if($declaration->signataire_nom) (nom saisi : {{ $declaration->signataire_nom }})@endif
        @endif
        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classeEtat }}">{{ $declaration->libelleVerification() }}</span>
        @if(isset($classesContestation[$contestation]))
            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesContestation[$contestation] }}">{{ Declaration::CONTESTATIONS[$contestation] }}</span>
        @endif
    </p>

    @if($declaration->gps_lat !== null && $declaration->gps_lng !== null)
        <p>Position : {{ number_format((float) $declaration->gps_lat, 6, '.', '') }}, {{ number_format((float) $declaration->gps_lng, 6, '.', '') }}@if($declaration->gps_precision !== null) (précision : {{ $declaration->gps_precision }} m)@endif</p>
    @endif

    @if($declaration->pieces->isNotEmpty())
        <div class="flex flex-wrap items-end gap-3">
            @foreach($declaration->pieces as $piece)
                @php $libellePiece = DeclarationPiece::ROLES[$piece->role] ?? $piece->role; @endphp
                @if($piece->piece_jointe_id === null)
                    <span class="text-amber-700">{{ $libellePiece }} attendue</span>
                @elseif($piecesVisibles === null || in_array($piece->empreinte, $piecesVisibles, true))
                    <a href="{{ route($routePiece, $piece->empreinte) }}" target="_blank" rel="noopener" class="block text-center underline">
                        <img src="{{ route($routePiece, $piece->empreinte) }}" alt="{{ $libellePiece }}" loading="lazy" class="h-16 max-w-32 object-contain rounded border border-slate-200 bg-white">
                        <span>{{ $libellePiece }}</span>
                    </a>
                @endif
            @endforeach
        </div>
    @endif

    @if($peutLever && $contestation === 'contestee')
        <form method="POST" action="{{ route('logistique.declarations.contestation.levee', $declaration) }}" class="flex flex-wrap gap-2 items-center pt-1"
              onsubmit="return confirm('Lever cette contestation ? Vérifiez d\'abord auprès du signataire.');">
            @csrf
            <input type="text" name="motif" required maxlength="500" placeholder="Motif de la levée" aria-label="Motif de la levée de la contestation"
                   class="px-3 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none w-full md:w-64">
            <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#0284c7;">Lever la contestation</button>
        </form>
    @endif
</div>

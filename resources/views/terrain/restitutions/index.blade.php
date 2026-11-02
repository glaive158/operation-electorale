@extends('layouts.app')
@section('title','Restitution')
@section('page-title','Restitution du matériel')

@php
    use App\Models\Logistique\Declaration;
    use App\Models\Logistique\Restitution;
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $classes = ['a_preparer' => 'bg-slate-100 text-slate-600', 'en_retour' => 'bg-sky-100 text-sky-700', 'recue_central' => 'bg-amber-100 text-amber-700', 'controlee' => 'bg-green-100 text-green-700'];
    $classesDeclaration = ['retenue' => 'bg-green-100 text-green-700', 'concordante' => 'bg-green-50 text-green-700', 'a_verifier' => 'bg-red-100 text-red-700', 'valide' => 'bg-slate-100 text-slate-600', 'ecartee' => 'bg-slate-200 text-slate-500', 'remplacee' => 'bg-slate-200 text-slate-500'];
    $champ = 'px-2 py-1 rounded-lg border border-slate-300 text-sm focus:outline-none';
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <p class="text-sm text-slate-600">Le matériel réutilisable que vous détenez encore revient au central après le cycle. Déclarez ici ce que vous rendez,
            ou bien sur la tablette de l'agent de retour avec votre code personnel. L'agent prend ensuite le retour en charge.</p>
    </div>

    @forelse($restitutions as $restitution)
        @php
            $nom = $restitution->detenteur_type === 'agent'
                ? (auth()->user()->nom_complet.' (agent)')
                : (string) ($destinataires[$restitution->detenteur_id] ?? 'Destinataire');
            $declarees = $restitution->declarations->filter(fn ($d) => ! in_array($d->statut, ['ecartee', 'remplacee'], true));
        @endphp
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-slate-800">{{ $nom }}</h3>
                    <p class="text-xs text-slate-500">{{ $restitution->cycle?->libelle }} — restitution n° {{ $restitution->id }}@if($restitution->agent) ; agent de retour : {{ $restitution->agent->nom_complet }}@endif</p>
                </div>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classes[$restitution->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ Restitution::STATUTS[$restitution->statut] ?? $restitution->statut }}</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                            <th class="px-4 py-2 text-left font-semibold">Article</th>
                            <th class="px-4 py-2 text-right font-semibold">À rendre</th>
                            <th class="px-4 py-2 text-right font-semibold">Déclaré rendu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($restitution->lignes as $l)
                            <tr>
                                <td class="px-4 py-2 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                                <td class="px-4 py-2 text-right text-slate-600">{{ $n($l->attendu) }}</td>
                                <td class="px-4 py-2 text-right text-slate-600">{{ $n($l->declare_restitue) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @foreach($declarees as $declaration)
                <div class="border border-slate-200 rounded-xl p-3 space-y-1">
                    <p class="text-sm">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesDeclaration[$declaration->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ Declaration::STATUTS[$declaration->statut] ?? $declaration->statut }}</span>
                        <span class="text-slate-600">— mode : {{ Declaration::MODES[$declaration->mode] ?? $declaration->mode }}, le {{ $declaration->created_at?->format('d/m/Y H:i') }}</span>
                    </p>
                    @include('logistique.declarations._preuve', ['declaration' => $declaration, 'routePiece' => 'terrain.pieces.show', 'piecesVisibles' => $piecesVisibles, 'peutLever' => false])
                </div>
            @endforeach
            @if($declarees->contains('statut', 'a_verifier'))
                <p class="text-sm text-amber-700">Une déclaration de restitution est à vérifier par le service logistique.</p>
            @endif

            @if(in_array($restitution->id, $declarables, true))
                <form method="POST" action="{{ route('terrain.restitutions.declarer', $restitution) }}" class="space-y-3 border-t border-slate-100 pt-3"
                      onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                    @csrf
                    <input type="hidden" name="cle" value="{{ $cles[$restitution->id] }}">
                    <p class="text-sm font-medium text-slate-800">Déclarer ce que je rends</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-slate-500 text-xs uppercase tracking-wide">
                                    <th class="py-2 text-left font-semibold">Article</th>
                                    <th class="py-2 text-right font-semibold">À rendre</th>
                                    <th class="py-2 text-right font-semibold">Rendu</th>
                                    <th class="py-2 text-left font-semibold pl-3">Observation</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($restitution->lignes as $l)
                                    <tr>
                                        <td class="py-1 font-medium text-slate-800">{{ $l->materiel?->libelle }}</td>
                                        <td class="py-1 text-right text-slate-600">{{ $n($l->attendu) }}</td>
                                        <td class="py-1 text-right"><input type="number" name="lignes[{{ $l->materiel_id }}][restitue]" required min="0" max="10000000" step="1" value="{{ old('lignes.'.$l->materiel_id.'.restitue', $l->attendu) }}" aria-label="Rendu : {{ $l->materiel?->libelle }}" class="w-28 text-right {{ $champ }}"></td>
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
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Déclarer la restitution</button>
                </form>
            @endif
        </div>
    @empty
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-10 text-center text-slate-400">Aucune restitution à faire pour le moment.</div>
    @endforelse

    @if($restitutions->hasPages())
        <div>{{ $restitutions->links() }}</div>
    @endif
</div>
@endsection

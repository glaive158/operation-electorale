@extends('layouts.app')
@section('title','Réceptions à vérifier')
@section('page-title','Réceptions à vérifier')

@php
    use App\Models\Logistique\Declaration;
    use App\Models\Logistique\Remise;
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $peutArbitrer = \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    $classesStatut = ['valide' => 'bg-sky-100 text-sky-700', 'retenue' => 'bg-green-100 text-green-700', 'concordante' => 'bg-green-100 text-green-700', 'a_verifier' => 'bg-amber-100 text-amber-700'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="font-semibold text-slate-800">{{ $remises->total() }} réception(s) à vérifier</h3>
        <p class="text-xs text-slate-500">Les déclarations d'une même remise ne concordent pas. Aucune n'est modifiée : le responsable en retient une, ou rejette celle qui est erronée.</p>
        @unless($peutArbitrer)
            <p class="text-xs text-amber-700 mt-1">Réservé aux responsables logistiques : l'arbitrage n'est pas ouvert à votre profil.</p>
        @endunless
    </div>

    @forelse($remises as $remise)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-slate-800">
                        <a href="{{ route('logistique.decharges.show', $remise->decharge) }}" class="underline">{{ $remise->decharge->numero }}</a>
                        — {{ $remise->decharge->destinataire?->libelle }}
                    </h3>
                    <p class="text-xs text-slate-500">{{ Remise::ETAPES[$remise->etape] ?? $remise->etape }} : {{ $parties[$remise->id]['de'] }} → {{ $parties[$remise->id]['a'] }}</p>
                </div>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">{{ Remise::STATUTS[$remise->statut] }}</span>
            </div>

            <p class="text-sm text-slate-600">
                <span class="font-medium text-slate-800">Annoncé :</span>
                @foreach($remise->lignes as $l) {{ $l->materiel?->libelle }} {{ $n($l->quantite) }}{{ $loop->last ? '' : ' ;' }} @endforeach
            </p>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                @foreach($remise->declarations as $declaration)
                    @php $arbitrable = $peutArbitrer && in_array($declaration->statut, ['a_verifier', 'valide'], true); @endphp
                    <div class="border border-slate-200 rounded-xl p-4 space-y-3">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-medium text-slate-800">Déclaration {{ $declaration->mode }}</span>
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesStatut[$declaration->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ Declaration::STATUTS[$declaration->statut] ?? $declaration->statut }}</span>
                        </div>
                        <p class="text-xs text-slate-500">
                            {{ $declaration->signataire_nom }}@if($declaration->signataire_qualite), {{ $declaration->signataire_qualite }}@endif
                            — enregistrée le {{ $declaration->created_at?->format('d/m/Y H:i') }}
                            @if($declaration->horodatage_terminal) ; terminal : {{ $declaration->horodatage_terminal->format('d/m/Y H:i') }} @endif
                        </p>
                        @if($declaration->anomalies)
                            <ul class="text-xs text-amber-700 list-disc pl-4">
                                @foreach($declaration->anomalies as $anomalie) <li>{{ Declaration::ANOMALIES[$anomalie] ?? $anomalie }}</li> @endforeach
                            </ul>
                        @endif
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-slate-500 text-xs uppercase tracking-wide">
                                    <th class="py-1 text-left font-semibold">Article</th>
                                    <th class="py-1 text-right font-semibold">Reçu</th>
                                    <th class="py-1 text-right font-semibold">Défectueux</th>
                                    <th class="py-1 text-left font-semibold pl-3">Observation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                @foreach($declaration->lignes as $l)
                                    <tr>
                                        <td class="py-1 text-slate-800">{{ $l->materiel?->libelle }}</td>
                                        <td class="py-1 text-right font-semibold text-slate-800">{{ $n($l->recu) }}</td>
                                        <td class="py-1 text-right text-slate-600">{{ $n($l->defectueux) }}</td>
                                        <td class="py-1 text-slate-600 pl-3">{{ $l->observation }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if($arbitrable)
                            <div class="space-y-2">
                                <form method="POST" action="{{ route('logistique.declarations.retenir', $declaration) }}" class="flex flex-wrap gap-2 items-center"
                                      onsubmit="return confirm('Retenir cette déclaration ? Les autres déclarations de la remise seront écartées.');">
                                    @csrf
                                    <input type="text" name="motif" required maxlength="500" placeholder="Motif de la retenue" aria-label="Motif de la retenue"
                                           class="px-3 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none w-full md:w-64">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Retenir</button>
                                </form>
                                <form method="POST" action="{{ route('logistique.declarations.rejeter', $declaration) }}" class="flex flex-wrap gap-2 items-center"
                                      onsubmit="return confirm('Rejeter cette déclaration ?');">
                                    @csrf
                                    <input type="text" name="motif" required maxlength="500" placeholder="Motif du rejet" aria-label="Motif du rejet"
                                           class="px-3 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none w-full md:w-64">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">Rejeter</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 px-6 py-10 text-center text-slate-400">Aucune réception à vérifier.</div>
    @endforelse

    @if($remises->hasPages())
        <div>{{ $remises->links() }}</div>
    @endif
</div>
@endsection

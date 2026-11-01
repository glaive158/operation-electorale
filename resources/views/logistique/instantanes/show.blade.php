@extends('layouts.app')
@section('title','Instantané v'.$instantane->version)
@section('page-title','Instantané v'.$instantane->version)

@php
    $b = $instantane->bilan ?? [];
    $libellesStatut = ['en_cours' => 'Capture en cours', 'brouillon' => 'Brouillon', 'fige' => 'Figé', 'echec' => 'Échec'];
    $classesStatut = ['en_cours' => 'bg-sky-100 text-sky-700', 'brouillon' => 'bg-amber-100 text-amber-700', 'fige' => 'bg-green-100 text-green-700', 'echec' => 'bg-red-100 text-red-700'];
    $nombre = fn ($cle) => isset($b[$cle]) ? number_format((int) $b[$cle], 0, ',', ' ') : '—';
    $controles = $b['controles'] ?? [];
    $libellesControle = [
        'codes_longueur_invalide' => 'Codes bureau de longueur invalide',
        'codes_multi_communes' => 'Codes bureau rattachés à plusieurs communes',
        'prefixes_incoherents' => 'Lieux de vote incohérents pour un même préfixe',
    ];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="font-semibold text-slate-800">Version {{ $instantane->version }} — source : {{ $instantane->source }}</h3>
                <p class="text-xs text-slate-500">
                    Capturé par {{ $instantane->auteur?->nom_complet }} le {{ $instantane->created_at?->format('d/m/Y H:i') }}
                    @if($instantane->fige_le) · figé le {{ $instantane->fige_le->format('d/m/Y H:i') }} @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesStatut[$instantane->statut] ?? '' }}">{{ $libellesStatut[$instantane->statut] ?? $instantane->statut }}</span>
                @if($instantane->statut === 'brouillon' && $cycle->estOuvert())
                    <form method="POST" action="{{ route('logistique.instantanes.figer', $instantane) }}"
                          onsubmit="return confirm('Figer cet instantané ? Il ne pourra plus être modifié.');">
                        @csrf
                        <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Figer</button>
                    </form>
                @endif
            </div>
        </div>

        @if($instantane->statut === 'echec' && $instantane->erreur)
            <div class="p-4 rounded-xl text-sm text-white mb-4" style="background:#EE1C25;">Erreur de capture : {{ $instantane->erreur }}</div>
        @endif
        @if($instantane->statut === 'en_cours')
            <p class="text-sm text-slate-500">La capture est en cours. <a href="{{ route('logistique.instantanes.show', $instantane) }}" class="underline">Actualiser</a></p>
        @endif

        @if($b !== [])
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach([
                    'electeurs_total' => 'Électeurs (total)',
                    'electeurs_rapproches' => 'Électeurs rapprochés',
                    'electeurs_non_rapproches' => 'Électeurs non rapprochés',
                    'electeurs_etranger' => 'Électeurs à l\'étranger',
                    'electeurs_fichier' => 'Lignes du fichier électoral',
                    'electeurs_ignores' => 'Lignes ignorées (géographie inexploitable)',
                ] as $cle => $libelle)
                    <div class="rounded-xl border border-slate-100 p-4">
                        <p class="text-xs text-slate-500">{{ $libelle }}</p>
                        <p class="text-xl font-semibold text-slate-800">{{ $nombre($cle) }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if($b !== [])
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h3 class="font-semibold text-slate-800 mb-3">Contrôles du fichier</h3>
                <ul class="space-y-2 text-sm">
                    @foreach($libellesControle as $cle => $libelle)
                        @php $n = (int) ($controles[$cle] ?? 0); @endphp
                        <li class="flex justify-between gap-3">
                            <span class="text-slate-600">{{ $libelle }}</span>
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $n === 0 ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">{{ $n === 0 ? 'Conforme' : $n.' anomalie(s)' }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h3 class="font-semibold text-slate-800 mb-3">Électeurs par région</h3>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-slate-50">
                        @forelse($b['totaux_par_region'] ?? [] as $region => $n)
                            <tr><td class="py-1.5 text-slate-600">{{ $region }}</td><td class="py-1.5 text-right text-slate-800">{{ number_format((int) $n, 0, ',', ' ') }}</td></tr>
                        @empty
                            <tr><td class="py-3 text-slate-400">Aucune donnée</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if(! empty($b['localites_sans_destinataire']))
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h3 class="font-semibold text-slate-800 mb-3">Localités sans destinataire ({{ count($b['localites_sans_destinataire']) }})</h3>
                <ul class="text-sm text-slate-600 columns-1 md:columns-3">
                    @foreach($b['localites_sans_destinataire'] as $l) <li>{{ $l }}</li> @endforeach
                </ul>
            </div>
        @endif
        @if(! empty($b['lignes_non_rapprochees']))
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h3 class="font-semibold text-slate-800 mb-3">Communes du fichier non rapprochées ({{ count($b['lignes_non_rapprochees']) }})</h3>
                <ul class="text-sm text-slate-600 columns-1 md:columns-2">
                    @foreach($b['lignes_non_rapprochees'] as $l) <li>{{ $l }}</li> @endforeach
                </ul>
                <a href="{{ route('logistique.correspondances.index') }}" class="inline-block mt-3 text-sm underline text-slate-600">Traiter les correspondances</a>
            </div>
        @endif
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $lignes->total() }} ligne(s)</h3>
            <a href="{{ route('logistique.instantanes.show', [$instantane] + (request()->boolean('non_rapprochees') ? [] : ['non_rapprochees' => 1])) }}" class="text-sm underline text-slate-600">
                {{ request()->boolean('non_rapprochees') ? 'Toutes les lignes' : 'Lignes non rapprochées seulement' }}
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Localité</th>
                        <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                        <th class="px-5 py-3 text-right font-semibold">Électeurs</th>
                        <th class="px-5 py-3 text-right font-semibold">Bureaux</th>
                        <th class="px-5 py-3 text-right font-semibold">Lieux</th>
                        <th class="px-5 py-3 text-right font-semibold">Commissions</th>
                        <th class="px-5 py-3 text-left font-semibold">Rapprochée</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($lignes as $l)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-slate-800">
                                @if($l->pays !== null) {{ $l->pays }} | {{ $l->ville }}
                                @else {{ $l->libelle_commune }} <span class="text-xs text-slate-400">{{ $l->contexte_departement }}</span> @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $l->destinataire?->libelle ?? '—' }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ number_format($l->electeurs, 0, ',', ' ') }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l->bureaux_vote }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l->lieux_vote }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l->commissions }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $l->rapprochee ? 'Oui' : 'Non' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-10 text-center text-slate-400">Aucune ligne</td></tr>
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

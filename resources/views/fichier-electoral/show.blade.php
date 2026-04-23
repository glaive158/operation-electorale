@extends('layouts.app')
@section('title','Fiche Électeur')
@section('page-title','Fichier Électoral')
@section('breadcrumb')
    <span class="text-xs text-slate-500">Fiche électeur</span>
@endsection

@section('content')
<div class="max-w-4xl space-y-5">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

        {{-- Identité --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#f0fdf4;">
                <svg class="w-4 h-4" style="color:#009A44;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <span class="font-semibold text-sm" style="color:#009A44;">Identité</span>
            </div>
            <div class="p-5">
                <dl class="space-y-3">
                    @foreach([
                        ['NIN', $electeur->numcni, true],
                        ['N° Électeur', $electeur->numelec, true],
                        ['Prénom', $electeur->prenom, false],
                        ['Nom', $electeur->nom, false],
                        ['Date de naissance', $electeur->datenaiss, false],
                        ['Lieu de naissance', $electeur->lieunaiss, false],
                        ['Prénom du père', $electeur->prenom_pere, false],
                        ['Prénom de la mère', $electeur->prenom_mere, false],
                        ['Nom de la mère', $electeur->nom_mere, false],
                    ] as [$label, $val, $mono])
                    <div class="flex justify-between items-start gap-4 py-2 border-b border-slate-50 last:border-0">
                        <dt class="text-xs text-slate-500 flex-shrink-0 w-36">{{ $label }}</dt>
                        <dd class="{{ $mono ? 'font-mono text-xs' : 'text-sm' }} font-medium text-slate-800 text-right">
                            {{ $val ?? '—' }}
                        </dd>
                    </div>
                    @endforeach
                </dl>
            </div>
        </div>

        {{-- Situation électorale --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2" style="background:#eff6ff;">
                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="font-semibold text-sm text-blue-600">Situation Électorale</span>
            </div>
            <div class="p-5">
                <dl class="space-y-3">
                    @foreach([
                        ['Région', $electeur->departement],
                        ['Département', $electeur->arrondissement],
                        ['Commune', $electeur->commune],
                        ['Lieu de vote', $electeur->lieu_vote],
                        ['N° Bureau', substr($electeur->code_bureau ?? '', -2)],
                        ['Code bureau', $electeur->code_bureau],
                    ] as [$label, $val])
                    <div class="flex justify-between items-start gap-4 py-2 border-b border-slate-50 last:border-0">
                        <dt class="text-xs text-slate-500 flex-shrink-0 w-36">{{ $label }}</dt>
                        <dd class="text-sm font-medium text-slate-800 text-right">{{ $val ?? '—' }}</dd>
                    </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <p class="text-xs font-medium text-slate-500 mb-3">Créer une opération pour cet électeur :</p>
        <div class="flex flex-wrap gap-2">
            @php
                $ps = http_build_query([
                    'nin_demandeur'       => $electeur->numcni,
                    'nom_demandeur'       => $electeur->nom,
                    'prenom_demandeur'    => $electeur->prenom,
                    'datenaiss_demandeur' => $electeur->datenaiss,
                ]);
            @endphp
            <a href="{{ route('operations.create') }}?type=inscription&{{ $ps }}"
               class="px-4 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition"
               style="background:#009A44;">Inscription</a>
            <a href="{{ route('operations.create') }}?type=modification&{{ $ps }}"
               class="px-4 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition"
               style="background:#0284c7;">Modification</a>
            <a href="{{ route('operations.create') }}?type=changement&{{ $ps }}"
               class="px-4 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition"
               style="background:#d97706;">Changement statut</a>
            <a href="{{ route('operations.create') }}?type=radiation&{{ $ps }}"
               class="px-4 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition"
               style="background:#EE1C25;">Radiation</a>
        </div>
    </div>

    <a href="{{ route('fichier-electoral') }}"
       class="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-800 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Retour au fichier électoral
    </a>

</div>
@endsection

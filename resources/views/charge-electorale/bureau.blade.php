@extends('layouts.app')
@section('title','Bureau ' . $bureau->code_bureau)
@section('page-title','Bureau ' . $bureau->code_bureau)

@section('content')
<div class="space-y-5">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-bold text-slate-800">Bureau {{ $bureau->code_bureau }}</h2>
            <p class="text-xs text-slate-400 mt-0.5">{{ $bureau->lieu_vote }} — {{ $bureau->commune }}</p>
        </div>
        <a href="{{ route('charge-electorale') }}"
           class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium text-white hover:opacity-90 transition"
           style="background:#0284c7;">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Retour
        </a>
    </div>

    {{-- Bureau Info --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div>
                <p class="text-xs text-slate-500 mb-1">Code Bureau</p>
                <p class="font-mono text-sm font-semibold text-slate-800">{{ $bureau->code_bureau }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 mb-1">Lieu de Vote</p>
                <p class="text-sm font-medium text-slate-800">{{ $bureau->lieu_vote }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 mb-1">Commune</p>
                <p class="text-sm text-slate-700">{{ $bureau->commune }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 mb-1">Arrondissement</p>
                <p class="text-sm text-slate-700">{{ $bureau->arrondissement }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 mb-1">Effectif</p>
                <p class="text-2xl font-bold" style="color:{{ $bureau->effectif < 10 ? '#dc2626' : ($bureau->effectif > 600 ? '#f59e0b' : '#009A44') }}">
                    {{ number_format($bureau->effectif) }}
                </p>
            </div>
        </div>
    </div>

    {{-- Liste électeurs --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <strong class="font-semibold text-slate-800 text-sm">Liste des Électeurs</strong>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium text-white" style="background:#009A44;">
                {{ number_format($electeurs->count()) }} électeur(s)
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Nom</th>
                        <th class="px-5 py-3 text-left font-semibold">Prénom</th>
                        <th class="px-5 py-3 text-left font-semibold">Date Naissance</th>
                        <th class="px-5 py-3 text-left font-semibold">Lieu Naissance</th>
                        <th class="px-5 py-3 text-left font-semibold">CNI</th>
                        <th class="px-5 py-3 text-left font-semibold">Prénom Père</th>
                        <th class="px-5 py-3 text-left font-semibold">Prénom Mère</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($electeurs as $e)
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-5 py-3 font-medium text-slate-800">{{ $e->nom }}</td>
                        <td class="px-5 py-3 text-slate-700">{{ $e->prenom }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $e->datenaiss ?? '-' }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $e->lieunaiss ?? '-' }}</td>
                        <td class="px-5 py-3 font-mono text-xs text-slate-600">{{ $e->numcni ?? '-' }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $e->prenom_pere ?? '-' }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $e->prenom_mere ?? '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

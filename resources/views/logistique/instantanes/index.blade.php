@extends('layouts.app')
@section('title','Effectifs')
@section('page-title','Instantanés des effectifs')

@php
    $libellesStatut = ['en_cours' => 'Capture en cours', 'brouillon' => 'Brouillon', 'fige' => 'Figé', 'echec' => 'Échec'];
    $classesStatut = ['en_cours' => 'bg-sky-100 text-sky-700', 'brouillon' => 'bg-amber-100 text-amber-700', 'fige' => 'bg-green-100 text-green-700', 'echec' => 'bg-red-100 text-red-700'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $instantanes->count() }} instantané(s)</h3>
            @if($cycle->estOuvert())
                <form method="POST" action="{{ route('logistique.instantanes.store', $cycle) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Capturer les effectifs</button>
                </form>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Version</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-right font-semibold">Électeurs</th>
                        <th class="px-5 py-3 text-right font-semibold">Non rapprochés</th>
                        <th class="px-5 py-3 text-left font-semibold">Capturé par</th>
                        <th class="px-5 py-3 text-left font-semibold">Date</th>
                        <th class="px-5 py-3 text-left font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($instantanes as $i)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">v{{ $i->version }}</td>
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesStatut[$i->statut] ?? '' }}">{{ $libellesStatut[$i->statut] ?? $i->statut }}</span></td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ isset($i->bilan['electeurs_total']) ? number_format($i->bilan['electeurs_total'], 0, ',', ' ') : '—' }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ isset($i->bilan['electeurs_non_rapproches']) ? number_format($i->bilan['electeurs_non_rapproches'], 0, ',', ' ') : '—' }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $i->auteur?->nom_complet }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $i->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-5 py-3"><a href="{{ route('logistique.instantanes.show', $i) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Détail</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-10 text-center text-slate-400">Aucun instantané</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

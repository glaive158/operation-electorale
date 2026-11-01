@extends('layouts.app')
@section('title','Plans de dotation')
@section('page-title','Plans de dotation')

@php
    $libellesStatut = ['brouillon' => 'Brouillon', 'approuve' => 'Approuvé', 'remplace' => 'Remplacé'];
    $classesStatut = ['brouillon' => 'bg-amber-100 text-amber-700', 'approuve' => 'bg-green-100 text-green-700', 'remplace' => 'bg-slate-200 text-slate-600'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    @if($cycle->estOuvert())
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
            <form method="POST" action="{{ route('logistique.plans.store', $cycle) }}" class="flex flex-wrap gap-2 items-center">
                @csrf
                <select name="instantane_id" required aria-label="Instantané figé" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    <option value="">Instantané figé à utiliser…</option>
                    @foreach($instantanes as $i)
                        <option value="{{ $i->id }}">v{{ $i->version }} — figé le {{ $i->fige_le?->format('d/m/Y') }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Calculer un plan</button>
                @if($instantanes->isEmpty())
                    <span class="text-sm text-slate-500">Aucun instantané figé : <a href="{{ route('logistique.instantanes.index', $cycle) }}" class="underline">capturer les effectifs</a> d'abord.</span>
                @endif
            </form>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $plans->count() }} plan(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Version</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-left font-semibold">Instantané</th>
                        <th class="px-5 py-3 text-left font-semibold">Calculé par</th>
                        <th class="px-5 py-3 text-left font-semibold">Date</th>
                        <th class="px-5 py-3 text-left font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($plans as $p)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">v{{ $p->version }}</td>
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesStatut[$p->statut] ?? '' }}">{{ $libellesStatut[$p->statut] ?? $p->statut }}</span></td>
                            <td class="px-5 py-3 text-slate-600">v{{ $p->instantane?->version }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $p->calculePar?->nom_complet }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $p->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-5 py-3"><a href="{{ route('logistique.plans.show', $p) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Détail</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-slate-400">Aucun plan</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

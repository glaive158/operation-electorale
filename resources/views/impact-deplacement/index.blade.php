@extends('layouts.app')
@section('title','Impact des déplacements de bureaux')
@section('page-title','Impact Déplacement Bureaux')

@section('content')
<div class="space-y-5">

    <div>
        <h2 class="text-lg font-bold text-slate-800">Impact des déplacements de bureaux sur les électeurs</h2>
        <p class="text-xs text-slate-400 mt-0.5">Comparaison ancien fichier (DB) vs nouveau fichier (CSV) — calculé le {{ $stats['computed_at'] }}</p>
    </div>

    {{-- VERDICT FINAL --}}
    <div class="rounded-2xl p-5 border-2" style="background:#dcfce7; border-color:#16a34a;">
        <div class="flex items-start gap-3">
            <svg class="w-8 h-8 flex-shrink-0" style="color:#16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <div>
                <h3 class="font-bold text-lg" style="color:#16a34a;">VERDICT: Aucun déplacement réel de bureau</h3>
                <p class="text-sm text-slate-700 mt-2">
                    Après analyse croisée (suivi électeurs, département identique, effectif similaire) :
                </p>
                <ul class="text-sm text-slate-700 mt-2 ml-4 list-disc space-y-1">
                    <li><strong>0 bureau</strong> réellement déplacé entre l'ancien et le nouveau fichier</li>
                    <li><strong>0 électeur impacté</strong> par un déplacement de bureau</li>
                    <li><strong>553 communes</strong> identiques dans les 2 fichiers (aucune création/fusion/suppression)</li>
                    <li><strong>Aucune réorganisation administrative</strong> détectée</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Stats globales electeurs --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="bg-white rounded-xl p-5 border border-slate-200">
            <p class="text-xs uppercase font-bold tracking-wider text-slate-500">Communes comparées</p>
            <p class="text-3xl font-bold text-slate-800 mt-1">553</p>
            <p class="text-xs text-slate-400">identiques dans les 2 fichiers</p>
        </div>
        <div class="bg-white rounded-xl p-5 border border-slate-200">
            <p class="text-xs uppercase font-bold tracking-wider text-slate-500">Électeurs ancien fichier</p>
            <p class="text-3xl font-bold text-slate-800 mt-1">7 033 990</p>
            <p class="text-xs text-slate-400">national</p>
        </div>
        <div class="bg-white rounded-xl p-5 border border-slate-200">
            <p class="text-xs uppercase font-bold tracking-wider text-slate-500">Électeurs déplacés involontairement</p>
            <p class="text-3xl font-bold mt-1" style="color:#16a34a;">0</p>
            <p class="text-xs text-slate-400">aucun bureau n'a entraîné d'électeurs</p>
        </div>
    </div>

</div>
@endsection

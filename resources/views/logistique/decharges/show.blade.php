@extends('layouts.app')
@section('title','Décharge '.($decharge->numero ?? 'brouillon'))
@section('page-title','Décharge '.($decharge->numero ?? 'brouillon'))

@php
    $libellesDocument = ['brouillon' => 'Brouillon', 'validee' => 'Validée', 'annulee' => 'Annulée'];
    $classesDocument = ['brouillon' => 'bg-amber-100 text-amber-700', 'validee' => 'bg-green-100 text-green-700', 'annulee' => 'bg-red-100 text-red-700'];
@endphp

@section('content')
<div class="space-y-5">

    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="font-semibold text-slate-800">{{ $decharge->numero ?? 'Brouillon sans numéro' }}</h3>
                <p class="text-sm text-slate-600">{{ $decharge->destinataire?->libelle }} — {{ \App\Models\Logistique\Decharge::CATEGORIES[$decharge->categorie] ?? $decharge->categorie }}</p>
                <p class="text-xs text-slate-500">{{ $decharge->cycle?->libelle }}</p>
            </div>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesDocument[$decharge->statut_document] ?? 'bg-slate-200 text-slate-600' }}">
                {{ $libellesDocument[$decharge->statut_document] ?? $decharge->statut_document }}
            </span>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div><dt class="text-xs text-slate-500">Document</dt><dd class="text-slate-800">{{ $libellesDocument[$decharge->statut_document] ?? $decharge->statut_document }}</dd></div>
            <div><dt class="text-xs text-slate-500">Acheminement</dt><dd class="text-slate-800">{{ $decharge->statut_acheminement }}</dd></div>
            <div><dt class="text-xs text-slate-500">Réclamation</dt><dd class="text-slate-800">{{ $decharge->statut_reclamation }}</dd></div>
            <div><dt class="text-xs text-slate-500">Destinataire</dt><dd class="text-slate-800">{{ $decharge->destinataire?->libelle }}</dd></div>
            @if($decharge->remisA)
                <div><dt class="text-xs text-slate-500">Remis à</dt><dd class="text-slate-800">{{ $decharge->remisA->libelle }}</dd></div>
            @endif
        </dl>
    </div>
</div>
@endsection

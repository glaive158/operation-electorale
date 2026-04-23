@extends('layouts.app')
@section('title','Demande #'.$operation->id)
@section('page-title','Détail de la demande')

@section('content')
<div class="max-w-4xl mx-auto space-y-5">

    {{-- Header --}}
    @php
        $tc = ['inscription'=>'#009A44','modification'=>'#ca8a04','changement'=>'#0284c7','radiation'=>'#EE1C25'][$operation->type] ?? '#666';
        $sc = ['en_attente'=>'#d97706','validee'=>'#009A44','rejetee'=>'#EE1C25'][$operation->statut] ?? '#666';
    @endphp
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 flex items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2 flex-wrap">
                <span class="px-3 py-1 rounded-full text-sm font-semibold text-white" style="background:{{ $tc }};">{{ $operation->type_label }}</span>
                <span class="px-3 py-1 rounded-full text-sm font-semibold text-white" style="background:{{ $sc }};">{{ $operation->statut_label }}</span>
                @if($operation->documents_complets)
                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">✓ Documents complets</span>
                @else
                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">⚠ Documents manquants</span>
                @endif
                @if($operation->militaire)
                    <span class="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-600">Militaire</span>
                @endif
                @if($operation->handicap)
                    <span class="px-2 py-0.5 rounded text-xs bg-purple-100 text-purple-700">Handicap</span>
                @endif
            </div>
            <p class="text-xs text-slate-400">Demande #{{ $operation->id }} — enregistrée le {{ $operation->created_at->format('d/m/Y à H:i') }}</p>
            <p class="text-xs text-slate-400">Par : {{ $operation->user?->nom_complet ?? '—' }}</p>
        </div>
        <div class="flex gap-2 flex-shrink-0 flex-wrap justify-end">
            <a href="{{ route('operations.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">← Retour</a>
            <a href="{{ route('operations.imprimer', $operation) }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">🖨 Imprimer</a>
            @if(!$operation->documents_complets)
                <a href="{{ route('operations.scanner', $operation) }}"
                   class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#d97706;">
                    📎 Scanner les documents
                </a>
            @endif
            @if($operation->statut === 'en_attente' && auth()->user()->canValidate())
                <form method="POST" action="{{ route('operations.valider', $operation) }}" class="inline">
                    @csrf @method('PATCH')
                    <button class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Valider</button>
                </form>
                <button onclick="document.getElementById('rejeter_modal').classList.remove('hidden')"
                        class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">
                    Rejeter
                </button>
            @endif
        </div>
    </div>

    {{-- Demandeur --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <h3 class="font-semibold text-slate-800 mb-4">Demandeur</h3>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            @php
                $fields = [
                    'NIN'               => $operation->nin_demandeur,
                    'Nom'               => $operation->nom_demandeur,
                    'Prénom'            => $operation->prenom_demandeur,
                    'Date de naissance' => $operation->datenaiss_demandeur?->format('d/m/Y'),
                    'Lieu de naissance' => $operation->lieunaiss_demandeur,
                    'Téléphone'         => $operation->tel_demandeur,
                    'Adresse'           => $operation->adresse_demandeur,
                ];
            @endphp
            @foreach($fields as $label => $val)
                <div>
                    <p class="text-xs text-slate-500">{{ $label }}</p>
                    <p class="font-medium text-slate-800 text-sm">{{ $val ?? '—' }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Type-specific section --}}
    @if(in_array($operation->type, ['inscription','modification']))
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
            <h3 class="font-semibold text-slate-800 mb-4">Informations électorales</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div><p class="text-xs text-slate-500">Commune</p><p class="font-medium text-slate-800 text-sm">{{ $operation->commune_nom ?? '—' }}</p></div>
                <div><p class="text-xs text-slate-500">Département</p><p class="font-medium text-slate-800 text-sm">{{ $operation->departement_nom ?? '—' }}</p></div>
                <div><p class="text-xs text-slate-500">Adresse électorale</p><p class="font-medium text-slate-800 text-sm">{{ $operation->adresse_electorale ?? '—' }}</p></div>
            </div>
        </div>
    @endif

    @if($operation->type === 'radiation')
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
            <h3 class="font-semibold text-slate-800 mb-4" style="color:#EE1C25;">Électeur à radier</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div><p class="text-xs text-slate-500">NIN</p><p class="font-medium text-slate-800 text-sm font-mono">{{ $operation->nin_electeur_radie ?? '—' }}</p></div>
                <div><p class="text-xs text-slate-500">Nom</p><p class="font-medium text-slate-800 text-sm">{{ $operation->nom_electeur_radie ?? '—' }}</p></div>
                <div><p class="text-xs text-slate-500">Prénom</p><p class="font-medium text-slate-800 text-sm">{{ $operation->prenom_electeur_radie ?? '—' }}</p></div>
                <div><p class="text-xs text-slate-500">N° Électeur</p><p class="font-medium text-slate-800 text-sm font-mono">{{ $operation->numelec_electeur_radie ?? '—' }}</p></div>
                <div class="md:col-span-4">
                    <p class="text-xs text-slate-500">Motif</p>
                    <p class="font-medium text-slate-800 text-sm">
                        {{ ['deces'=>'Décès','incapacite_juridique'=>'Incapacité juridique','demande_interessee'=>'Demande intéressée'][$operation->motif_radiation] ?? '—' }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if($operation->type === 'changement')
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
            <h3 class="font-semibold text-slate-800 mb-4" style="color:#d97706;">Changement de statut</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-xs text-slate-500">Sens du changement</p>
                    <p class="font-medium text-slate-800 text-sm">
                        {{ $operation->statut_changement === 'civil_vers_militaire' ? 'Civil → Militaire' : 'Militaire → Civil' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Avec modification</p>
                    <p class="font-medium text-slate-800 text-sm">{{ $operation->avec_modification ? 'Oui' : 'Non' }}</p>
                </div>
                @if($operation->avec_modification)
                    <div><p class="text-xs text-slate-500">Nouvelle commune</p><p class="font-medium text-slate-800 text-sm">{{ $operation->commune_nom ?? '—' }}</p></div>
                    <div><p class="text-xs text-slate-500">Département</p><p class="font-medium text-slate-800 text-sm">{{ $operation->departement_nom ?? '—' }}</p></div>
                @endif
            </div>
        </div>
    @endif

    {{-- Documents --}}
    @if($operation->documents->isNotEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
            <h3 class="font-semibold text-slate-800 mb-4">Documents joints</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                @foreach($operation->documents as $doc)
                    <div class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50">
                        <svg class="w-8 h-8 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        <div class="overflow-hidden">
                            <p class="text-xs font-medium text-slate-700 truncate">
                                {{ \App\Models\OperationDocument::$typeLabels[$doc->type_document] ?? $doc->type_document }}
                            </p>
                            <p class="text-xs text-slate-400 truncate">{{ $doc->nom_original }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Commentaire --}}
    @if($operation->commentaire)
        <div class="bg-amber-50 rounded-2xl border border-amber-200 p-5">
            <p class="text-xs font-semibold text-amber-700 uppercase tracking-wide mb-1">Commentaire</p>
            <p class="text-sm text-amber-900">{{ $operation->commentaire }}</p>
        </div>
    @endif

</div>

{{-- Rejection modal --}}
<div id="rejeter_modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-md">
        <h3 class="font-semibold text-slate-800 mb-4">Motif de rejet</h3>
        <form method="POST" action="{{ route('operations.rejeter', $operation) }}">
            @csrf @method('PATCH')
            <textarea name="commentaire" rows="4" required
                      class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 resize-none mb-4"
                      placeholder="Précisez le motif du rejet…"></textarea>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="document.getElementById('rejeter_modal').classList.add('hidden')"
                        class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">
                    Annuler
                </button>
                <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">
                    Confirmer le rejet
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

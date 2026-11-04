@extends('layouts.app')
@section('title','Réclamation')
@section('page-title','Réclamation sur la décharge '.($decharge->numero ?? ''))

@php
    use App\Models\Logistique\Reclamation;
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $classes = ['ouverte' => 'bg-red-100 text-red-700', 'en_traitement' => 'bg-amber-100 text-amber-700', 'resolue' => 'bg-green-100 text-green-700'];
    $responsable = \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    // Autoriser un remplacement et clôturer sans remplacement relèvent du responsable ; le service le contrôle aussi.
    $issues = collect(Reclamation::ISSUES)->reject(fn ($libelle, $cle) => ! $responsable && in_array($cle, ['remplacement_autorise', 'cloture_sans_remplacement'], true));
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-800">{{ $reclamation->materiel?->libelle }} — {{ Reclamation::MOTIFS[$reclamation->motif] ?? $reclamation->motif }}, {{ $n($reclamation->quantite) }}</h3>
                <p class="text-sm text-slate-600">
                    <a href="{{ route('logistique.decharges.show', $decharge) }}" class="underline">{{ $decharge->numero }}</a>
                    — {{ $decharge->destinataire?->libelle }}
                </p>
            </div>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classes[$reclamation->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ Reclamation::STATUTS[$reclamation->statut] ?? $reclamation->statut }}</span>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div><dt class="text-xs text-slate-500">Ouverte par</dt><dd class="text-slate-800">{{ $reclamation->ouvertePar?->nom_complet }} le {{ $reclamation->created_at?->format('d/m/Y H:i') }}</dd></div>
            @if($reclamation->traiteePar)
                <div><dt class="text-xs text-slate-500">Traitée par</dt><dd class="text-slate-800">{{ $reclamation->traiteePar->nom_complet }}</dd></div>
            @endif
            @if($reclamation->issue)
                <div><dt class="text-xs text-slate-500">Issue</dt><dd class="text-slate-800">{{ Reclamation::ISSUES[$reclamation->issue] ?? $reclamation->issue }}</dd></div>
            @endif
            @if($reclamation->resolue_le)
                <div><dt class="text-xs text-slate-500">Résolue le</dt><dd class="text-slate-800">{{ $reclamation->resolue_le->format('d/m/Y H:i') }}</dd></div>
            @endif
        </dl>

        <div>
            <p class="text-xs text-slate-500">Message de l'autorité</p>
            <p class="text-sm text-slate-800 whitespace-pre-line">{{ $reclamation->message }}</p>
        </div>
        @if($reclamation->motif_cloture)
            <div>
                <p class="text-xs text-slate-500">Motif de la résolution</p>
                <p class="text-sm text-slate-800 whitespace-pre-line">{{ $reclamation->motif_cloture }}</p>
            </div>
        @endif
        @if($besoin)
            <p class="text-sm text-slate-600">
                Remplacement autorisé : {{ $n($besoin->quantite) }}.
                @foreach($remplacements as $remplacement)
                    <a href="{{ route('logistique.decharges.show', $remplacement) }}" class="underline font-medium">{{ $remplacement->numero ?? 'Brouillon n° '.$remplacement->id }}</a>@if(! $loop->last), @endif
                @endforeach
            </p>
        @endif
    </div>

    @if($reclamation->statut === 'ouverte')
        <form method="POST" action="{{ route('logistique.reclamations.prendre', $reclamation) }}" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 flex flex-wrap items-center gap-3">
            @csrf
            <p class="text-sm text-slate-600">Prenez la réclamation en charge pour signaler qu'elle est en cours de traitement.</p>
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#0284c7;">Prendre en charge</button>
        </form>
    @endif

    @if($reclamation->statut !== 'resolue')
        <form method="POST" action="{{ route('logistique.reclamations.resoudre', $reclamation) }}" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-3">
            @csrf
            <h3 class="font-semibold text-slate-800">Résoudre</h3>
            <div class="flex flex-wrap gap-3 items-start">
                <select name="issue" required aria-label="Issue" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    <option value="">Issue…</option>
                    @foreach($issues as $valeur => $libelle)
                        <option value="{{ $valeur }}" @selected(old('issue') === $valeur)>{{ $libelle }}</option>
                    @endforeach
                </select>
                <input type="number" name="quantite" min="1" max="{{ $reclamation->quantite }}" step="1" value="{{ old('quantite') }}" placeholder="Quantité à remplacer" aria-label="Quantité à remplacer"
                       class="w-44 px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <select name="declaration_id" aria-label="Déclaration erronée" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    <option value="">Déclaration erronée…</option>
                    @foreach($declarations as $declaration)
                        <option value="{{ $declaration->id }}" @selected((int) old('declaration_id') === $declaration->id)>
                            n° {{ $declaration->id }} — {{ $declaration->mode }}, {{ \App\Models\Logistique\Declaration::STATUTS[$declaration->statut] ?? $declaration->statut }}, {{ $declaration->signataire_nom }}
                        </option>
                    @endforeach
                </select>
            </div>
            <p class="text-xs text-slate-500">La quantité ne sert qu'au remplacement autorisé ; la déclaration erronée, qu'à l'issue « Erreur de déclaration ».</p>
            <div class="flex flex-wrap gap-3 items-center">
                <input type="text" name="motif" required maxlength="500" value="{{ old('motif') }}" placeholder="Motif de la résolution" aria-label="Motif de la résolution"
                       class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none w-full md:w-96">
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Résoudre</button>
            </div>
        </form>
    @endif
</div>
@endsection

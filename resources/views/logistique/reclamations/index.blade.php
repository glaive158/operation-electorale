@extends('layouts.app')
@section('title','Réclamations')
@section('page-title','Réclamations')

@php
    use App\Models\Logistique\Reclamation;
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $classes = ['ouverte' => 'bg-red-100 text-red-700', 'en_traitement' => 'bg-amber-100 text-amber-700', 'resolue' => 'bg-green-100 text-green-700'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="flex flex-wrap gap-3 items-center">
            <select name="statut" aria-label="Statut" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les statuts</option>
                @foreach(Reclamation::STATUTS as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($statut === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select name="motif" aria-label="Motif" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les motifs</option>
                @foreach(Reclamation::MOTIFS as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($motif === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#0284c7;">Filtrer</button>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $reclamations->total() }} réclamation(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Décharge</th>
                        <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        <th class="px-5 py-3 text-left font-semibold">Motif</th>
                        <th class="px-5 py-3 text-right font-semibold">Quantité</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-left font-semibold">Ouverte le</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($reclamations as $r)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $r->decharge?->numero }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $r->decharge?->destinataire?->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $r->materiel?->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ Reclamation::MOTIFS[$r->motif] ?? $r->motif }}</td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $n($r->quantite) }}</td>
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classes[$r->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ Reclamation::STATUTS[$r->statut] ?? $r->statut }}</span></td>
                            <td class="px-5 py-3 text-slate-600">{{ $r->created_at?->format('d/m/Y') }}</td>
                            <td class="px-5 py-3"><a href="{{ route('logistique.reclamations.show', $r) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Ouvrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-slate-400">Aucune réclamation</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($reclamations->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $reclamations->links() }}</div>
        @endif
    </div>
</div>
@endsection

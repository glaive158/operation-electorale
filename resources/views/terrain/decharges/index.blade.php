@extends('layouts.app')
@section('title', auth()->user()->role === 'gouverneur' ? 'Décharges de la région' : 'Mes décharges')
@section('page-title', auth()->user()->role === 'gouverneur' ? 'Décharges de la région' : 'Mes décharges')

@php
    use App\Models\Logistique\Decharge;
    $libellesDocument = ['validee' => 'Validée', 'annulee' => 'Annulée'];
    $classesDocument = ['validee' => 'bg-green-100 text-green-700', 'annulee' => 'bg-red-100 text-red-700'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $decharges->total() }} décharge(s)</h3>
            <p class="text-xs text-slate-500">Les décharges validées ou annulées de votre périmètre. Le QR code imprimé sur le document ouvre directement la fiche.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">N°</th>
                        <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                        <th class="px-5 py-3 text-left font-semibold">Cycle</th>
                        <th class="px-5 py-3 text-left font-semibold">Catégorie</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-left font-semibold">Acheminement</th>
                        <th class="px-5 py-3 text-left font-semibold">Réclamation</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($decharges as $d)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $d->numero }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $d->destinataire?->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $d->cycle?->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ Decharge::CATEGORIES[$d->categorie] ?? $d->categorie }}</td>
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classesDocument[$d->statut_document] ?? '' }}">{{ $libellesDocument[$d->statut_document] ?? $d->statut_document }}</span></td>
                            <td class="px-5 py-3 text-slate-600">{{ Decharge::ACHEMINEMENTS[$d->statut_acheminement] ?? $d->statut_acheminement }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ Decharge::RECLAMATIONS[$d->statut_reclamation] ?? $d->statut_reclamation }}</td>
                            <td class="px-5 py-3"><a href="{{ route('terrain.decharges.show', $d) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Ouvrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-slate-400">Aucune décharge dans votre périmètre.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($decharges->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $decharges->links() }}</div>
        @endif
    </div>
</div>
@endsection

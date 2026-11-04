@extends('layouts.app')
@section('title','Écarts')
@section('page-title','Écarts avec le plan de dotation en vigueur')

@php
    $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $peutPreparer = $cycle->estOuvert();
    $peutAnnuler = $cycle->estOuvert() && \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    $libelles = ['reliquat' => 'Reliquat à expédier', 'annulation' => 'Excédent à annuler avant départ', 'signalement' => 'Excédent déjà parti'];
    $classes = ['reliquat' => 'bg-sky-100 text-sky-700', 'annulation' => 'bg-amber-100 text-amber-700', 'signalement' => 'bg-red-100 text-red-700'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $ecarts->count() }} écart(s)</h3>
            <p class="text-xs text-slate-500">Différence entre ce que les décharges validées ont engagé et le besoin d'expédition (dotation du plan approuvé courant, remplacements et clôtures compris).</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        <th class="px-5 py-3 text-right font-semibold">Dotation</th>
                        <th class="px-5 py-3 text-right font-semibold">Besoin</th>
                        <th class="px-5 py-3 text-right font-semibold">Engagé</th>
                        <th class="px-5 py-3 text-left font-semibold">Écart</th>
                        <th class="px-5 py-3 text-right font-semibold">Quantité</th>
                        <th class="px-5 py-3 text-left font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($ecarts as $e)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $e['destinataire']->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $e['materiel']->libelle }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($e['dotation']) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($e['besoin']) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($e['engage']) }}</td>
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classes[$e['type']] ?? '' }}">{{ $libelles[$e['type']] ?? $e['type'] }}</span></td>
                            <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ $n($e['quantite']) }}</td>
                            <td class="px-5 py-3">
                                @if($e['type'] === 'reliquat')
                                    @if($peutPreparer)
                                        <form method="POST" action="{{ route('logistique.decharges.store', $cycle) }}">
                                            @csrf
                                            <input type="hidden" name="destinataire_id" value="{{ $e['destinataire']->id }}">
                                            <input type="hidden" name="categorie" value="{{ $e['categorie'] }}">
                                            <button type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#009A44;">Préparer le reliquat</button>
                                        </form>
                                    @endif
                                @elseif($e['type'] === 'annulation')
                                    @if($peutAnnuler)
                                        @foreach($e['decharges'] as $id)
                                            <a href="{{ route('logistique.decharges.show', $id) }}" class="inline-block px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#d97706;">Annuler et remplacer (décharge {{ $id }})</a>
                                        @endforeach
                                    @else
                                        <span class="text-xs text-slate-500">À annuler par un responsable logistique</span>
                                    @endif
                                @else
                                    <span class="text-xs text-slate-500">Signalé : matériel déjà parti</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-slate-400">Aucun écart : les décharges validées correspondent au plan en vigueur.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

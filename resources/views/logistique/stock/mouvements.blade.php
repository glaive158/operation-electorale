@extends('layouts.app')
@section('title', 'Mouvements — '.$materiel->libelle)
@section('page-title', 'Mouvements de stock')

@php
    $types = [
        'inventaire_initial' => 'Inventaire initial',
        'entree_fournisseur' => 'Entrée fournisseur',
        'sortie_expedition'  => 'Sortie expédition',
        'reintegration'      => 'Réintégration',
        'reclassement'       => 'Reclassement',
        'reforme'            => 'Réforme',
        'compensation'       => 'Compensation',
    ];
@endphp

@section('content')
<div class="space-y-5">

    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="font-semibold text-slate-800">{{ $materiel->libelle }}</h3>
                <p class="text-xs text-slate-500">{{ \App\Models\Logistique\Materiel::CATEGORIES[$materiel->categorie] ?? $materiel->categorie }}</p>
            </div>
            <a href="{{ route('logistique.stock.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">Retour au stock</a>
        </div>
        <dl class="grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
            <div><dt class="text-xs text-slate-500">Physique</dt><dd class="font-semibold text-slate-800">{{ $indicateurs['physique'] }}</dd></div>
            <div><dt class="text-xs text-slate-500">Réservé</dt><dd class="font-semibold text-slate-800">{{ $indicateurs['reserve'] }}</dd></div>
            <div><dt class="text-xs text-slate-500">Attribué</dt><dd class="font-semibold text-slate-800">{{ $indicateurs['attribue'] }}</dd></div>
            <div><dt class="text-xs text-slate-500">Libre non attribué</dt><dd class="font-semibold text-slate-800">{{ $indicateurs['libre_non_attribue'] }}</dd></div>
            <div><dt class="text-xs text-slate-500">À réparer</dt><dd class="font-semibold text-slate-800">{{ $indicateurs['a_reparer'] }}</dd></div>
        </dl>
    </div>

    @can('logistique-administrer')
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Attributions par cycle ouvert</h3>
            <p class="text-xs text-slate-500">La quantité saisie remplace l'attribution actuelle du cycle pour cet article.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Cycle</th>
                        <th class="px-5 py-3 text-right font-semibold">Attribué</th>
                        <th class="px-5 py-3 text-right font-semibold">Réservé</th>
                        <th class="px-5 py-3 text-left font-semibold">Nouvelle attribution</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($cycles as $c)
                        <tr>
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $c['cycle']->libelle }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $c['attribue'] }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $c['reserve'] }}</td>
                            <td class="px-5 py-3">
                                <form method="POST" action="{{ route('logistique.attributions.update', [$c['cycle'], $materiel]) }}" class="flex flex-wrap gap-2 items-center">
                                    @csrf @method('PUT')
                                    <input type="number" name="quantite" min="0" step="1" required value="{{ $c['attribue'] }}" aria-label="Quantité attribuée"
                                           class="w-28 px-3 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif"
                                           class="w-56 px-3 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-xs font-medium hover:opacity-90" style="background:#009A44;">Attribuer</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-slate-400">Aucun cycle ouvert</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endcan

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Grand livre</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Date</th>
                        <th class="px-5 py-3 text-left font-semibold">Type</th>
                        <th class="px-5 py-3 text-left font-semibold">État</th>
                        <th class="px-5 py-3 text-right font-semibold">Quantité</th>
                        <th class="px-5 py-3 text-left font-semibold">Motif</th>
                        @can('logistique-administrer')
                            <th class="px-5 py-3 text-left font-semibold">Correction</th>
                        @endcan
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($mouvements as $mv)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-slate-600">{{ $mv->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-5 py-3 text-slate-800">{{ $types[$mv->type] ?? $mv->type }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $mv->etat === 'a_reparer' ? 'À réparer' : 'Bon' }}</td>
                            <td class="px-5 py-3 text-right font-semibold {{ $mv->quantite < 0 ? 'text-red-600' : 'text-slate-800' }}">{{ $mv->quantite > 0 ? '+' : '' }}{{ $mv->quantite }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $mv->motif ?? '—' }}</td>
                            @can('logistique-administrer')
                                <td class="px-5 py-3">
                                    @if($mv->type === 'compensation')
                                        <span class="text-xs text-slate-400">Compensation de #{{ $mv->mouvement_compense_id }}</span>
                                    @elseif(in_array($mv->id, $compenses, true))
                                        <span class="text-xs text-slate-400">Compensé</span>
                                    @else
                                        <form method="POST" action="{{ route('logistique.mouvements.compenser', $mv) }}" class="flex gap-2 items-center"
                                              onsubmit="return confirm('Compenser ce mouvement ?');">
                                            @csrf
                                            <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif de la compensation"
                                                   class="w-44 px-2 py-1 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-green-500">
                                            <button type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#EE1C25;">Compenser</button>
                                        </form>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-slate-400">Aucun mouvement</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($mouvements->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $mouvements->links() }}</div>
        @endif
    </div>
</div>
@endsection

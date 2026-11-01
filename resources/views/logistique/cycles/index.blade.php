@extends('layouts.app')
@section('title','Cycles électoraux')
@section('page-title','Cycles électoraux')

@section('content')
<div class="space-y-5">
    @include('logistique._erreurs')
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $cycles->count() }} cycle(s)</h3>
            @can('logistique-administrer')
                <a href="{{ route('logistique.cycles.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Nouveau cycle</a>
            @endcan
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Libellé</th>
                        <th class="px-5 py-3 text-left font-semibold">Type</th>
                        <th class="px-5 py-3 text-left font-semibold">Date du scrutin</th>
                        <th class="px-5 py-3 text-right font-semibold">Majoration</th>
                        <th class="px-5 py-3 text-right font-semibold">Réserve centrale</th>
                        <th class="px-5 py-3 text-left font-semibold">Circuit</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        @can('logistique-administrer')
                            <th class="px-5 py-3 text-left font-semibold">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($cycles as $c)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $c->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $c->type === 'scrutin' ? 'Scrutin' : 'Révision' }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $c->date_scrutin?->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ rtrim(rtrim($c->majoration_distribuee, '0'), '.') }} %</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ rtrim(rtrim($c->reserve_centrale, '0'), '.') ?: '0' }} %</td>
                            <td class="px-5 py-3 text-slate-600">{{ $c->circuit_defaut === 'direct' ? 'Direct' : 'Via le préfet' }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $c->estOuvert() ? 'bg-green-100 text-green-700' : 'bg-slate-200 text-slate-600' }}">
                                    {{ $c->estOuvert() ? 'Ouvert' : 'Clos' }}
                                </span>
                            </td>
                            @can('logistique-administrer')
                                <td class="px-5 py-3">
                                    @if($c->estOuvert())
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <a href="{{ route('logistique.cycles.edit', $c) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Modifier</a>
                                            <form method="POST" action="{{ route('logistique.cycles.cloturer', $c) }}" class="flex gap-2 items-center"
                                                  onsubmit="return confirm('Clôturer ce cycle ? Ses réservations et attributions retournent au stock commun.');">
                                                @csrf
                                                <input type="text" name="motif" required maxlength="500" placeholder="Motif de la clôture" aria-label="Motif de la clôture"
                                                       class="w-48 px-2 py-1 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-green-500">
                                                <button type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#EE1C25;">Clôturer</button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-400">Clôturé{{ $c->clos_le ? ' le '.$c->clos_le->format('d/m/Y') : '' }}</span>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-slate-400">Aucun cycle</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

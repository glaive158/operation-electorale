@extends('layouts.app')
@section('title','Commandes fournisseur')
@section('page-title','Commandes fournisseur')

@section('content')
<div class="space-y-5">

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-center">
            <select name="statut" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les statuts</option>
                @foreach(\App\Models\Logistique\Commande::LIBELLES_STATUT as $v => $l)
                    <option value="{{ $v }}" {{ request('statut') === $v ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
            <div class="flex gap-2 md:col-start-3">
                <button class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex-1" style="background:#009A44;">Filtrer</button>
                <a href="{{ route('logistique.commandes.index') }}" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">↺</a>
            </div>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $commandes->total() }} commande(s)</h3>
            <a href="{{ route('logistique.commandes.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Nouvelle commande</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Référence</th>
                        <th class="px-5 py-3 text-left font-semibold">Fournisseur</th>
                        <th class="px-5 py-3 text-left font-semibold">Date</th>
                        <th class="px-5 py-3 text-right font-semibold">Lignes</th>
                        <th class="px-5 py-3 text-right font-semibold">Reste à recevoir</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-left font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($commandes as $c)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $c->reference }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $c->fournisseur }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $c->date_commande?->format('d/m/Y') }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $c->lignes->count() }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ in_array($c->statut, ['confirmee', 'partiellement_recue'], true) ? $c->lignes->sum(fn ($l) => $l->resteARecevoir()) : '—' }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                    {{ match($c->statut) {
                                        'confirmee', 'partiellement_recue' => 'bg-sky-100 text-sky-700',
                                        'recue' => 'bg-green-100 text-green-700',
                                        'annulee' => 'bg-red-100 text-red-700',
                                        default => 'bg-slate-200 text-slate-600',
                                    } }}">
                                    {{ \App\Models\Logistique\Commande::LIBELLES_STATUT[$c->statut] ?? $c->statut }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <a href="{{ route('logistique.commandes.show', $c) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Détail</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-10 text-center text-slate-400">Aucune commande</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($commandes->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $commandes->links() }}</div>
        @endif
    </div>
</div>
@endsection

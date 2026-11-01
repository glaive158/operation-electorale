@extends('layouts.app')
@section('title', 'Stock central')
@section('page-title', 'Stock central')

@section('content')
<div class="space-y-5">

    @include('logistique._erreurs')

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-center">
            <select name="categorie" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Toutes les catégories</option>
                @foreach(\App\Models\Logistique\Materiel::CATEGORIES as $v => $l)
                    <option value="{{ $v }}" {{ request('categorie') === $v ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
            <div class="flex gap-2 md:col-start-3">
                <button class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex-1" style="background:#009A44;">Filtrer</button>
                <a href="{{ route('logistique.stock.index') }}" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">↺</a>
            </div>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $lignes->count() }} article(s)</h3>
            <a href="{{ route('logistique.commandes.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">Commandes</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        <th class="px-5 py-3 text-left font-semibold">Catégorie</th>
                        <th class="px-5 py-3 text-right font-semibold">Physique</th>
                        <th class="px-5 py-3 text-right font-semibold">Réservé</th>
                        <th class="px-5 py-3 text-right font-semibold">Attribué</th>
                        <th class="px-5 py-3 text-right font-semibold">Libre non attribué</th>
                        <th class="px-5 py-3 text-right font-semibold">À réparer</th>
                        <th class="px-5 py-3 text-right font-semibold">Commandé à recevoir</th>
                        <th class="px-5 py-3 text-left font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($lignes as $l)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $l['materiel']->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ \App\Models\Logistique\Materiel::CATEGORIES[$l['materiel']->categorie] ?? $l['materiel']->categorie }}</td>
                            <td class="px-5 py-3 text-right text-slate-800 font-semibold">{{ $l['physique'] }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l['reserve'] }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l['attribue'] }}</td>
                            <td class="px-5 py-3 text-right {{ $l['libre_non_attribue'] < 0 ? 'text-red-600 font-semibold' : 'text-slate-600' }}">{{ $l['libre_non_attribue'] }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l['a_reparer'] }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $l['commande'] }}</td>
                            <td class="px-5 py-3">
                                <a href="{{ route('logistique.stock.mouvements', $l['materiel']) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Mouvements</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-6 py-10 text-center text-slate-400">Aucun article</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <h3 class="font-semibold text-slate-800 mb-1">Inventaire initial</h3>
        <p class="text-xs text-slate-500 mb-4">Entre en stock la quantité comptée pour un article. Une erreur se corrige par une compensation, jamais par une modification.</p>
        <form method="POST" action="{{ route('logistique.stock.inventaire') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            @csrf
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Article <span class="text-red-500">*</span></label>
                <select name="materiel_id" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    <option value="">Choisir un article</option>
                    @foreach($actifs as $m)
                        <option value="{{ $m->id }}" {{ (string) old('materiel_id') === (string) $m->id ? 'selected' : '' }}>{{ $m->libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Quantité <span class="text-red-500">*</span></label>
                <input type="number" name="quantite" min="1" step="1" required value="{{ old('quantite') }}"
                       class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Motif <span class="text-red-500">*</span></label>
                <input type="text" name="motif" required maxlength="500" value="{{ old('motif') }}"
                       class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <button type="submit" class="px-6 py-2.5 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer</button>
        </form>
    </div>
</div>
@endsection

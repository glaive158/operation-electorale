@extends('layouts.app')
@section('title','Catalogue des articles')
@section('page-title','Catalogue des articles')

@section('content')
<div class="space-y-5">

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-center">
            <select name="categorie" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Toutes les catégories</option>
                @foreach(\App\Models\Logistique\Materiel::CATEGORIES as $v => $l)
                    <option value="{{ $v }}" {{ request('categorie') === $v ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="inactifs" value="1" {{ request()->boolean('inactifs') ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;">
                Afficher aussi les articles désactivés
            </label>
            <div class="flex gap-2">
                <button class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex-1" style="background:#009A44;">Filtrer</button>
                <a href="{{ route('logistique.materiels.index') }}" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">↺</a>
            </div>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $materiels->count() }} article(s)</h3>
            <a href="{{ route('logistique.materiels.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Nouvel article</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Libellé</th>
                        <th class="px-5 py-3 text-left font-semibold">Catégorie</th>
                        <th class="px-5 py-3 text-left font-semibold">Regroupement</th>
                        <th class="px-5 py-3 text-left font-semibold">Unité de stock</th>
                        <th class="px-5 py-3 text-left font-semibold">Distribution</th>
                        <th class="px-5 py-3 text-left font-semibold">Réutilisable</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-left font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($materiels as $m)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $m->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ \App\Models\Logistique\Materiel::CATEGORIES[$m->categorie] ?? $m->categorie }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $m->regroupement ?? '—' }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $m->unite_stock }}</td>
                            <td class="px-5 py-3 text-slate-600">
                                @if($m->unite_distribution)
                                    {{ $m->unite_distribution }} de {{ $m->facteur_conditionnement }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $m->reutilisable ? 'Oui' : 'Non' }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $m->actif ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $m->actif ? 'Actif' : 'Désactivé' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <a href="{{ route('logistique.materiels.edit', $m) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Modifier</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-slate-400">Aucun article</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
